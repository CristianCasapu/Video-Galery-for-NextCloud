<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Service;

use OCA\VideoGallery\Db\Item;
use OCA\VideoGallery\Db\ItemMapper;
use OCA\VideoGallery\Db\ProgressMapper;
use OCP\IL10N;

/**
 * Arranges a pile of files into something worth looking at: the rows on the
 * front page, and the one video that gets the big treatment at the top.
 */
class Library {
	private const RAIL_SIZE = 24;

	public function __construct(
		private ItemMapper $items,
		private ProgressMapper $progress,
		private GalleryFolder $folder,
		private IL10N $l10n,
	) {
	}

	/**
	 * The front page.
	 *
	 * @return array<string, mixed>
	 */
	public function home(string $userId): array {
		$rails = [];
		$this->addRail($rails, 'continue', $this->l10n->t('Carry on watching'), $this->continueWatching($userId));
		$this->addRail($rails, 'mine', $this->folder->name(), $this->inDefaultFolder($userId));
		$this->addRail($rails, 'recent', $this->l10n->t('Recently added'), $this->recent($userId));
		$this->addRail($rails, 'onthisday', $this->onThisDayTitle(), $this->onThisDay($userId));
		foreach ($this->yearRails($userId) as $rail) {
			$this->addRail($rails, $rail['id'], $rail['title'], $rail['items']);
		}
		$this->addRail($rails, 'films', $this->l10n->t('Longer than twenty minutes'), $this->byDuration($userId, 1200, 0));
		$this->addRail($rails, 'clips', $this->l10n->t('Short clips'), $this->byDuration($userId, 0, 120));
		foreach ($this->folderRails($userId) as $rail) {
			$this->addRail($rails, $rail['id'], $rail['title'], $rail['items']);
		}
		$this->addRail($rails, 'rediscover', $this->l10n->t('Worth another look'), $this->rediscover($userId));

		return [
			'hero' => $this->hero($userId, $rails),
			'rails' => $rails,
			'stats' => $this->items->stats($userId),
		];
	}

	/**
	 * @param list<array<string, mixed>> $rails
	 * @param list<Item> $items
	 */
	private function addRail(array &$rails, string $id, string $title, array $items): void {
		if ($items === []) {
			return;
		}
		$rails[] = [
			'id' => $id,
			'title' => $title,
			'items' => array_map(static fn (Item $item) => $item->jsonSerialize(), $items),
		];
	}

	/** @return list<Item> */
	private function continueWatching(string $userId): array {
		$progress = $this->progress->unfinished($userId, self::RAIL_SIZE);
		if ($progress === []) {
			return [];
		}
		$fileIds = array_map(static fn ($p) => $p->getFileId(), $progress);
		$found = $this->items->search($userId, ['fileIds' => $fileIds], count($fileIds));
		// Keep the order the progress list gave us: most recently watched first.
		$byId = [];
		foreach ($found as $item) {
			$byId[$item->getFileId()] = $item;
		}
		$ordered = [];
		foreach ($fileIds as $fileId) {
			if (isset($byId[$fileId])) {
				$ordered[] = $byId[$fileId];
			}
		}
		return $ordered;
	}

	/**
	 * What is in the account's own video folder, which is usually the answer to
	 * "where did I put that".
	 *
	 * @return list<Item>
	 */
	private function inDefaultFolder(string $userId): array {
		$name = $this->folder->name();
		if ($name === '') {
			return [];
		}
		return $this->items->search($userId, ['folder' => $name, 'sort' => 'taken_desc'], self::RAIL_SIZE);
	}

	/** @return list<Item> */
	private function recent(string $userId): array {
		return $this->items->search($userId, ['sort' => 'added_desc'], self::RAIL_SIZE);
	}

	private function onThisDayTitle(): string {
		return $this->l10n->t('On this day');
	}

