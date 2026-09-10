<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Controller;

use OCA\VideoGallery\AppInfo\Application;
use OCA\VideoGallery\Db\ItemMapper;
use OCA\VideoGallery\Service\Config;
use OCA\VideoGallery\Service\Environment;
use OCA\VideoGallery\Service\PlaybackDecision;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;

class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private IInitialState $initialState,
		private IUserSession $userSession,
		private ItemMapper $items,
		private Config $config,
		private Environment $environment,
		private PlaybackDecision $decision,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		return $this->page(null);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function watch(int $fileId): TemplateResponse {
		return $this->page($fileId);
	}

	private function page(?int $fileId): TemplateResponse {
		$userId = $this->userSession->getUser()?->getUID() ?? '';
		$report = $this->environment->report();

		$this->initialState->provideInitialState('config', [
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
			'externalPlayer' => $this->config->getBool('external_player_enabled'),
			'transcoding' => $this->config->getBool('transcode_enabled'),
			'playbackPossible' => $report['playbackPossible'],
			'hardware' => $this->config->getBool('transcode_enabled') && ($report['status'] !== Environment::ERROR),
			'stats' => $userId === '' ? [] : $this->items->stats($userId),
			'openFileId' => $fileId,
		]);

		Util::addScript(Application::APP_ID, 'videogallery-main');
		$response = new TemplateResponse(Application::APP_ID, 'main');

		// Segments and previews are served from this same origin, but the media
		// element needs to be allowed to load them as media rather than as page
		// resources, and blob URLs are how a media source buffer is handed over.
		$policy = new ContentSecurityPolicy();
		$policy->addAllowedMediaDomain("'self'");
		$policy->addAllowedMediaDomain('blob:');
		$policy->addAllowedImageDomain("'self'");
		$policy->addAllowedImageDomain('blob:');
		$policy->addAllowedImageDomain('data:');
		$policy->addAllowedConnectDomain("'self'");
		$policy->addAllowedConnectDomain('blob:');
		// hls.js does its work in a worker, and hands the media element the
		// stream through a blob URL.
		$policy->addAllowedWorkerSrcDomain('blob:');
		$response->setContentSecurityPolicy($policy);
		return $response;
	}
}
