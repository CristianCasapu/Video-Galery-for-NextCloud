<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Service;

use OCA\VideoGallery\Db\Item;
use OCA\VideoGallery\Db\ItemMapper;
use OCA\VideoGallery\Db\ProgressMapper;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IL10N;

/**
 * Arranges a pile of files into something worth looking at.
 *
 * The arrangement follows how people actually keep video: a course in one
 * folder, a holiday in another, whatever the phone saved in a third. So the
 * unit here is the folder rather than the file, and each folder is shown in the
 * way that suits what is in it — a course by the part you had reached, a folder
 * of clips by its newest.
 */
class Library {
	private ICache $cache;

	public function __construct(
		private ItemMapper $items,
		private ProgressMapper $progress,
		private Collections $collections,
		private GalleryFolder $folder,
		private Config $config,
		private IL10N $l10n,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed('videogallery-library');
	}

	private function railSize(): int {
		return $this->config->getInt('rail_size');
	}

	// -- the front page -----------------------------------------------------

	/**
	 * @return array<string, mixed>
	 */
	public function home(string $userId): array {
		// Arranging a library is the same work for every visit within a minute
		// or two of itself, and it is the one page people open most.
		$key = 'home-' . $userId;
		$cached = $this->cache->get($key);
		if (is_array($cached)) {
			return $cached;
		}
		$home = $this->buildHome($userId);
		$this->cache->set($key, $home, 90);
		return $home;
	}

	public function forget(string $userId): void {
		$this->cache->remove('home-' . $userId);
	}

	/** @return array<string, mixed> */
	private function buildHome(string $userId): array {
		$folders = $this->collections->build($userId);
		$watched = $this->progress->allFor($userId);
		$rails = [];

		// Where you left off, across everything.
		$resume = [];
		foreach ($this->progress->unfinished($userId, $this->railSize()) as $entry) {
			$resume[] = $entry->getFileId();
		}
		$this->addRail($rails, 'continue', $this->l10n->t('Carry on watching'), $userId, $resume, $folders, $watched);

		// A row for each kind of thing the administrator has defined, in the
		// order they defined them, each shown the way that kind wants showing:
		// a course opened at the part worth watching next, a folder of clips
		// newest first.
		foreach ($this->collections->categories() as $category) {
			if ($category['id'] === Collections::OTHER) {
				continue;
			}
			$ranked = $this->rank($folders, $category['id'], $watched);
			if ($ranked === []) {
				continue;
			}
			$ids = [];
			$reasons = [];
			if ($category['show'] === 'latest') {
				foreach ($ranked as $folder) {
					foreach ($this->collections->latest($folder, 8) as $fileId) {
						$ids[] = $fileId;
					}
					if (count($ids) >= $this->railSize() * 2) {
						break;
					}
				}
				$ids = array_slice($this->newestFirst($userId, $ids), 0, $this->railSize());
			} else {
				foreach (array_slice($ranked, 0, $this->railSize()) as $folder) {
					$entry = $folder['entry'];
					if ($entry === null) {
						continue;
					}
					$ids[] = $entry['fileId'];
					$reasons[$entry['fileId']] = $entry['reason'];
				}
			}
			$this->addRail($rails, $category['id'], $this->l10n->t($category['label']), $userId, $ids, $folders, $watched, $reasons);
		}

		$recent = array_map(
			static fn (Item $item) => $item->getFileId(),
			$this->items->search($userId, ['sort' => 'added_desc'], $this->railSize()),
		);
		$this->addRail($rails, 'recent', $this->l10n->t('Recently added'), $userId, $recent, $folders, $watched);

		// Everything that fitted no description, one card per folder.
		$otherIds = [];
		$otherReasons = [];
		$mine = $this->folder->name();
		foreach (array_slice($this->rank($folders, Collections::OTHER, $watched), 0, $this->railSize()) as $folder) {
			$entry = $folder['entry'];
			if ($entry === null || $folder['path'] === '' || $folder['path'] === $mine) {
				continue;
			}
			$otherIds[] = $entry['fileId'];
			$otherReasons[$entry['fileId']] = $entry['reason'];
		}
		$other = $this->collections->category(Collections::OTHER);
		$this->addRail($rails, 'collections', $this->l10n->t((string)($other['label'] ?? 'Folders')), $userId, $otherIds, $folders, $watched, $otherReasons);

		$this->addRail($rails, 'rediscover', $this->l10n->t('Worth another look'), $userId, $this->rediscover($userId), $folders, $watched);

		return [
			'hero' => $this->hero($userId, $folders, $watched),
			'rails' => $rails,
			'stats' => $this->items->stats($userId),
		];
	}

