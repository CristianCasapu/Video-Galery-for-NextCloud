<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Controller;

use OCA\VideoGallery\AppInfo\Application;
use OCA\VideoGallery\Db\Item;
use OCA\VideoGallery\Db\Session;
use OCA\VideoGallery\Db\SessionMapper;
use OCA\VideoGallery\Service\Config;
use OCA\VideoGallery\Service\Janitor;
use OCA\VideoGallery\Service\PlaybackDecision;
use OCA\VideoGallery\Service\PlaybackMemory;
use OCA\VideoGallery\Service\PreviewService;
use OCA\VideoGallery\Service\QualityGovernor;
use OCA\VideoGallery\Service\Series;
use OCA\VideoGallery\Service\ShareAccess;
use OCA\VideoGallery\Service\SubtitleService;
use OCA\VideoGallery\Service\Transcoder;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * The same conversation the player has with the server, held with somebody who
 * arrived through a link instead of an account.
 *
 * Every call begins by resolving the token, and every file named in a request is
 * checked against what that token actually opens onto — never against a path the
 * request supplied.
 */
class PublicApiController extends OCSController {
	public function __construct(
		IRequest $request,
		private ShareAccess $access,
		private SessionMapper $sessions,
		private Transcoder $transcoder,
		private PlaybackDecision $decision,
		private PlaybackMemory $memory,
		private QualityGovernor $governor,
		private SubtitleService $subtitles,
		private PreviewService $previews,
		private Series $series,
		private Janitor $janitor,
		private Config $config,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** The share, once, with every check applied. */
	private function share(string $token): ?IShare {
		$share = $this->access->find($token);
		if ($share === null || !$this->access->isUnlocked($share)) {
			return null;
		}
		return $share;
	}

	/** What this link opens onto: the videos, and the folders below it. */
	#[PublicPage]
	#[NoCSRFRequired]
	public function contents(string $token, string $folder = '', string $sort = 'name_asc', int $limit = 500, int $offset = 0): DataResponse {
		$share = $this->share($token);
		if ($share === null) {
			return new DataResponse(['message' => 'Not available'], Http::STATUS_NOT_FOUND);
		}
		$contents = $this->access->contents($share, $folder, $sort, min(1000, max(1, $limit)), max(0, $offset));
		return new DataResponse($contents + [
			'share' => $this->access->describe($share),
			'folder' => $folder,
			'ladder' => $this->decision->ladder(),
		]);
	}

	/** Everything the player needs about one video in the share. */
	#[PublicPage]
	#[NoCSRFRequired]
	public function item(string $token, int $fileId): DataResponse {
		$share = $this->share($token);
		$item = $share === null ? null : $this->access->item($share, $fileId);
		if ($share === null || $item === null) {
			return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse([
			'item' => $item->jsonSerialize(),
			'subtitles' => $this->subtitles->describe($item),
			'sprite' => $item->hasAsset(Item::ASSET_SPRITE) ? $this->previews->spriteLayout($item) : null,
			'ladder' => $this->decision->ladder(),
			'canDownload' => $this->access->canDownload($share),
			'next' => $this->series->nextInShare($share, $item),
		]);
	}

	/** Work out how to play something, and set it up. */
	#[PublicPage]
	#[NoCSRFRequired]
	public function play(string $token, int $fileId, string $client = '', float $bandwidth = 0, string $profile = 'auto', float $start = 0, int $audioIndex = -1): DataResponse {
		$share = $this->share($token);
		$item = $share === null ? null : $this->access->item($share, $fileId);
		if ($share === null || $item === null) {
			return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
		$capabilities = json_decode($client, true);
		if (!is_array($capabilities)) {
			$capabilities = [];
		}
		$canDownload = $this->access->canDownload($share);
		$signature = $this->memory->signature($capabilities, $item);
		$plan = $this->decision->decide(
			$item,
			$capabilities,
			$bandwidth,
			$profile,
			$this->memory->recall($signature),
			$this->memory->knownBad($signature),
			$canDownload,
		);

		if ($plan['mode'] === PlaybackDecision::DIRECT) {
			return new DataResponse([
				'mode' => PlaybackDecision::DIRECT,
				'url' => $this->urlGenerator->linkToRoute('videogallery.public.direct', ['token' => $token, 'fileId' => $fileId]),
				'plan' => $plan,
				'item' => $item->jsonSerialize(),
			]);
		}
		if (!$this->transcoder->slotAvailable() && !$this->config->getBool('software_fallback')) {
			return new DataResponse([
				'mode' => 'busy',
				'plan' => $plan,
				'message' => 'Every conversion slot is in use. Try again shortly.',
			], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		try {
			$session = $this->transcoder->open($this->access->ownerId($share), $item, $plan, $start, $audioIndex);
			$session->setShareToken($token);
			$this->sessions->update($session);
			$this->transcoder->recordContext($session, $signature, $capabilities);
		} catch (\Throwable $e) {
			$this->logger->error('Video Gallery could not start playback for a share: ' . $e->getMessage(), ['exception' => $e]);
			return new DataResponse(['message' => 'Playback could not be prepared.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new DataResponse([
			'mode' => $plan['mode'],
			'session' => $session->jsonSerialize(),
			'url' => $this->urlGenerator->linkToRoute('videogallery.public.master', ['token' => $token, 'sessionId' => $session->getToken()]),
			'plan' => $plan,
			'item' => $item->jsonSerialize(),
		]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function ping(string $token, string $sessionId, float $position = 0, float $buffer = 0, int $stalls = 0, float $bandwidth = 0): DataResponse {
		$session = $this->session($token, $sessionId);
		if ($session === null) {
			return new DataResponse(['message' => 'Unknown session'], Http::STATUS_NOT_FOUND);
		}
		$this->sessions->touch($sessionId);
		$this->transcoder->throttle($session, $position);

		$item = $this->itemFor($session);
		if ($item === null) {
			return new DataResponse(['state' => $session->getState()]);
		}
		$verdict = $this->governor->evaluate($session, $item, [
			'bandwidthKbps' => $bandwidth,
			'bufferSeconds' => $buffer,
			'stalls' => $stalls,
			'position' => $position,
		]);
		$response = [
			'state' => $session->getState(),
			'error' => $session->getError(),
			'action' => $verdict['action'],
			'profile' => $session->getProfile(),
		];
		if ($verdict['action'] !== QualityGovernor::KEEP && $verdict['profile'] !== $session->getProfile()) {
			$session = $this->transcoder->switchProfile($session, $verdict['profile'], $position);
			$response['profile'] = $session->getProfile();
			$response['reason'] = $verdict['reason'];
			$response['reload'] = $this->urlGenerator->linkToRoute('videogallery.public.master', ['token' => $token, 'sessionId' => $session->getToken()]);
		}
		return new DataResponse($response);
	}

	/** A quality chosen by the viewer. */
	#[PublicPage]
	#[NoCSRFRequired]
	public function report(string $token, string $sessionId, string $profile = '', float $position = 0): DataResponse {
		$session = $this->session($token, $sessionId);
		if ($session === null || $profile === '') {
			return new DataResponse(['message' => 'Unknown session'], Http::STATUS_NOT_FOUND);
		}
		$session = $this->transcoder->switchProfile($session, $profile, $position);
		return new DataResponse([
			'profile' => $session->getProfile(),
			'reload' => $this->urlGenerator->linkToRoute('videogallery.public.master', ['token' => $token, 'sessionId' => $session->getToken()]),
		]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function close(string $token, string $sessionId): DataResponse {
		$session = $this->session($token, $sessionId);
		if ($session !== null) {
			$this->janitor->endSession($session, 'closed by the viewer');
			$this->janitor->dropSession($session);
			$this->governor->forget($sessionId);
		}
		return new DataResponse([]);
	}

	/** A session, but only one belonging to this very link. */
	private function session(string $token, string $sessionId): ?Session {
		if ($this->share($token) === null) {
			return null;
		}
		$session = $this->sessions->findByToken($sessionId);
		if ($session === null || $session->getShareToken() !== $token) {
			return null;
		}
		return $session;
	}

	private function itemFor(Session $session): ?Item {
		$share = $this->access->find((string)$session->getShareToken());
		return $share === null ? null : $this->access->item($share, $session->getFileId());
	}
}
