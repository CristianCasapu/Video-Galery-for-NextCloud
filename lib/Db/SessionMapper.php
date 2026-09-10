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
 * @template-extends QBMapper<Session>
 */
class SessionMapper extends QBMapper {
	public const TABLE = 'videogallery_sessions';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, Session::class);
	}

	public function findByToken(string $token): ?Session {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<Session> */
	public function live(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->in('state', $qb->createNamedParameter([Session::STARTING, Session::RUNNING], IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('created_at', 'ASC');
		return $this->findEntities($qb);
	}

	/** Sessions that are actually holding an encoder slot right now. */
	public function countActiveEncoders(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))->from($this->getTableName())
			->where($qb->expr()->in('state', $qb->createNamedParameter([Session::STARTING, Session::RUNNING], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->gt('pid', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$total = (int)$result->fetchOne();
		$result->closeCursor();
		return $total;
	}

	/** @return list<Session> */
	public function forUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntities($qb);
	}

	/**
	 * Sessions nobody has asked about lately, or that have simply run too long.
	 *
	 * @return list<Session>
	 */
	public function expired(int $ttlSeconds, int $maxLifeSeconds): array {
		$now = time();
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->orX(
				$qb->expr()->lt('last_seen', $qb->createNamedParameter($now - $ttlSeconds, IQueryBuilder::PARAM_INT)),
				$qb->expr()->lt('created_at', $qb->createNamedParameter($now - $maxLifeSeconds, IQueryBuilder::PARAM_INT)),
			));
		return $this->findEntities($qb);
	}

	/** @return list<Session> every session, live or not */
	public function all(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());
		return $this->findEntities($qb);
	}

	public function touch(string $token): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('last_seen', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));
		$qb->executeStatement();
	}

	public function deleteForUser(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $qb->executeStatement();
	}
}
