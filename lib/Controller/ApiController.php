<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Controller;

use OCA\VideoGallery\AppInfo\Application;
use OCA\VideoGallery\Db\ItemMapper;
use OCA\VideoGallery\Db\Progress;
use OCA\VideoGallery\Db\ProgressMapper;
use OCA\VideoGallery\Service\Config;
use OCA\VideoGallery\Service\ExternalPlayer;
use OCA\VideoGallery\Service\GalleryFolder;
use OCA\VideoGallery\Service\Indexer;
use OCA\VideoGallery\Service\Library;
use OCA\VideoGallery\Service\PlaybackDecision;
use OCA\VideoGallery\Service\PreviewService;
use OCA\VideoGallery\Service\Series;
use OCA\VideoGallery\Service\SubtitleService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class ApiController extends OCSController {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private ItemMapper $items,
		private ProgressMapper $progress,
		private Library $library,
		private GalleryFolder $galleryFolder,
		private Indexer $indexer,
		private PreviewService $previews,
		private Series $series,
		private SubtitleService $subtitles,
		private ExternalPlayer $external,
		private PlaybackDecision $decision,
		private Config $config,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function userId(): string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}

	#[NoAdminRequired]
	public function config(): DataResponse {
		return new DataResponse([
			'ladder' => $this->decision->ladder(),
			'stats' => $this->items->stats($this->userId()),
			'transcoding' => $this->config->getBool('transcode_enabled'),
			'externalPlayer' => $this->config->getBool('external_player_enabled'),
		]);
	}

	/** The front page: the featured video and every row under it. */
	#[NoAdminRequired]
	public function rails(): DataResponse {
		$userId = $this->userId();
		// Made on the first visit, so there is somewhere obvious to put things.
		$this->galleryFolder->ensure($userId);
		return new DataResponse($this->library->home($userId) + ['defaultFolder' => $this->galleryFolder->name()]);
	}

	/** The library as a timeline, a page at a time. */
	#[NoAdminRequired]
	public function timeline(int $limit = 120, int $offset = 0, string $query = '', string $folder = '', int $from = 0, int $to = 0, string $sort = 'taken_desc'): DataResponse {
		$filter = array_filter([
			'query' => $query,
			'folder' => $folder,
			'from' => $from,
			'to' => $to,
			'sort' => $sort,
		]);
		return new DataResponse($this->library->timeline($this->userId(), min(500, max(1, $limit)), max(0, $offset), $filter));
	}

	/** A flat list, for the grid and for search. */
	#[NoAdminRequired]
	public function items(int $limit = 120, int $offset = 0, string $query = '', string $folder = '', string $sort = 'taken_desc', int $from = 0, int $to = 0, int $minDuration = 0, int $maxDuration = 0, string $codec = ''): DataResponse {
		$userId = $this->userId();
		$filter = array_filter([
			'query' => $query,
			'folder' => $folder,
			'sort' => $sort,
			'from' => $from,
			'to' => $to,
			'minDuration' => $minDuration,
			'maxDuration' => $maxDuration,
			'codec' => $codec,
		]);
		$items = $this->items->search($userId, $filter, min(500, max(1, $limit)), max(0, $offset));
		$progress = $this->progress->forFiles($userId, array_map(static fn ($i) => $i->getFileId(), $items));

		$out = [];
		foreach ($items as $item) {
			$row = $item->jsonSerialize();
			$row['progress'] = isset($progress[$item->getFileId()]) ? $progress[$item->getFileId()]->jsonSerialize() : null;
			$out[] = $row;
		}
		return new DataResponse([
			'items' => $out,
			'total' => $this->items->count($userId, $filter),
			'offset' => $offset,
			'limit' => $limit,
		]);
	}

	/** Everything about one video, including what the player needs to start. */
	#[NoAdminRequired]
	public function item(int $fileId): DataResponse {
		$userId = $this->userId();
		$item = $this->items->find($userId, $fileId);
		if ($item === null) {
			return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
		$progress = $this->progress->find($userId, $fileId);
		return new DataResponse([
			'item' => $item->jsonSerialize(),
			'progress' => $progress?->jsonSerialize(),
			'subtitles' => $this->subtitles->describe($item),
			'sprite' => $item->hasAsset(\OCA\VideoGallery\Db\Item::ASSET_SPRITE)
				? $this->previews->spriteLayout($item)
				: null,
			'ladder' => $this->decision->ladder(),
			'sourceKbps' => round($this->decision->sourceBitrateKbps($item)),
			// Where this sits in a sequence, if it is part of one.
			'series' => $this->series->context($userId, $item),
		]);
	}

	/** Folders holding videos, for the filter menu. */
	#[NoAdminRequired]
	public function folders(): DataResponse {
		return new DataResponse(['folders' => $this->items->folders($this->userId())]);
	}

	/** Remember where the viewer got to. */
	#[NoAdminRequired]
	public function setProgress(int $fileId, int $position = 0, int $duration = 0, bool $finished = false, int $audioIndex = -1, int $subIndex = -1): DataResponse {
		$userId = $this->userId();
		$item = $this->items->find($userId, $fileId);
		if ($item === null) {
			return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
		$durationMs = $duration > 0 ? $duration * 1000 : $item->getDurationMs();
		$positionMs = max(0, $position * 1000);
		// Within the last half minute counts as watched; nobody wants a film they
		// finished sitting in "carry on watching" forever.
		$done = $finished || ($durationMs > 0 && $positionMs > $durationMs - 30000);

		$entity = $this->progress->find($userId, $fileId);
		if ($entity === null) {
			$entity = new Progress();
			$entity->setUserId($userId);
			$entity->setFileId($fileId);
		}
		$entity->setPositionMs($positionMs);
		$entity->setDurationMs($durationMs);
		$entity->setFinished($done ? 1 : 0);
		$entity->setAudioIndex($audioIndex);
		$entity->setSubIndex($subIndex);
		$entity->setUpdatedAt(time());
		$saved = $entity->getId() === null ? $this->progress->insert($entity) : $this->progress->update($entity);
		return new DataResponse($saved->jsonSerialize());
	}

	#[NoAdminRequired]
	public function deleteProgress(int $fileId): DataResponse {
		$this->progress->deleteByFileId($fileId, $this->userId());
		return new DataResponse([]);
	}

	/** Links that open the untouched original in a player on the device. */
	#[NoAdminRequired]
	public function externalToken(int $fileId): DataResponse {
		if (!$this->config->getBool('external_player_enabled')) {
			return new DataResponse(['message' => 'Disabled'], Http::STATUS_FORBIDDEN);
		}
		$userId = $this->userId();
		$item = $this->items->find($userId, $fileId);
		if ($item === null) {
			return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
		$token = $this->external->mint($userId, $fileId);
		return new DataResponse($this->external->links(
			$token,
			pathinfo($item->getName(), PATHINFO_FILENAME),
			(int)round($item->getDurationMs() / 1000),
		));
	}

	/** Look for files that are not in the library yet. */
	#[NoAdminRequired]
	public function rescan(): DataResponse {
		$userId = $this->userId();
		$sync = $this->indexer->sync($userId);
		$queue = $this->indexer->processQueue(min(50, $this->config->getInt('index_batch')), $userId);
		return new DataResponse(['sync' => $sync, 'queue' => $queue, 'stats' => $this->items->stats($userId)]);
	}
}