	/**
	 * Collections of one kind, most worth showing first.
	 *
	 * Something half watched comes before something untouched, and among the
	 * untouched the most recent comes first — which is the order anybody would
	 * put them in if asked.
	 *
	 * @param array<string, array<string, mixed>> $folders
	 * @param array<int, \OCA\VideoGallery\Db\Progress> $watched
	 * @return list<array<string, mixed>>
	 */
	private function rank(array $folders, string $kind, array $watched): array {
		$chosen = [];
		foreach ($folders as $folder) {
			if ($folder['kind'] !== $kind || $folder['count'] === 0) {
				continue;
			}
			$lastTouched = 0;
			foreach ($folder['unfinished'] as $at) {
				$lastTouched = max($lastTouched, (int)$at);
			}
			$folder['lastTouched'] = $lastTouched;
			$chosen[] = $folder;
		}
		usort($chosen, static function (array $a, array $b): int {
			if (($a['lastTouched'] > 0) !== ($b['lastTouched'] > 0)) {
				return $b['lastTouched'] <=> $a['lastTouched'];
			}
			return ($b['lastTouched'] ?: $b['latest']) <=> ($a['lastTouched'] ?: $a['latest']);
		});
		return $chosen;
	}

	/**
	 * Turn a list of file ids into cards, keeping the order they were given in
	 * and hanging on each one the folder it came from and how far it was watched.
	 *
	 * @param list<array<string, mixed>> $rails
	 * @param list<int> $fileIds
	 * @param array<string, array<string, mixed>> $folders
	 * @param array<int, \OCA\VideoGallery\Db\Progress> $watched
	 * @param array<int, string> $reasons
	 */
	private function addRail(array &$rails, string $id, string $title, string $userId, array $fileIds, array $folders, array $watched, array $reasons = []): void {
		$cards = $this->cards($userId, $fileIds, $folders, $watched, $reasons);
		if ($cards === []) {
			return;
		}
		$rails[] = ['id' => $id, 'title' => $title, 'items' => $cards];
	}

	/**
	 * @param list<int> $fileIds
	 * @param array<string, array<string, mixed>> $folders
	 * @param array<int, \OCA\VideoGallery\Db\Progress> $watched
	 * @param array<int, string> $reasons
	 * @return list<array<string, mixed>>
	 */
	public function cards(string $userId, array $fileIds, array $folders, array $watched, array $reasons = []): array {
		$fileIds = array_values(array_unique(array_filter($fileIds)));
		if ($fileIds === []) {
			return [];
		}
		$found = [];
		foreach ($this->items->search($userId, ['fileIds' => $fileIds], count($fileIds)) as $item) {
			$found[$item->getFileId()] = $item;
		}
		$cards = [];
		foreach ($fileIds as $fileId) {
			$item = $found[$fileId] ?? null;
			if ($item === null) {
				continue;
			}
			$row = $item->jsonSerialize();
			// Without this the red line under a card never appears, however far
			// somebody got through the film.
			$row['progress'] = isset($watched[$fileId]) ? $watched[$fileId]->jsonSerialize() : null;
			$folderPath = trim(dirname($item->getPath()), '.');
			$folder = $folders[$folderPath] ?? null;
			$row['collection'] = [
				'path' => $folderPath,
				'name' => $folderPath === '' ? $this->l10n->t('Top level') : basename($folderPath),
				'kind' => $folder['kind'] ?? Collections::OTHER,
				'count' => $folder['count'] ?? 0,
			];
			$row['reason'] = $reasons[$fileId] ?? null;
			$cards[] = $row;
		}
		return $cards;
	}

