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
 * @template-extends QBMapper<Recipe>
 */
class RecipeMapper extends QBMapper {
	public const TABLE = 'videogallery_recipes';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, Recipe::class);
	}

	public function find(string $signature, string $mode, string $profile): ?Recipe {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('signature', $qb->createNamedParameter($signature)))
			->andWhere($qb->expr()->eq('mode', $qb->createNamedParameter($mode)))
			->andWhere($qb->expr()->eq('profile', $qb->createNamedParameter($profile)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Everything known about one kind of situation, most proven first.
	 *
	 * @return list<Recipe>
	 */
	public function forSignature(string $signature): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('signature', $qb->createNamedParameter($signature)))
			->orderBy('successes', 'DESC')
			->addOrderBy('failures', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<Recipe> */
	public function all(int $limit = 500): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('updated_at', 'DESC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/** Drop what has not been useful for a long time. */
	public function prune(int $olderThanSeconds): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('updated_at', $qb->createNamedParameter(time() - $olderThanSeconds, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}

	public function clear(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName());
		return $qb->executeStatement();
	}
}