	/**
	 * Videos shot on today's date in years gone by.
	 *
	 * @return list<Item>
	 */
	private function onThisDay(string $userId): array {
		$out = [];
		$month = (int)date('n');
		$day = (int)date('j');
		$thisYear = (int)date('Y');
		for ($year = $thisYear - 1; $year >= $thisYear - 15 && count($out) < self::RAIL_SIZE; $year--) {
			$from = mktime(0, 0, 0, $month, $day, $year);
			if ($from === false) {
				continue;
			}
			$found = $this->items->search($userId, [
				'from' => $from,
				'to' => $from + 86400,
				'sort' => 'taken_desc',
			], self::RAIL_SIZE - count($out));
			foreach ($found as $item) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * A row per year, for the years that actually hold something.
	 *
	 * @return list<array{id: string, title: string, items: list<Item>}>
	 */
	private function yearRails(string $userId): array {
		$years = [];
		foreach ($this->items->timeline($userId, 'year') as $bucket) {
			$years[(int)$bucket['day']] = (int)$bucket['count'];
		}
		arsort($years);
		$rails = [];
		$taken = 0;
		foreach ($years as $year => $count) {
			if ($taken >= 4 || $count < 3 || $year < 1980) {
				continue;
			}
			$from = mktime(0, 0, 0, 1, 1, $year);
			$to = mktime(0, 0, 0, 1, 1, $year + 1);
			if ($from === false || $to === false) {
				continue;
			}
			$items = $this->items->search($userId, ['from' => $from, 'to' => $to, 'sort' => 'taken_desc'], self::RAIL_SIZE);
			if ($items === []) {
				continue;
			}
			$rails[] = ['id' => 'year-' . $year, 'title' => (string)$year, 'items' => $items];
			$taken++;
		}
		return $rails;
	}

	/** @return list<Item> */
	private function byDuration(string $userId, int $minSeconds, int $maxSeconds): array {
		$filter = ['sort' => 'taken_desc'];
		if ($minSeconds > 0) {
			$filter['minDuration'] = $minSeconds;
		}
		if ($maxSeconds > 0) {
			$filter['maxDuration'] = $maxSeconds;
		}
		return $this->items->search($userId, $filter, self::RAIL_SIZE);
	}

	/**
	 * A row for each folder that holds a decent number of videos, which is
	 * usually how people have already organised them.
	 *
	 * @return list<array{id: string, title: string, items: list<Item>}>
	 */
	private function folderRails(string $userId): array {
		$rails = [];
		$taken = 0;
		foreach ($this->items->folders($userId) as $folder) {
			if ($taken >= 5 || $folder['count'] < 4) {
				continue;
			}
			$path = $folder['path'];
			if ($path === '' || $this->folder->contains($path)) {
				continue;
			}
			$items = $this->items->search($userId, ['folder' => $path, 'sort' => 'taken_desc'], self::RAIL_SIZE);
			if ($items === []) {
				continue;
			}
			$rails[] = [
				'id' => 'folder-' . md5($path),
				'title' => basename($path),
				'items' => $items,
			];
			$taken++;
		}
		return $rails;
	}

	/**
	 * Something from more than a year ago, chosen at random, because a library
	 * this size is mostly things you have forgotten you have.
	 *
	 * @return list<Item>
	 */
	private function rediscover(string $userId): array {
		$cutoff = time() - (365 * 86400);
		$total = $this->items->count($userId, ['to' => $cutoff]);
		if ($total < 5) {
			return [];
		}
		$offset = random_int(0, max(0, $total - self::RAIL_SIZE));
		return $this->items->search($userId, ['to' => $cutoff, 'sort' => 'taken_desc'], self::RAIL_SIZE, $offset);
	}

	/**
	 * The video shown across the top. Something long enough to deserve the
	 * space, with a cover picture already made so the page does not open on an
	 * empty rectangle.
	 *
	 * @param list<array<string, mixed>> $rails
	 * @return array<string, mixed>|null
	 */
	private function hero(string $userId, array $rails): ?array {
		$candidates = $this->items->search($userId, ['minDuration' => 60, 'sort' => 'taken_desc'], 60);
		if ($candidates === []) {
			$candidates = $this->items->search($userId, ['sort' => 'taken_desc'], 20);
		}
		if ($candidates === []) {
			return null;
		}
		$withPoster = array_values(array_filter($candidates, static fn (Item $i) => $i->hasAsset(Item::ASSET_POSTER)));
		$pool = $withPoster !== [] ? $withPoster : $candidates;
		// Steady for a day: the front page should not shuffle on every reload.
		$seed = (int)date('Ymd') + crc32($userId);
		return $pool[$seed % count($pool)]->jsonSerialize();
	}

	/**
	 * The timeline view: days, each with its videos, newest first.
	 *
	 * @return array<string, mixed>
	 */
	public function timeline(string $userId, int $limit, int $offset, array $filter = []): array {
		$items = $this->items->search($userId, $filter + ['sort' => 'taken_desc'], $limit, $offset);
		$days = [];
		foreach ($items as $item) {
			$day = date('Y-m-d', $item->getTakenAt());
			if (!isset($days[$day])) {
				$days[$day] = ['day' => $day, 'label' => $this->dayLabel($item->getTakenAt()), 'items' => []];
			}
			$days[$day]['items'][] = $item->jsonSerialize();
		}
		return [
			'days' => array_values($days),
			'total' => $this->items->count($userId, $filter),
			'offset' => $offset,
			'limit' => $limit,
		];
	}

	private function dayLabel(int $timestamp): string {
		$today = strtotime('today');
		$yesterday = strtotime('yesterday');
		if ($timestamp >= $today) {
			return $this->l10n->t('Today');
		}
		if ($timestamp >= $yesterday) {
			return $this->l10n->t('Yesterday');
		}
		if ((int)date('Y', $timestamp) === (int)date('Y')) {
			return $this->l10n->l('date', $timestamp, ['width' => 'long']);
		}
		return $this->l10n->l('date', $timestamp, ['width' => 'long']);
	}
}