	/** @param list<int> $fileIds @return list<int> */
	private function newestFirst(string $userId, array $fileIds): array {
		if ($fileIds === []) {
			return [];
		}
		$found = $this->items->search($userId, ['fileIds' => $fileIds, 'sort' => 'taken_desc'], count($fileIds));
		return array_map(static fn (Item $item) => $item->getFileId(), $found);
	}

	/** @return list<int> */
	private function rediscover(string $userId): array {
		$cutoff = time() - (365 * 86400);
		$total = $this->items->count($userId, ['to' => $cutoff]);
		if ($total < 5) {
			return [];
		}
		$offset = random_int(0, max(0, $total - $this->railSize()));
		return array_map(
			static fn (Item $item) => $item->getFileId(),
			$this->items->search($userId, ['to' => $cutoff, 'sort' => 'taken_desc'], $this->railSize(), $offset),
		);
	}

	/**
	 * @param array<string, array<string, mixed>> $folders
	 * @param array<int, \OCA\VideoGallery\Db\Progress> $watched
	 * @return array<string, mixed>|null
	 */
	private function hero(string $userId, array $folders, array $watched): ?array {
		// Something half watched makes the best invitation there is.
		$unfinished = $this->progress->unfinished($userId, 1);
		if ($unfinished !== []) {
			$cards = $this->cards($userId, [$unfinished[0]->getFileId()], $folders, $watched, [$unfinished[0]->getFileId() => 'resume']);
			if ($cards !== []) {
				return $cards[0];
			}
		}
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
		$chosen = $pool[$seed % count($pool)];
		$cards = $this->cards($userId, [$chosen->getFileId()], $folders, $watched);
		return $cards[0] ?? null;
	}

	// -- everything, by folder ----------------------------------------------

	/**
	 * The whole library as folders, each opened at the part worth opening.
	 *
	 * @return array<string, mixed>
	 */
	public function everything(string $userId, int $limit = 20, int $offset = 0, string $query = ''): array {
		$folders = $this->collections->build($userId);
		$watched = $this->progress->allFor($userId);

		$ordered = [];
		foreach ($this->collections->categories() as $category) {
			foreach ($this->rank($folders, $category['id'], $watched) as $folder) {
				$ordered[] = $folder;
			}
		}
		if ($query !== '') {
			$needle = mb_strtolower($query);
			$ordered = array_values(array_filter($ordered, static function (array $folder) use ($needle): bool {
				if (str_contains(mb_strtolower((string)$folder['path']), $needle)) {
					return true;
				}
				foreach ($folder['items'] as $row) {
					if (str_contains(mb_strtolower((string)$row['name']), $needle)) {
						return true;
					}
				}
				return false;
			}));
		}

		$total = count($ordered);
		$page = array_slice($ordered, $offset, $limit);
		$perSection = 8;

		$sections = [];
		foreach ($page as $folder) {
			$ids = $this->showsLatest($folder['kind'])
				? $this->collections->latest($folder, $perSection)
				: $this->collections->fromEntry($folder, $perSection);
			$reasons = [];
			if (($folder['entry']['fileId'] ?? null) !== null) {
				$reasons[$folder['entry']['fileId']] = $folder['entry']['reason'];
			}
			$cards = $this->cards($userId, $ids, $folders, $watched, $reasons);
			if ($cards === []) {
				continue;
			}
			$sections[] = [
				'path' => $folder['path'],
				'name' => $folder['path'] === '' ? $this->l10n->t('Top level') : basename($folder['path']),
				'parent' => $folder['parent'],
				'kind' => $folder['kind'],
				'kindLabel' => $this->l10n->t((string)($this->collections->category((string)$folder['kind'])['label'] ?? '')),
				'count' => $folder['count'],
				'items' => $cards,
			];
		}

		return ['sections' => $sections, 'total' => $total, 'offset' => $offset, 'limit' => $limit];
	}

	// -- the timeline -------------------------------------------------------

