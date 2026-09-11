<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Service;

use OCA\VideoGallery\Db\Item;
use OCA\VideoGallery\Db\ItemMapper;
use OCP\Share\IShare;

/**
 * Videos that are meant to be watched in order.
 *
 * A course, a lecture series, a set of episodes: dozens of files in one folder,
 * numbered, each carrying on where the last stopped. Treating those as a shelf
 * of unrelated clips makes watching one of them a chore — you finish a part and
 * have to go and find the next.
 *
 * There is no metadata to lean on here. What there is, is the way people
 * actually name such things: the files sit together in a folder and their names
 * sort into the right order. That is enough, provided the sorting is done the
 * way a person would do it — "9" before "10", not after, which is where a plain
 * alphabetical sort falls down and takes the whole idea with it.
 */
class Series {
	public function __construct(
		private ItemMapper $items,
		private Config $config,
		private Collections $collections,
		private ShareAccess $access,
	) {
	}

	/**
	 * Whether one part of this follows another.
	 *
	 * Asked of the kind of folder it is in, which the administrator defines,
	 * rather than guessed afresh: a folder called "Season 2" is a sequence
	 * whatever its files are named, and a folder of holiday clips is not one
	 * however neatly they are numbered.
	 */
	private function isSequence(string $userId, Item $item): bool {
		$folders = $this->collections->build($userId);
		$path = trim(dirname($item->getPath()), '.');
		$kind = (string)($folders[$path]['kind'] ?? Collections::OTHER);
		return (bool)($this->collections->category($kind)['sequence'] ?? false);
	}

	/**
	 * Everything in the same folder, in the order a person would put it.
	 *
	 * @return list<Item>
	 */
	public function siblings(string $userId, Item $item): array {
		$folder = trim(dirname($item->getPath()), '.');
		$filter = $folder === '' ? [] : ['folder' => $folder];
		$found = $this->items->search($userId, $filter + ['sort' => 'name_asc'], 500);
		if ($folder === '') {
			// The top of the account is not a folder anybody organises a course
			// into; only its own immediate files count as neighbours.
			$found = array_values(array_filter($found, static fn (Item $candidate) => !str_contains($candidate->getPath(), '/')));
		}
		usort($found, static fn (Item $a, Item $b) => strnatcasecmp($a->getName(), $b->getName()));
		return $found;
	}

	/**
	 * Whether a folder is a sequence rather than a heap.
	 *
	 * Enough files to be a set, and names that differ in a way that orders them
	 * — most often a number. A folder of holiday clips from a phone is not a
	 * course, and starting the next one automatically would be an intrusion.
	 *
	 * @param list<Item> $siblings
	 */
	public function looksLikeSeries(array $siblings): bool {
		if (count($siblings) < $this->config->getInt('series_minimum')) {
			return false;
		}
		$numbered = 0;
		foreach ($siblings as $item) {
			// A number somewhere in the name, which is how parts are marked
			// whether they are called "01", "Part 3" or "Lecture 12".
			if (preg_match('/\d/', pathinfo($item->getName(), PATHINFO_FILENAME)) === 1) {
				$numbered++;
			}
		}
		return $numbered >= count($siblings) * 0.75;
	}

	/**
	 * What follows this one, if anything does.
	 *
	 * @return array<string, mixed>|null
	 */
	public function next(string $userId, Item $item): ?array {
		$siblings = $this->siblings($userId, $item);
		$position = $this->positionOf($siblings, $item);
		if ($position === null || !isset($siblings[$position + 1])) {
			return null;
		}
		$isSequence = $this->isSequence($userId, $item) && count($siblings) >= $this->config->getInt('series_minimum');
		return $this->describe($siblings[$position + 1], $siblings, $position + 1, $isSequence);
	}

	/** The same, for somebody watching through a share link. */
	public function nextInShare(IShare $share, Item $item): ?array {
		$next = $this->next($this->access->ownerId($share), $item);
		if ($next === null) {
			return null;
		}
		// Only if it is inside the share as well: a folder share must not become
		// a way to walk out of it and into the next folder along.
		if ($this->access->item($share, (int)$next['item']['fileId']) === null) {
			return null;
		}
		$root = $this->access->rootPath($share);
		if ($root !== '' && str_starts_with((string)$next['item']['path'], $root . '/')) {
			$next['item']['path'] = substr((string)$next['item']['path'], strlen($root) + 1);
		}
		return $next;
	}

	/**
	 * Where the viewer is in the sequence, and what is on either side.
	 *
	 * @return array<string, mixed>
	 */
	public function context(string $userId, Item $item): array {
		$siblings = $this->siblings($userId, $item);
		$position = $this->positionOf($siblings, $item);
		$isSeries = $this->isSequence($userId, $item) && count($siblings) >= $this->config->getInt('series_minimum');
		return [
			'isSeries' => $isSeries,
			'title' => basename(trim(dirname($item->getPath()), '.')) ?: '',
			'position' => $position === null ? null : $position + 1,
			'total' => count($siblings),
			'autoplay' => $isSeries && $this->config->getBool('autoplay_next'),
			'delay' => $this->config->getInt('autoplay_delay'),
			'previous' => ($position !== null && isset($siblings[$position - 1]))
				? $this->describe($siblings[$position - 1], $siblings, $position - 1, $isSeries)
				: null,
			'next' => ($position !== null && isset($siblings[$position + 1]))
				? $this->describe($siblings[$position + 1], $siblings, $position + 1, $isSeries)
				: null,
		];
	}

	/** @param list<Item> $siblings */
	private function positionOf(array $siblings, Item $item): ?int {
		foreach ($siblings as $index => $candidate) {
			if ($candidate->getFileId() === $item->getFileId()) {
				return $index;
			}
		}
		return null;
	}

	/**
	 * @param list<Item> $siblings
	 * @return array<string, mixed>
	 */
	private function describe(Item $item, array $siblings, int $index, bool $isSequence): array {
		return [
			'item' => $item->jsonSerialize(),
			'position' => $index + 1,
			'total' => count($siblings),
			'autoplay' => $isSequence && $this->config->getBool('autoplay_next'),
			'delay' => $this->config->getInt('autoplay_delay'),
		];
	}
}
