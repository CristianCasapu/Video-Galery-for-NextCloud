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
 * @template-extends QBMapper<Asset>
 */
class AssetMapper extends QBMapper {
	public const TABLE = 'videogallery_assets';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, Asset::class);
	}

	public function find(int $fileId, string $kind): ?Asset {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<Asset> */
	public function forFile(int $fileId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	/** @return list<Asset> */
	public function all(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());
		return $this->findEntities($qb);
	}

	/**
	 * The least recently used assets, for trimming the cache back under its limit.
	 *
	 * @return list<Asset>
	 */
	public function coldest(int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('last_used', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/** @return list<Asset> assets untouched for longer than the given age */
	public function olderThan(int $seconds, int $limit = 1000): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->lt('last_used', $qb->createNamedParameter(time() - $seconds, IQueryBuilder::PARAM_INT)))
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	public function totalSize(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->sum('size', 'bytes'))->from($this->getTableName());
		$result = $qb->executeQuery();
		$bytes = (int)$result->fetchOne();
		$result->closeCursor();
		return $bytes;
	}

	public function touch(int $fileId, string $kind): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('last_used', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)));
		$qb->executeStatement();
	}

	public function deleteByFileId(int $fileId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}

	public function deleteForUser(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $qb->executeStatement();
	}

	/** Every relative path we believe is on disk, as a set. */
	public function knownPaths(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('rel_path')->from($this->getTableName());
		$result = $qb->executeQuery();
		$paths = [];
		while ($row = $result->fetch()) {
			$paths[(string)$row['rel_path']] = true;
		}
		$result->closeCursor();
		return $paths;
	}
}
