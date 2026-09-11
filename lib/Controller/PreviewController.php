<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Controller;

use OCA\VideoGallery\AppInfo\Application;
use OCA\VideoGallery\Db\Asset;
use OCA\VideoGallery\Db\ItemMapper;
use OCA\VideoGallery\Http\RangeResponse;
use OCA\VideoGallery\Service\PreviewService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The cover pictures, the clips that play under the pointer, and the thumbnail
 * strips for scrubbing.
 */
class PreviewController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private ItemMapper $items,
		private PreviewService $previews,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function poster(int $fileId): Response {
		return $this->serve($fileId, Asset::POSTER, 'image/jpeg');
	}

	/**
	 * The short silent clip. Made on the first request if it is not there yet,
	 * which is why hovering something nobody has hovered before takes a moment.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function loop(int $fileId): Response {
		return $this->serve($fileId, Asset::LOOP, 'video/mp4');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function sprite(int $fileId): Response {
		return $this->serve($fileId, Asset::SPRITE, 'image/jpeg');
	}

	private function flagFor(string $kind): int {
		return match ($kind) {
			Asset::POSTER => \OCA\VideoGallery\Db\Item::ASSET_POSTER,
			Asset::LOOP => \OCA\VideoGallery\Db\Item::ASSET_LOOP,
			Asset::SPRITE => \OCA\VideoGallery\Db\Item::ASSET_SPRITE,
			default => 0,
		};
	}

	private function serve(int $fileId, string $kind, string $contentType): Response {
		$userId = $this->userSession->getUser()?->getUID() ?? '';
		$item = $this->items->find($userId, $fileId);
		if ($item === null) {
			return new DataDisplayResponse('Not found', Http::STATUS_NOT_FOUND);
		}
		$path = $this->previews->ensure($item, $kind);
		if ($path === null) {
			// Either this cannot be made at all, or every place for making one
			// is taken. The two deserve different answers: the second is worth
			// coming back for.
			if ($item->hasAsset($this->flagFor($kind))) {
				return new DataDisplayResponse('Not available', Http::STATUS_NOT_FOUND);
			}
			return new DataDisplayResponse('Busy', Http::STATUS_SERVICE_UNAVAILABLE, [
				'Retry-After' => '8',
				'Cache-Control' => 'no-store',
			]);
		}
		$response = new RangeResponse($path, $contentType, $this->request->getHeader('Range') ?: null);
		// These only change when the file behind them does, and then they are
		// made afresh under a new name.
		$response->cacheFor(604800, false, true);
		return $response;
	}
}
