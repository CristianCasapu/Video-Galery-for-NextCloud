<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Item>
 */
class ItemMapper extends QBMapper {
	public const TABLE = 'videogallery_items';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, Item::class);
	}

	public function find(string $userId, int $fileId): ?Item {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * The library, filtered and paged.
	 *
	 * @param array<string, mixed> $filter
	 * @return list<Item>
	 */
	public function search(string $userId, array $filter = [], int $limit = 200, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());
		$this->applyFilter($qb, $userId, $filter);

		$sort = (string)($filter['sort'] ?? 'taken_desc');
		match ($sort) {
			'taken_asc' => $qb->orderBy('taken_at', 'ASC')->addOrderBy('id', 'ASC'),
			'added_desc' => $qb->orderBy('mtime', 'DESC')->addOrderBy('id', 'DESC'),
			'added_asc' => $qb->orderBy('mtime', 'ASC')->addOrderBy('id', 'ASC'),
			'name_asc' => $qb->orderBy('name', 'ASC')->addOrderBy('id', 'ASC'),
			'name_desc' => $qb->orderBy('name', 'DESC')->addOrderBy('id', 'DESC'),
			'size_desc' => $qb->orderBy('size', 'DESC')->addOrderBy('id', 'DESC'),
			'duration_desc' => $qb->orderBy('duration_ms', 'DESC')->addOrderBy('id', 'DESC'),
			'duration_asc' => $qb->orderBy('duration_ms', 'ASC')->addOrderBy('id', 'ASC'),
			default => $qb->orderBy('taken_at', 'DESC')->addOrderBy('id', 'DESC'),
		};
		$qb->setMaxResults(max(1, min(2000, $limit)))->setFirstResult(max(0, $offset));
		return $this->findEntities($qb);
	}

	/** @param array<string, mixed> $filter */
	public function count(string $userId, array $filter = []): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))->from($this->getTableName());
		$this->applyFilter($qb, $userId, $filter);
		$result = $qb->executeQuery();
		$total = (int)$result->fetchOne();
		$result->closeCursor();
		return $total;
	}

	/** @param array<string, mixed> $filter */
	private function applyFilter(IQueryBuilder $qb, string $userId, array $filter): void {
		$qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		if (($filter['includeFailed'] ?? false) !== true) {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('ok')));
		}
		if (!empty($filter['folder'])) {
			$folder = rtrim((string)$filter['folder'], '/');
			$qb->andWhere($qb->expr()->like('path', $qb->createNamedParameter(
				$this->db->escapeLikeParameter($folder) . '/%',
			)));
			if (($filter['directOnly'] ?? false) === true) {
				// Only what sits in this folder itself, not in the folders below it.
				$qb->andWhere($qb->expr()->notLike('path', $qb->createNamedParameter(
					$this->db->escapeLikeParameter($folder) . '/%/%',
				)));
			}
		} elseif (($filter['directOnly'] ?? false) === true) {
			$qb->andWhere($qb->expr()->notLike('path', $qb->createNamedParameter('%/%')));
		}
		if (!empty($filter['query'])) {
			$needle = '%' . $this->db->escapeLikeParameter((string)$filter['query']) . '%';
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->like($qb->func()->lower('name'), $qb->createNamedParameter(mb_strtolower($needle))),
				$qb->expr()->like($qb->func()->lower('path'), $qb->createNamedParameter(mb_strtolower($needle))),
			));
		}
		if (!empty($filter['from'])) {
			$qb->andWhere($qb->expr()->gte('taken_at', $qb->createNamedParameter((int)$filter['from'], IQueryBuilder::PARAM_INT)));
		}
		if (!empty($filter['to'])) {
			$qb->andWhere($qb->expr()->lt('taken_at', $qb->createNamedParameter((int)$filter['to'], IQueryBuilder::PARAM_INT)));
		}
		if (!empty($filter['minDuration'])) {
			$qb->andWhere($qb->expr()->gte('duration_ms', $qb->createNamedParameter((int)$filter['minDuration'] * 1000, IQueryBuilder::PARAM_INT)));
		}
		if (!empty($filter['maxDuration'])) {
			$qb->andWhere($qb->expr()->lt('duration_ms', $qb->createNamedParameter((int)$filter['maxDuration'] * 1000, IQueryBuilder::PARAM_INT)));
		}
		if (!empty($filter['codec'])) {
			$qb->andWhere($qb->expr()->eq('vcodec', $qb->createNamedParameter((string)$filter['codec'])));
		}
		if (($filter['hdr'] ?? null) === true) {
			$qb->andWhere($qb->expr()->eq('hdr', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		}
		if (!empty($filter['minHeight'])) {
			$qb->andWhere($qb->expr()->gte('height', $qb->createNamedParameter((int)$filter['minHeight'], IQueryBuilder::PARAM_INT)));
		}
		if (!empty($filter['fileIds']) && is_array($filter['fileIds'])) {
			$qb->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter(
				array_map('intval', $filter['fileIds']),
				IQueryBuilder::PARAM_INT_ARRAY,
			)));
		}
	}

	/**
	 * How many videos fall on each day, newest first. This is what the timeline
	 * scrollbar is built from, and it must stay cheap: one grouped query, no rows.
	 *
	 * @return list<array{day: string, count: int}>
	 */
	public function timeline(string $userId, string $granularity = 'day'): array {
		$format = match ($granularity) {
			'year' => '%Y',
			'month' => '%Y-%m',
			default => '%Y-%m-%d',
		};
		$qb = $this->db->getQueryBuilder();
		$platform = $this->db->getDatabasePlatform();
		$driver = strtolower((new \ReflectionClass($platform))->getShortName());

		// Grouping by a formatted date is the one place a portable query builder
		// has nothing to offer, so each engine gets its own expression.
		if (str_contains($driver, 'postgre')) {
			$pattern = match ($granularity) {
				'year' => 'YYYY',
				'month' => 'YYYY-MM',
				default => 'YYYY-MM-DD',
			};
			$expr = "to_char(to_timestamp(taken_at), '" . $pattern . "')";
		} elseif (str_contains($driver, 'sqlite')) {
			$pattern = match ($granularity) {
				'year' => '%Y',
				'month' => '%Y-%m',
				default => '%Y-%m-%d',
			};
			$expr = "strftime('" . $pattern . "', taken_at, 'unixepoch')";
		} else {
			$expr = "DATE_FORMAT(FROM_UNIXTIME(taken_at), '" . $format . "')";
		}

		$qb->selectAlias($qb->createFunction($expr), 'bucket')
			->selectAlias($qb->func()->count('*'), 'total')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('ok')))
			->groupBy('bucket')
			->orderBy('bucket', 'DESC');

		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$out[] = ['day' => (string)$row['bucket'], 'count' => (int)$row['total']];
		}
		$result->closeCursor();
		return $out;
	}

	/**
	 * Folders holding videos, with a count each.
	 *
	 * @return list<array{path: string, count: int}>
	 */
	public function folders(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('path')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('ok')));
		$result = $qb->executeQuery();
		$counts = [];
		while ($row = $result->fetch()) {
			$folder = trim(dirname((string)$row['path']), '.');
			$counts[$folder] = ($counts[$folder] ?? 0) + 1;
		}
		$result->closeCursor();
		arsort($counts);
		$out = [];
		foreach ($counts as $path => $count) {
			$out[] = ['path' => (string)$path, 'count' => $count];
		}
		return $out;
	}

	/**
	 * Just enough about every video in an account to arrange them: which folder
	 * each is in, what it is called, and when it happened.
	 *
	 * One query rather than one per folder. Grouping a library into collections,
	 * deciding which are courses, and working out where to resume each of them
	 * all need the same handful of columns, and a library of six thousand videos
	 * is a few hundred kilobytes of them. Asking the database once and thinking
	 * in memory is far quicker than two hundred round trips.
	 *
	 * @return list<array{fileId: int, name: string, path: string, folder: string, takenAt: int, mtime: int, duration: int}>
	 */
	public function skeleton(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('file_id', 'name', 'path', 'taken_at', 'mtime', 'duration_ms')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('ok')));
		$result = $qb->executeQuery();
		$rows = [];
		while ($row = $result->fetch()) {
			$path = (string)$row['path'];
			$slash = strrpos($path, '/');
			$rows[] = [
				'fileId' => (int)$row['file_id'],
				'name' => (string)$row['name'],
				'path' => $path,
				'folder' => $slash === false ? '' : substr($path, 0, $slash),
				'takenAt' => (int)$row['taken_at'],
				'mtime' => (int)$row['mtime'],
				'duration' => (int)round(((int)$row['duration_ms']) / 1000),
			];
		}
		$result->closeCursor();
		return $rows;
	}

	/**
	 * The folders directly inside one folder, with how many videos each holds
	 * altogether, counting everything below it.
	 *
	 * Read from the paths alone rather than from the rows, so a folder with
	 * thousands of videos in it costs the same as one with three.
	 *
	 * @return list<array{name: string, count: int}>
	 */
	public function subfolders(string $userId, string $scope): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('path')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('ok')));
		$prefix = '';
		if ($scope !== '') {
			$prefix = rtrim($scope, '/') . '/';
			$qb->andWhere($qb->expr()->like('path', $qb->createNamedParameter(
				$this->db->escapeLikeParameter($prefix) . '%',
			)));
		}
		$result = $qb->executeQuery();
		$counts = [];
		while ($row = $result->fetch()) {
			$relative = $prefix === '' ? (string)$row['path'] : substr((string)$row['path'], strlen($prefix));
			$slash = strpos($relative, '/');
			if ($slash === false) {
				continue;
			}
			$name = substr($relative, 0, $slash);
			$counts[$name] = ($counts[$name] ?? 0) + 1;
		}
		$result->closeCursor();
		uksort($counts, 'strnatcasecmp');
		$out = [];
		foreach ($counts as $name => $count) {
			$out[] = ['name' => (string)$name, 'count' => $count];
		}
		return $out;
	}

	/**
	 * Items still needing a probe, oldest first.
	 *
	 * @return list<Item>
	 */
	public function pending(int $limit, ?string $userId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->in('status', $qb->createNamedParameter(['pending', 'stale'], IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('indexed_at', 'ASC')
			->setMaxResults($limit);
		if ($userId !== null) {
			$qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		}
		return $this->findEntities($qb);
	}

	/**
	 * Indexed items that still lack one of their cached assets, newest first:
	 * the ones a viewer is most likely to hover over next.
	 *
	 * @return list<Item>
	 */
	public function missingAssets(int $flag, int $limit, ?string $userId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('ok')))
			->andWhere($qb->expr()->neq(
				$qb->createFunction('(' . $qb->getColumnName('assets') . ' & ' . $flag . ')'),
				$qb->createNamedParameter($flag, IQueryBuilder::PARAM_INT),
			))
			->andWhere($qb->expr()->gt('duration_ms', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('taken_at', 'DESC')
			->setMaxResults($limit);
		if ($userId !== null) {
			$qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		}
		return $this->findEntities($qb);
	}

	/**
	 * The accounts that have anything in the library, busiest first.
	 *
	 * @return list<string>
	 */
	public function users(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')->selectAlias($qb->func()->count('*'), 'total')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('ok')))
			->groupBy('user_id')
			->orderBy('total', 'DESC');
		$result = $qb->executeQuery();
		$users = [];
		while ($row = $result->fetch()) {
			$users[] = (string)$row['user_id'];
		}
		$result->closeCursor();
		return $users;
	}

	/** @return list<Item> */
	public function byFileId(int $fileId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	/** File ids this user already has indexed, as a lookup set. */
	public function knownFileIds(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('file_id', 'mtime', 'size', 'probe_version', 'status')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$known = [];
		while ($row = $result->fetch()) {
			$known[(int)$row['file_id']] = [
				'mtime' => (int)$row['mtime'],
				'size' => (int)$row['size'],
				'probe_version' => (int)$row['probe_version'],
				'status' => (string)$row['status'],
			];
		}
		$result->closeCursor();
		return $known;
	}

	public function deleteByFileId(int $fileId, ?string $userId = null): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		if ($userId !== null) {
			$qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		}
		return $qb->executeStatement();
	}

	public function deleteForUser(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $qb->executeStatement();
	}

	/** Mark every item stale so the indexer probes them again. */
	public function markAllStale(?string $userId = null): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter('stale'))
			->set('indexed_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT));
		if ($userId !== null) {
			$qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		}
		return $qb->executeStatement();
	}

	public function setAssetFlag(int $fileId, int $flag, bool $on): void {
		$qb = $this->db->getQueryBuilder();
		$expr = $on
			? $qb->getColumnName('assets') . ' | ' . $flag
			: $qb->getColumnName('assets') . ' & ~' . $flag;
		$qb->update($this->getTableName())
			->set('assets', $qb->createFunction($expr))
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/** @return array<string, int> a headline figure per statistic */
	public function stats(?string $userId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))
			->selectAlias($qb->func()->sum('size'), 'bytes')
			->selectAlias($qb->func()->sum('duration_ms'), 'ms')
			->from($this->getTableName());
		if ($userId !== null) {
			$qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		}
		$result = $qb->executeQuery();
		$row = $result->fetch() ?: [];
		$result->closeCursor();

		$byStatus = [];
		$qb2 = $this->db->getQueryBuilder();
		$qb2->select('status')->selectAlias($qb2->func()->count('*'), 'total')->from($this->getTableName());
		if ($userId !== null) {
			$qb2->where($qb2->expr()->eq('user_id', $qb2->createNamedParameter($userId)));
		}
		$qb2->groupBy('status');
		$result2 = $qb2->executeQuery();
		while ($r = $result2->fetch()) {
			$byStatus[(string)$r['status']] = (int)$r['total'];
		}
		$result2->closeCursor();

		return [
			'total' => (int)($row['total'] ?? 0),
			'bytes' => (int)($row['bytes'] ?? 0),
			'seconds' => (int)round(((int)($row['ms'] ?? 0)) / 1000),
			'ok' => $byStatus['ok'] ?? 0,
			'pending' => ($byStatus['pending'] ?? 0) + ($byStatus['stale'] ?? 0),
			'failed' => $byStatus['failed'] ?? 0,
			'excluded' => $byStatus['excluded'] ?? 0,
		];
	}
}
