<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Controller;

use OCA\VideoGallery\AppInfo\Application;
use OCA\VideoGallery\Db\ItemMapper;
use OCA\VideoGallery\Db\Session;
use OCA\VideoGallery\Db\SessionMapper;
use OCA\VideoGallery\Http\RangeResponse;
use OCA\VideoGallery\Service\Config;
use OCA\VideoGallery\Service\FileResolver;
use OCA\VideoGallery\Service\Janitor;
use OCA\VideoGallery\Service\SubtitleService;
use OCA\VideoGallery\Service\Transcoder;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Everything to do with getting the picture onto the screen.
 *
 * The OCS half is the conversation with the player: what shall we do with this
 * file, how is it going, stop now. The plain half is the media itself, because
 * a <video> element and hls.js speak HTTP and nothing else.
 */
class StreamController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private ItemMapper $items,
		private SessionMapper $sessions,
		private Transcoder $transcoder,
		private SubtitleService $subtitles,
		private FileResolver $resolver,
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

	// -- the media itself ---------------------------------------------------

	/**
	 * The same as closing, but reachable from a page that is being torn down.
	 *
	 * A closing tab cannot make an ordinary request — there is no time and no
	 * headers — so it sends a beacon here, and the encoder stops within the
	 * second rather than being left for the sweep to find a minute later.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function abandon(string $sessionId): Response {
		$session = $this->ownedSession($sessionId);
		if ($session !== null) {
			$this->janitor->endSession($session, 'the page was closed');
			$this->janitor->dropSession($session);
			$this->governor->forget($sessionId);
		}
		return new DataDisplayResponse('', Http::STATUS_NO_CONTENT);
	}

	/** The original file, with byte ranges, for when no conversion is needed. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function direct(int $fileId): Response {
		$userId = $this->userId();
		$item = $this->items->find($userId, $fileId);
		if ($item === null) {
			return new DataDisplayResponse('Not found', Http::STATUS_NOT_FOUND);
		}
		$file = $this->resolver->getFile($userId, $fileId);
		if ($file === null) {
			return new DataDisplayResponse('Not found', Http::STATUS_NOT_FOUND);
		}
		try {
			$handle = $file->fopen('r');
		} catch (\Throwable) {
			$handle = false;
		}
		if (!is_resource($handle)) {
			return new DataDisplayResponse('Cannot read', Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		$response = new RangeResponse(
			'',
			$item->getMimetype() ?: 'video/mp4',
			$this->request->getHeader('Range') ?: null,
			null,
			$handle,
			$item->getSize(),
		);
		$response->cacheFor(3600, false, true);
		return $response;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function master(string $sessionId): Response {
		$session = $this->ownedSession($sessionId);
		if ($session === null) {
			return new DataDisplayResponse('Unknown session', Http::STATUS_NOT_FOUND);
		}
		$this->sessions->touch($sessionId);
		$indexUrl = $this->urlGenerator->linkToRoute('videogallery.stream.playlist', ['sessionId' => $sessionId]);
		return $this->playlistResponse($this->transcoder->master($session, $indexUrl));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function playlist(string $sessionId): Response {
		$session = $this->ownedSession($sessionId);
		if ($session === null) {
			return new DataDisplayResponse('Unknown session', Http::STATUS_NOT_FOUND);
		}
		$this->sessions->touch($sessionId);
		return $this->playlistResponse($this->transcoder->playlist($session));
	}

	/**
	 * One segment. If it does not exist yet this call is what causes it to be
	 * made, and it waits here until it is ready.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function segment(string $sessionId, int $index, string $ext = 'ts'): Response {
		$session = $this->ownedSession($sessionId);
		if ($session === null) {
			return new DataDisplayResponse('Unknown session', Http::STATUS_NOT_FOUND);
		}
		try {
			$path = $this->transcoder->ensureSegment($session, $index);
		} catch (\Throwable $e) {
			$this->logger->error('Video Gallery could not produce segment ' . $index . ': ' . $e->getMessage(), ['exception' => $e]);
			return new DataDisplayResponse('Encoder failure', Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		if ($path === null) {
			$fresh = $this->sessions->findByToken($sessionId);
			$message = $fresh?->getError() ?? 'The segment could not be produced in time.';
			return new DataDisplayResponse($message, Http::STATUS_SERVICE_UNAVAILABLE);
		}
		$response = new RangeResponse($path, $this->transcoder->segmentContentType($session), $this->request->getHeader('Range') ?: null);
		// A segment for a given session never changes once written.
		$response->cacheFor(86400, false, true);
		return $response;
	}

	/**
	 * The initialisation segment of a fragmented MP4 stream: the headers that
	 * describe what the segments contain. Nothing plays until this has loaded.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function init(string $sessionId): Response {
		$session = $this->ownedSession($sessionId);
		if ($session === null) {
			return new DataDisplayResponse('Unknown session', Http::STATUS_NOT_FOUND);
		}
		$path = $this->transcoder->ensureInit($session);
		if ($path === null) {
			return new DataDisplayResponse('Not ready', Http::STATUS_SERVICE_UNAVAILABLE);
		}
		$response = new RangeResponse($path, 'video/mp4', $this->request->getHeader('Range') ?: null);
		$response->cacheFor(86400, false, true);
		return $response;
	}

	/** An embedded subtitle track, converted to WebVTT the first time it is asked for. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function subtitle(int $fileId, int $index): Response {
		$item = $this->items->find($this->userId(), $fileId);
		if ($item === null) {
			return new DataDisplayResponse('Not found', Http::STATUS_NOT_FOUND);
		}
		$path = $this->subtitles->ensure($item, $index);
		if ($path === null) {
			return new DataDisplayResponse('Track not available', Http::STATUS_NOT_FOUND);
		}
		$response = new DataDisplayResponse((string)file_get_contents($path), Http::STATUS_OK, [
			'Content-Type' => 'text/vtt; charset=utf-8',
		]);
		$response->cacheFor(86400, false, true);
		return $response;
	}

	/**
	 * Bytes to time a download against, so the client can find out what the link
	 * between it and this server will really carry.
	 *
	 * The payload is random, because anything compressible would be squeezed by
	 * the web server and measure the wrong thing entirely.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function bandwidth(int $bytes = 0): Response {
		$limit = $this->config->getInt('bandwidth_probe_bytes');
		$size = $bytes > 0 ? min($bytes, $limit) : $limit;
		$payload = random_bytes(max(1024, $size));
		$response = new DataDisplayResponse($payload, Http::STATUS_OK, [
			'Content-Type' => 'application/octet-stream',
			'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
			'Pragma' => 'no-cache',
			// Asking the web server not to compress what is already incompressible.
			'Content-Encoding' => 'identity',
			'X-Accel-Buffering' => 'no',
		]);
		return $response;
	}

	private function playlistResponse(string $body): Response {
		$response = new DataDisplayResponse($body, Http::STATUS_OK, [
			'Content-Type' => 'application/vnd.apple.mpegurl',
			'Cache-Control' => 'no-store, max-age=0',
		]);
		return $response;
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
