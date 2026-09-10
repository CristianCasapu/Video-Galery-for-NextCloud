<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Controller;

use OCA\VideoGallery\AppInfo\Application;
use OCA\VideoGallery\Db\ItemMapper;
use OCA\VideoGallery\Db\Session;
use OCA\VideoGallery\Db\SessionMapper;
use OCA\VideoGallery\Service\Config;
use OCA\VideoGallery\Service\Janitor;
use OCA\VideoGallery\Service\PlaybackDecision;
use OCA\VideoGallery\Service\PlaybackMemory;
use OCA\VideoGallery\Service\QualityGovernor;
use OCA\VideoGallery\Service\Transcoder;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The conversation with the player: what shall we do with this file, how is it
 * going, change the quality, stop now.
 *
 * The media itself is served by StreamController, which answers plain HTTP
 * because a video element and hls.js speak nothing else.
 */
class PlaybackController extends OCSController {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private ItemMapper $items,
		private SessionMapper $sessions,
		private Transcoder $transcoder,
		private PlaybackDecision $decision,
		private PlaybackMemory $memory,
		private QualityGovernor $governor,
		private Janitor $janitor,
		private Config $config,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function userId(): string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}

	// -- starting and steering playback -------------------------------------

	/**
	 * Work out how this file should be played, and set it up.
	 *
	 * The client sends what its browser can decode and how fast its link
	 * measured, and gets back either a plain URL to the file or a playlist that
	 * a converted stream will be delivered through.
	 */
	#[NoAdminRequired]
	public function open(int $fileId, string $client = '', float $bandwidth = 0, string $profile = 'auto', float $start = 0, int $audioIndex = -1): DataResponse {
		$userId = $this->userId();
		$item = $this->items->find($userId, $fileId);
		if ($item === null) {
			return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
		$capabilities = json_decode($client, true);
		if (!is_array($capabilities)) {
			$capabilities = [];
		}

		// What has worked for this shape of browser and this shape of file before.
		$signature = $this->memory->signature($capabilities, $item);
		$plan = $this->decision->decide(
			$item,
			$capabilities,
			$bandwidth,
			$profile,
			$this->memory->recall($signature),
			$this->memory->knownBad($signature),
		);

		if ($plan['mode'] === PlaybackDecision::DIRECT) {
			// Nothing is produced for a file sent as it is, so there is no session
			// to report back on; the decision is recorded here and now.
			$this->memory->remember($signature, PlaybackDecision::DIRECT, 'none', $plan['profile'], [
				'success' => true,
				'sample' => ['container' => $item->getContainer(), 'video' => $item->getVcodec(), 'audio' => $item->getAcodec()],
			]);
			return new DataResponse([
				'mode' => PlaybackDecision::DIRECT,
				'url' => $this->urlGenerator->linkToRoute('videogallery.stream.direct', ['fileId' => $fileId]),
				'plan' => $plan,
				'item' => $item->jsonSerialize(),
				'signature' => $signature,
			]);
		}

		// Converting needs an encoder slot. If they are all busy, say so rather
		// than starting something that will fight the others for the hardware.
		if (!$this->transcoder->slotAvailable()) {
			$fallback = $this->config->getBool('software_fallback');
			if (!$fallback) {
				return new DataResponse([
					'mode' => 'busy',
					'plan' => $plan,
					'message' => 'Every conversion slot is in use. Try again shortly, or open the file in a player on this device.',
					'active' => $this->transcoder->activeCount(),
				], Http::STATUS_SERVICE_UNAVAILABLE);
			}
		}

		try {
			$session = $this->transcoder->open($userId, $item, $plan, $start, $audioIndex);
		} catch (\Throwable $e) {
			$this->logger->error('Video Gallery could not start playback: ' . $e->getMessage(), ['exception' => $e]);
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$this->transcoder->recordContext($session, $signature, $capabilities);
		return new DataResponse([
			'mode' => $plan['mode'],
			'session' => $session->jsonSerialize(),
			'url' => $this->urlGenerator->linkToRoute('videogallery.stream.master', ['sessionId' => $session->getToken()]),
			'plan' => $plan,
			'item' => $item->jsonSerialize(),
			'signature' => $signature,
		]);
	}

	/**
	 * The player checking in. This is what keeps the session alive, and what the
	 * quality is steered by.
	 */
	#[NoAdminRequired]
	public function ping(string $sessionId, float $position = 0, float $buffer = 0, int $stalls = 0, float $bandwidth = 0, int $droppedFrames = 0, int $startupMs = 0): DataResponse {
		$session = $this->ownedSession($sessionId);
		if ($session === null) {
			return new DataResponse(['message' => 'Unknown session'], Http::STATUS_NOT_FOUND);
		}
		$this->sessions->touch($sessionId);
		$this->transcoder->throttle($session, $position);
		$this->learn($session, $stalls, $position, $startupMs);

		$item = $this->items->find($session->getUserId(), $session->getFileId());
		if ($item === null) {
			return new DataResponse(['state' => $session->getState()]);
		}
		$verdict = $this->governor->evaluate($session, $item, [
			'bandwidthKbps' => $bandwidth,
			'bufferSeconds' => $buffer,
			'stalls' => $stalls,
			'position' => $position,
			'droppedFrames' => $droppedFrames,
		]);

		$response = [
			'state' => $session->getState(),
			'error' => $session->getError(),
			'action' => $verdict['action'],
			'profile' => $session->getProfile(),
			'ahead' => max(0, $this->aheadSeconds($session, $position)),
		];

		if ($verdict['action'] !== QualityGovernor::KEEP && $verdict['profile'] !== $session->getProfile()) {
			$session = $this->transcoder->switchProfile($session, $verdict['profile'], $position);
			$response['profile'] = $session->getProfile();
			$response['reason'] = $verdict['reason'];
			// Same session, same playlist URL: the player reloads it and carries
			// on from where it was, at the new quality.
			$response['reload'] = $this->urlGenerator->linkToRoute('videogallery.stream.master', ['sessionId' => $session->getToken()]);
		}
		return new DataResponse($response);
	}

	/** A deliberate jump: give the encoder its new starting point at once. */
	#[NoAdminRequired]
	public function seek(string $sessionId, float $position = 0): DataResponse {
		$session = $this->ownedSession($sessionId);
		if ($session === null) {
			return new DataResponse(['message' => 'Unknown session'], Http::STATUS_NOT_FOUND);
		}
		$this->sessions->touch($sessionId);
		$segment = $this->transcoder->segmentIndexFor($session, $position);
		return new DataResponse([
			'segment' => $segment,
			'ready' => is_file($this->transcoder->segmentPath($session, $segment)),
		]);
	}

	/** The viewer asked for a different quality themselves. */
	#[NoAdminRequired]
	public function report(string $sessionId, string $profile = '', float $position = 0): DataResponse {
		$session = $this->ownedSession($sessionId);
		if ($session === null) {
			return new DataResponse(['message' => 'Unknown session'], Http::STATUS_NOT_FOUND);
		}
		if ($profile === '') {
			throw new OCSBadRequestException('A quality is required.');
		}
		$session = $this->transcoder->switchProfile($session, $profile, $position);
		return new DataResponse([
			'profile' => $session->getProfile(),
			'reload' => $this->urlGenerator->linkToRoute('videogallery.stream.master', ['sessionId' => $session->getToken()]),
		]);
	}

	/**
	 * Finished. The encoder stops and the working directory goes immediately,
	 * rather than waiting for the sweep to notice.
	 */
	#[NoAdminRequired]
	public function close(string $sessionId): DataResponse {
		$session = $this->ownedSession($sessionId);
		if ($session === null) {
			return new DataResponse([]);
		}
		$this->janitor->endSession($session, 'closed by the player');
		$freed = $this->janitor->dropSession($session);
		$this->governor->forget($sessionId);
		return new DataResponse(['freed' => $freed]);
	}

	/**
	 * Note how this session is going, against the situation that produced it.
	 *
	 * The first check-in is the telling one: if the player has got far enough to
	 * report a position, the stream is playing, and whatever was decided was
	 * right. Later check-ins add only the stumbles.
	 */
	private function learn(Session $session, int $stalls, float $position, int $startupMs): void {
		$context = $this->transcoder->context($session);
		$signature = (string)($context['signature'] ?? '');
		if ($signature === '') {
			return;
		}
		if ($session->getState() === Session::FAILED) {
			// A way of playing that did not play. Recorded once, and avoided in
			// future for this same situation while another way remains.
			if ($this->claimMarker($session, 'failed-recorded')) {
				$this->memory->remember($signature, $session->getMode(), $session->getSegmentType(), $session->getProfile(), [
					'success' => false,
					'sample' => ['error' => mb_substr((string)$session->getError(), 0, 200)],
				]);
			}
			return;
		}

		$playing = $position > 0.5;
		$this->memory->remember($signature, $session->getMode(), $session->getSegmentType(), $session->getProfile(), [
			// Only counted once: the marker file is what makes the first report
			// the one that counts as a success.
			'success' => ($playing && $this->claimMarker($session, 'reported')) ? true : null,
			'stalls' => $stalls,
			'watched' => (int)round($this->config->getInt('governor_interval')),
			'firstSegmentMs' => $startupMs,
		]);
	}

	/** True the first time it is asked for a given marker, and false afterwards. */
	private function claimMarker(Session $session, string $name): bool {
		$marker = $session->getDir() . '/' . $name;
		if (is_file($marker)) {
			return false;
		}
		return @file_put_contents($marker, (string)time()) !== false;
	}

	/** How many seconds of stream are ready beyond where the viewer is. */
	private function aheadSeconds(Session $session, float $position): float {
		$boundaries = $this->transcoder->boundaries($session);
		$next = $this->transcoder->highestSegment($session) + 1;
		$producedTo = $boundaries[$next] ?? ($session->getDurationMs() / 1000);
		return $producedTo - $position;
	}

	/** A session, but only if it belongs to whoever is asking. */
	private function ownedSession(string $token): ?Session {
		$session = $this->sessions->findByToken($token);
		if ($session === null) {
			return null;
		}
		return $session->getUserId() === $this->userId() ? $session : null;
	}
}
