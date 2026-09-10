<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Controller;

use OCA\VideoGallery\AppInfo\Application;
use OCA\VideoGallery\Db\Item;
use OCA\VideoGallery\Db\Session;
use OCA\VideoGallery\Db\SessionMapper;
use OCA\VideoGallery\Http\RangeResponse;
use OCA\VideoGallery\Service\Config;
use OCA\VideoGallery\Service\FileResolver;
use OCA\VideoGallery\Service\PlaybackDecision;
use OCA\VideoGallery\Service\PreviewService;
use OCA\VideoGallery\Service\ShareAccess;
use OCA\VideoGallery\Service\SubtitleService;
use OCA\VideoGallery\Service\Transcoder;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Share\IShare;
use OCP\Util;

/**
 * Watching through a link.
 *
 * The page a link opens, the password gate in front of it where there is one,
 * and the media itself. Everything here answers without an account, so every
 * method starts from the token and goes no further than the token allows.
 */
class PublicController extends Controller {
	public function __construct(
		IRequest $request,
		private ShareAccess $access,
		private SessionMapper $sessions,
		private Transcoder $transcoder,
		private PreviewService $previews,
		private SubtitleService $subtitles,
		private FileResolver $resolver,
		private PlaybackDecision $decision,
		private Config $config,
		private IInitialState $initialState,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	// -- the page -----------------------------------------------------------

	#[PublicPage]
	#[NoCSRFRequired]
	public function index(string $token): Response {
		$share = $this->access->find($token);
		if ($share === null) {
			return $this->gone();
		}
		if (!$this->access->isUnlocked($share)) {
			return $this->passwordForm($token, false);
		}
		return $this->page($share);
	}

	/** The password gate, for a link whose owner set one. */
	#[PublicPage]
	#[NoCSRFRequired]
	public function authenticate(string $token, string $password = ''): Response {
		$share = $this->access->find($token);
		if ($share === null) {
			return $this->gone();
		}
		if ($this->access->unlock($share, $password)) {
			return new RedirectResponse($this->urlGenerator->linkToRoute('videogallery.public.index', ['token' => $token]));
		}
		return $this->passwordForm($token, true);
	}

	private function passwordForm(string $token, bool $wrong): TemplateResponse {
		return new TemplateResponse('core', 'publicshareauth', [
			'wrongpw' => $wrong,
			'share' => null,
			'label' => '',
			'token' => $token,
		], 'guest');
	}

	private function gone(): TemplateResponse {
		$response = new TemplateResponse('core', '403', [
			'message' => 'This link is not available. It may have been removed, or it may have expired.',
		], 'guest');
		$response->setStatus(Http::STATUS_NOT_FOUND);
		return $response;
	}

	private function page(IShare $share): TemplateResponse {
		$this->initialState->provideInitialState('public', [
			'share' => $this->access->describe($share),
			'ladder' => $this->decision->ladder(),
			'segmentDuration' => $this->config->getInt('segment_duration'),
			'bandwidthProbe' => [
				'enabled' => $this->config->getBool('bandwidth_probe_enabled'),
				'bytes' => $this->config->getInt('bandwidth_probe_bytes'),
				'ttl' => $this->config->getInt('bandwidth_probe_ttl'),
			],
			'governor' => [
				'enabled' => $this->config->getBool('governor_enabled'),
				'interval' => $this->config->getInt('governor_interval'),
			],
			'previews' => [
				'enabled' => $this->config->getBool('preview_enabled'),
				'sprites' => $this->config->getBool('sprite_enabled'),
			],
			// A link that forbids downloading offers no way to take the file,
			// which includes handing it to a player on the device.
			'externalPlayer' => $this->config->getBool('external_player_enabled') && $this->access->canDownload($share),
			'transcoding' => $this->config->getBool('transcode_enabled'),
			'autoplay' => $this->config->getBool('autoplay_next'),
		]);
		Util::addScript(Application::APP_ID, 'videogallery-public');
		$response = new TemplateResponse(Application::APP_ID, 'public', [], 'base');

		$policy = new ContentSecurityPolicy();
		$policy->addAllowedMediaDomain("'self'");
		$policy->addAllowedMediaDomain('blob:');
		$policy->addAllowedImageDomain("'self'");
		$policy->addAllowedImageDomain('blob:');
		$policy->addAllowedImageDomain('data:');
		$policy->addAllowedConnectDomain("'self'");
		$policy->addAllowedConnectDomain('blob:');
		$policy->addAllowedWorkerSrcDomain('blob:');
		$response->setContentSecurityPolicy($policy);
		return $response;
	}

	// -- the media ----------------------------------------------------------

	#[PublicPage]
	#[NoCSRFRequired]
	public function master(string $token, string $sessionId): Response {
		$session = $this->session($token, $sessionId);
		if ($session === null) {
			return new DataDisplayResponse('Unknown session', Http::STATUS_NOT_FOUND);
		}
		$this->sessions->touch($sessionId);
		$indexUrl = $this->urlGenerator->linkToRoute('videogallery.public.playlist', ['token' => $token, 'sessionId' => $sessionId]);
		return $this->playlistResponse($this->transcoder->master($session, $indexUrl));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function playlist(string $token, string $sessionId): Response {
		$session = $this->session($token, $sessionId);
		if ($session === null) {
			return new DataDisplayResponse('Unknown session', Http::STATUS_NOT_FOUND);
		}
		$this->sessions->touch($sessionId);
		return $this->playlistResponse($this->transcoder->playlist($session));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function segment(string $token, string $sessionId, int $index, string $ext = 'ts'): Response {
		$session = $this->session($token, $sessionId);
		if ($session === null) {
			return new DataDisplayResponse('Unknown session', Http::STATUS_NOT_FOUND);
		}
		$path = $this->transcoder->ensureSegment($session, $index);
		if ($path === null) {
			return new DataDisplayResponse('Not ready', Http::STATUS_SERVICE_UNAVAILABLE);
		}
		$response = new RangeResponse($path, $this->transcoder->segmentContentType($session), $this->request->getHeader('Range') ?: null);
		$response->cacheFor(86400, false, true);
		return $response;
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function init(string $token, string $sessionId): Response {
		$session = $this->session($token, $sessionId);
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

	/**
	 * The file itself, which only a link that permits downloading will serve.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function direct(string $token, int $fileId): Response {
		$share = $this->unlockedShare($token);
		$item = $share === null ? null : $this->access->item($share, $fileId);
		if ($share === null || $item === null) {
			return new DataDisplayResponse('Not found', Http::STATUS_NOT_FOUND);
		}
		if (!$this->access->canDownload($share)) {
			return new DataDisplayResponse('This link allows watching but not downloading.', Http::STATUS_FORBIDDEN);
		}
		$file = $this->resolver->getFile($this->access->ownerId($share), $fileId);
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
		return new RangeResponse('', $item->getMimetype() ?: 'video/mp4', $this->request->getHeader('Range') ?: null, null, $handle, $item->getSize());
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function poster(string $token, int $fileId): Response {
		return $this->asset($token, $fileId, 'poster', 'image/jpeg');
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function loop(string $token, int $fileId): Response {
		return $this->asset($token, $fileId, 'loop', 'video/mp4');
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function sprite(string $token, int $fileId): Response {
		return $this->asset($token, $fileId, 'sprite', 'image/jpeg');
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function subtitle(string $token, int $fileId, int $index): Response {
		$share = $this->unlockedShare($token);
		$item = $share === null ? null : $this->access->item($share, $fileId);
		if ($item === null) {
			return new DataDisplayResponse('Not found', Http::STATUS_NOT_FOUND);
		}
		$path = $this->subtitles->ensure($item, $index);
		if ($path === null) {
			return new DataDisplayResponse('Track not available', Http::STATUS_NOT_FOUND);
		}
		$response = new DataDisplayResponse((string)file_get_contents($path), Http::STATUS_OK, ['Content-Type' => 'text/vtt; charset=utf-8']);
		$response->cacheFor(86400, false, true);
		return $response;
	}

	private function asset(string $token, int $fileId, string $kind, string $contentType): Response {
		$share = $this->unlockedShare($token);
		$item = $share === null ? null : $this->access->item($share, $fileId);
		if ($item === null) {
			return new DataDisplayResponse('Not found', Http::STATUS_NOT_FOUND);
		}
		$path = $this->previews->ensure($item, $kind);
		if ($path === null) {
			return new DataDisplayResponse('Not available', Http::STATUS_NOT_FOUND);
		}
		$response = new RangeResponse($path, $contentType, $this->request->getHeader('Range') ?: null);
		$response->cacheFor(604800, false, true);
		return $response;
	}

	private function unlockedShare(string $token): ?IShare {
		$share = $this->access->find($token);
		return ($share !== null && $this->access->isUnlocked($share)) ? $share : null;
	}

	private function session(string $token, string $sessionId): ?Session {
		if ($this->unlockedShare($token) === null) {
			return null;
		}
		$session = $this->sessions->findByToken($sessionId);
		return ($session !== null && $session->getShareToken() === $token) ? $session : null;
	}

	private function playlistResponse(string $body): Response {
		return new DataDisplayResponse($body, Http::STATUS_OK, [
			'Content-Type' => 'application/vnd.apple.mpegurl',
			'Cache-Control' => 'no-store, max-age=0',
		]);
	}
}
