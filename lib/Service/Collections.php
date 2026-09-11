<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Service;

use OCA\VideoGallery\Db\ItemMapper;
use OCA\VideoGallery\Db\ProgressMapper;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * The folders a library is really made of, and what to show for each.
 *
 * A list of videos is not a library. People keep a course in one folder, a
 * holiday in another, and whatever the phone put there in a third, and those
 * three want quite different treatment. A course wants one card that takes you
 * to the part you had reached; a folder of phone clips wants the newest few;
 * a folder of odds and ends wants whatever is most recent.
 *
 * Everything here is worked out from one query's worth of skeleton — which
 * folder each video is in, what it is called, when it happened — because a
 * library with two hundred folders cannot afford a query each.
 */
class Collections {
	/** What a folder is called when nothing else fits. */
	public const OTHER = 'other';

	private ICache $cache;

	public function __construct(
		private ItemMapper $items,
		private ProgressMapper $progress,
		private Config $config,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed('videogallery-collections');
	}

	/**
	 * Every folder holding videos, described.
	 *
	 * @return array<string, array<string, mixed>> keyed by folder path
	 */
	public function build(string $userId): array {
		// Paging through the timeline asks for this on every page, and it is the
		// same answer each time within a minute of itself.
		$key = 'shape-' . $userId;
		$cached = $this->cache->get($key);
		if (is_array($cached)) {
			return $this->rehydrate($cached, $userId);
		}
		$folders = $this->assemble($userId);
		$this->cache->set($key, $this->dehydrate($folders), 90);
		return $folders;
	}

	public function forget(string $userId): void {
		$this->cache->remove('shape-' . $userId);
	}

	/** After a change to the rules, nobody's arrangement is right any more. */
	public function forgetAll(): void {
		$this->cache->clear();
	}

	/**
	 * Progress entities do not survive a trip through the cache, and they are
	 * the one part of this that changes minute by minute anyway, so the shape of
	 * the library is kept and what has been watched is read afresh.
	 *
	 * @param array<string, array<string, mixed>> $folders
	 * @return array<string, array<string, mixed>>
	 */
	private function dehydrate(array $folders): array {
		foreach ($folders as &$folder) {
			unset($folder['unfinished'], $folder['entry'], $folder['watchedCount']);
		}
		return $folders;
	}

	/**
	 * @param array<string, array<string, mixed>> $folders
	 * @return array<string, array<string, mixed>>
	 */
	private function rehydrate(array $folders, string $userId): array {
		$watched = $this->progress->allFor($userId);
		foreach ($folders as &$folder) {
			$folder['unfinished'] = [];
			$folder['watchedCount'] = 0;
			foreach ($folder['items'] as $row) {
				$seen = $watched[$row['fileId']] ?? null;
				if ($seen === null) {
					continue;
				}
				$folder['watchedCount']++;
				if ($seen->getFinished() !== 1 && $seen->getPositionMs() > 15000) {
					$folder['unfinished'][$row['fileId']] = $seen->getUpdatedAt();
				}
			}
			$folder['entry'] = $this->entryPoint($folder, $watched);
		}
		return $folders;
	}