	/**
	 * By year, then by day, and within a day by the folder each came from.
	 *
	 * @param array<string, mixed> $filter
	 * @return array<string, mixed>
	 */
	public function timeline(string $userId, int $limit, int $offset, array $filter = []): array {
		$found = $this->items->search($userId, $filter + ['sort' => 'taken_desc'], $limit, $offset);
		$folders = $this->collections->build($userId);
		$watched = $this->progress->allFor($userId);
		$cards = $this->cards($userId, array_map(static fn (Item $item) => $item->getFileId(), $found), $folders, $watched);

		$years = [];
		foreach ($cards as $card) {
			$when = (int)$card['takenAt'];
			$year = date('Y', $when);
			$day = date('Y-m-d', $when);
			$folderPath = (string)$card['collection']['path'];

			$years[$year] ??= ['year' => $year, 'count' => 0, 'days' => []];
			$years[$year]['count']++;
			$years[$year]['days'][$day] ??= [
				'day' => $day,
				'label' => $this->dayLabel($when),
				'groups' => [],
			];
			$years[$year]['days'][$day]['groups'][$folderPath] ??= [
				'path' => $folderPath,
				'name' => $card['collection']['name'],
				'kind' => $card['collection']['kind'],
				'count' => $card['collection']['count'],
				'items' => [],
			];
			$years[$year]['days'][$day]['groups'][$folderPath]['items'][] = $card;
		}

		// Out of the maps they were built in, into lists the page can walk.
		$out = [];
		foreach ($years as $year) {
			$days = [];
			foreach ($year['days'] as $day) {
				$day['groups'] = array_values($day['groups']);
				$days[] = $day;
			}
			$year['days'] = $days;
			$out[] = $year;
		}

		return [
			'years' => $out,
			'total' => $this->items->count($userId, $filter),
			'offset' => $offset,
			'limit' => $limit,
		];
	}

	/** Whether a kind of folder is shown newest first rather than in order. */
	private function showsLatest(string $kind): bool {
		return ($this->collections->category($kind)['show'] ?? 'entry') === 'latest';
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
		return $this->l10n->l('date', $timestamp, ['width' => 'long']);
	}

	// -- one folder ---------------------------------------------------------

	/**
	 * Everything in one folder, in the order it should be watched.
	 *
	 * @return array<string, mixed>
	 */
	public function folderView(string $userId, string $path, int $limit = 500, int $offset = 0): array {
		$folders = $this->collections->build($userId);
		$watched = $this->progress->allFor($userId);
		$folder = $folders[$path] ?? null;
		if ($folder === null) {
			return ['path' => $path, 'name' => basename($path), 'items' => [], 'total' => 0, 'children' => []];
		}
		$ids = array_map(static fn ($row) => $row['fileId'], $folder['items']);
		if ($this->showsLatest((string)$folder['kind'])) {
			$ids = $this->collections->latest($folder, count($ids));
		}
		$reasons = [];
		if (($folder['entry']['fileId'] ?? null) !== null) {
			$reasons[$folder['entry']['fileId']] = $folder['entry']['reason'];
		}

		// Folders sitting inside this one, so a course split into sections can
		// be walked through rather than only searched.
		$children = [];
		$prefix = $path === '' ? '' : $path . '/';
		foreach ($folders as $candidate) {
			if ($candidate['path'] === $path || ($prefix !== '' && !str_starts_with((string)$candidate['path'], $prefix))) {
				continue;
			}
			$relative = $prefix === '' ? (string)$candidate['path'] : substr((string)$candidate['path'], strlen($prefix));
			if ($relative === '' || str_contains($relative, '/')) {
				continue;
			}
			$children[] = [
				'path' => $candidate['path'],
				'name' => basename((string)$candidate['path']),
				'kind' => $candidate['kind'],
				'count' => $candidate['count'],
			];
		}
		usort($children, static fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

		return [
			'path' => $path,
			'name' => $path === '' ? $this->l10n->t('Top level') : basename($path),
			'parent' => $folder['parent'],
			'kind' => $folder['kind'],
			'total' => count($ids),
			'children' => $children,
			'entry' => $folder['entry'],
			'items' => $this->cards($userId, array_slice($ids, $offset, $limit), $folders, $watched, $reasons),
		];
	}
}
