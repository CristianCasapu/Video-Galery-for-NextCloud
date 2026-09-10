<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Controller;

use OCA\VideoGallery\AppInfo\Application;
use OCA\VideoGallery\Db\ItemMapper;
use OCA\VideoGallery\Http\RangeResponse;
use OCA\VideoGallery\Service\Config;
use OCA\VideoGallery\Service\ExternalPlayer;
use OCA\VideoGallery\Service\FileResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Serves the untouched original to a player running on the viewer's own device.
 *
 * These are the only routes in the app that answer without a Nextcloud session,
 * because VLC on a television has no cookies to send. Authorisation rides in
 * the signed token in the address, which names one file, one account, and an
 * expiry, and cannot be altered without the server's secret.
 */
class ExternalController extends Controller {
	public function __construct(
		IRequest $request,
		private ExternalPlayer $external,
		private ItemMapper $items,
		private FileResolver $resolver,
		private Config $config,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function playlist(string $token): Response {
		$claim = $this->claim($token);
		if ($claim === null) {
			return new DataDisplayResponse('This link is no longer valid.', Http::STATUS_UNAUTHORIZED);
		}
		[$userId, $item] = $claim;
		$body = $this->external->playlist(
			pathinfo($item->getName(), PATHINFO_FILENAME),
			$this->external->fileUrl($token),
			(int)round($item->getDurationMs() / 1000),
		);
		return new DataDisplayResponse($body, Http::STATUS_OK, [
			'Content-Type' => 'audio/x-mpegurl',
			'Content-Disposition' => 'attachment; filename="' . rawurlencode(pathinfo($item->getName(), PATHINFO_FILENAME)) . '.m3u"',
			'Cache-Control' => 'no-store',
		]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function file(string $token): Response {
		$claim = $this->claim($token);
		if ($claim === null) {
			return new DataDisplayResponse('This link is no longer valid.', Http::STATUS_UNAUTHORIZED);
		}
		[$userId, $item] = $claim;
		$file = $this->resolver->getFile($userId, $item->getFileId());
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
		return new RangeResponse(
			'',
			$item->getMimetype() ?: 'video/mp4',
			$this->request->getHeader('Range') ?: null,
			null,
			$handle,
			$item->getSize(),
		);
	}

	/** @return array{0: string, 1: \OCA\VideoGallery\Db\Item}|null */
	private function claim(string $token): ?array {
		if (!$this->config->getBool('external_player_enabled')) {
			return null;
		}
		$claim = $this->external->verify($token);
		if ($claim === null) {
			return null;
		}
		$item = $this->items->find($claim['userId'], $claim['fileId']);
		return $item === null ? null : [$claim['userId'], $item];
	}
}