	/** @return array<string, array<string, mixed>> */
	private function assemble(string $userId): array {
		$skeleton = $this->items->skeleton($userId);
		$watched = $this->progress->allFor($userId);

		// Compiled once rather than per file; a bad expression from the settings
		// page is dropped rather than allowed to break every page in the app.
		$patterns = [];
		foreach ($this->categories() as $category) {
			$pattern = $category['namePattern'];
			if ($pattern === '') {
				continue;
			}
			$candidate = '/' . str_replace('/', '\\/', $pattern) . '/iu';
			if (@preg_match($candidate, '') !== false) {
				$patterns[$category['id']] = $candidate;
			}
		}

		$folders = [];
		foreach ($skeleton as $row) {
			$folder = $row['folder'];
			if (!isset($folders[$folder])) {
				$folders[$folder] = [
					'path' => $folder,
					'name' => $folder === '' ? '' : basename($folder),
					'parent' => $folder === '' ? '' : trim(dirname($folder), '.'),
					'items' => [],
					'count' => 0,
					'latest' => 0,
					'patternHits' => [],
					'numbered' => 0,
					'watchedCount' => 0,
					'unfinished' => [],
				];
			}
			$folders[$folder]['items'][] = $row;
			$folders[$folder]['count']++;
			$folders[$folder]['latest'] = max($folders[$folder]['latest'], $row['takenAt'], $row['mtime']);

			$stem = pathinfo($row['name'], PATHINFO_FILENAME);
			foreach ($patterns as $categoryId => $pattern) {
				if (preg_match($pattern, $row['name']) === 1) {
					$folders[$folder]['patternHits'][$categoryId] =
						($folders[$folder]['patternHits'][$categoryId] ?? 0) + 1;
				}
			}
			// A number at the front, which is how parts of something are named.
			// Merely containing a digit proves nothing: dates, resolutions and
			// camera counters all put digits in names that are not sequences.
			if (preg_match('/^\s*(?:\d{1,3}|[a-z]{1,12}[ _-]\d{1,3})\b/iu', $stem) === 1) {
				$folders[$folder]['numbered']++;
			}

			$seen = $watched[$row['fileId']] ?? null;
			if ($seen !== null) {
				$folders[$folder]['watchedCount']++;
				if ($seen->getFinished() !== 1 && $seen->getPositionMs() > 15000) {
					$folders[$folder]['unfinished'][$row['fileId']] = $seen->getUpdatedAt();
				}
			}
		}

		foreach ($folders as $path => &$folder) {
			// In the order a person would put them, so "part 9" comes before
			// "part 10" rather than after it.
			usort($folder['items'], static fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
			$folder['kind'] = $this->classify($path, $folder);
			$folder['entry'] = $this->entryPoint($folder, $watched);
		}
		unset($folder);

		return $folders;
	}

	/**
	 * The kinds of thing a folder may hold, as the administrator has defined
	 * them, with the catch-all on the end.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function categories(): array {
		$out = [];
		foreach ($this->config->getArray('categories') as $entry) {
			if (!is_array($entry) || ($entry['id'] ?? '') === '') {
				continue;
			}
			$out[] = [
				'id' => (string)$entry['id'],
				'label' => (string)($entry['label'] ?? $entry['id']),
				'words' => array_values(array_filter(array_map('strval', (array)($entry['words'] ?? [])))),
				'namePattern' => (string)($entry['namePattern'] ?? ''),
				'show' => ($entry['show'] ?? 'entry') === 'latest' ? 'latest' : 'entry',
				'sequence' => (bool)($entry['sequence'] ?? false),
			];
		}
		$out[] = [
			'id' => self::OTHER,
			'label' => $this->config->getString('other_label') ?: 'Folders',
			'words' => [],
			'namePattern' => '',
			'show' => 'entry',
			'sequence' => false,
		];
		return $out;
	}

	/** @return array<string, mixed>|null */
	public function category(string $id): ?array {
		foreach ($this->categories() as $category) {
			if ($category['id'] === $id) {
				return $category;
			}
		}
		return null;
	}

	/**
	 * What kind of folder this is.
	 *
	 * The name is taken at its word first, because somebody who calls a folder
	 * "Curs Laravel" has told us exactly what it is. Failing that, the shape of
	 * what is inside speaks: files named the way a phone names them came off a
	 * phone, and files numbered from one upwards are parts of something.
	 *
	 * The rules themselves are settings, since everybody names things
	 * differently and in their own language.
	 *
	 * @param array<string, mixed> $folder
	 */
	public function classify(string $path, array $folder): string {
		$haystack = mb_strtolower($path);
		$count = max(1, (int)$folder['count']);

		foreach ($this->categories() as $category) {
			if ($category['id'] === self::OTHER) {
				continue;
			}
			foreach ($category['words'] as $word) {
				$word = mb_strtolower(trim($word));
				if ($word !== '' && str_contains($haystack, $word)) {
					return $category['id'];
				}
			}
			$pattern = $category['namePattern'];
			if ($pattern !== '' && isset($folder['patternHits'][$category['id']])
				&& $folder['patternHits'][$category['id']] / $count > 0.6) {
				return $category['id'];
			}
		}

		// Plainly numbered parts, whatever they are called.
		if ($count >= $this->config->getInt('series_minimum') && $folder['numbered'] / $count >= 0.7) {
			$fallback = $this->config->getString('sequence_category');
			if ($fallback !== '' && $this->category($fallback) !== null) {
				return $fallback;
			}
		}
		return self::OTHER;
	}

	/**
	 * Where to pick a collection up.
	 *
	 * Somewhere in the middle of watching is the most useful answer there is, so
	 * it wins. Failing that, the first part nobody has started — which for a
	 * course half watched is the next lecture, and for one never opened is the
	 * beginning. A collection watched all the way through goes back to its
	 * start, since there is nothing else to offer.
	 *
	 * @param array<string, mixed> $folder
	 * @param array<int, \OCA\VideoGallery\Db\Progress> $watched
	 * @return array{fileId: int, reason: string}|null
	 */
	private function entryPoint(array $folder, array $watched): ?array {
		if ($folder['items'] === []) {
			return null;
		}
		if ($folder['unfinished'] !== []) {
			// The most recently left off, if several were started.
			arsort($folder['unfinished']);
			return ['fileId' => (int)array_key_first($folder['unfinished']), 'reason' => 'resume'];
		}
		foreach ($folder['items'] as $row) {
			$seen = $watched[$row['fileId']] ?? null;
			if ($seen === null || $seen->getFinished() !== 1) {
				$started = $folder['watchedCount'] > 0;
				return ['fileId' => $row['fileId'], 'reason' => $started ? 'next' : 'first'];
			}
		}
		return ['fileId' => $folder['items'][0]['fileId'], 'reason' => 'again'];
	}

	/**
	 * The newest few from a folder, for the collections where order means
	 * nothing and recency means everything.
	 *
	 * @param array<string, mixed> $folder
	 * @return list<int>
	 */
	public function latest(array $folder, int $limit): array {
		$rows = $folder['items'];
		usort($rows, static fn ($a, $b) => max($b['takenAt'], $b['mtime']) <=> max($a['takenAt'], $a['mtime']));
		return array_map(static fn ($row) => $row['fileId'], array_slice($rows, 0, $limit));
	}

	/**
	 * The first few from a collection, starting where it should be picked up.
	 *
	 * @param array<string, mixed> $folder
	 * @return list<int>
	 */
	public function fromEntry(array $folder, int $limit): array {
		$ids = array_map(static fn ($row) => $row['fileId'], $folder['items']);
		$entry = $folder['entry']['fileId'] ?? null;
		$at = $entry === null ? false : array_search($entry, $ids, true);
		if ($at === false) {
			return array_slice($ids, 0, $limit);
		}
		// Begin at the part that matters, not at part one — but a course resumed
		// near its end would then show two cards and a gap, so the row is filled
		// back out with what came before it.
		$start = (int)$at;
		if (count($ids) - $start < $limit) {
			$start = max(0, count($ids) - $limit);
		}
		return array_slice($ids, $start, $limit);
	}
}
