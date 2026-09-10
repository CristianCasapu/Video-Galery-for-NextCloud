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
 * @template-extends QBMapper<Progress>
 */
class ProgressMapper extends QBMapper {
	public const TABLE = 'videogallery_progress';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, Progress::class);
	}

	public function find(string $userId, int $fileId): ?Progress {
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
	 * Started but not finished, most recent first: the "carry on watching" row.
	 *
	 * @return list<Progress>
	 */
	public function unfinished(string $userId, int $limit = 20): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('finished', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('position_ms', $qb->createNamedParameter(15000, IQueryBuilder::PARAM_INT)))
			->orderBy('updated_at', 'DESC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * @param list<int> $fileIds
	 * @return array<int, Progress> keyed by file id
	 */
	public function forFiles(string $userId, array $fileIds): array {
		if ($fileIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter(array_map('intval', $fileIds), IQueryBuilder::PARAM_INT_ARRAY)));
		$out = [];
		foreach ($this->findEntities($qb) as $entity) {
			$out[$entity->getFileId()] = $entity;
		}
		return $out;
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
}
