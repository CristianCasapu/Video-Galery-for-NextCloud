<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Service;

use OCA\VideoGallery\Db\Item;
use OCA\VideoGallery\Db\ItemMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\File;
use OCP\Files\IMimeTypeLoader;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Builds and maintains the list of videos in each account.
 *
 * Discovery and inspection are deliberately separate. Finding the files is a
 * single indexed query per mount, so a full sweep of a large account costs
 * almost nothing; opening each file with ffprobe is the slow part, and that
 * happens later, in the background, a batch at a time.
 */
class Indexer {
	public function __construct(
		private ItemMapper $mapper,
		private Probe $probe,
		private FileResolver $resolver,
		private Config $config,
		private IRootFolder $rootFolder,
		private IUserMountCache $mountCache,
		private IUserManager $userManager,
		private IMimeTypeLoader $mimeLoader,
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	/** A file that was looked at, understood, and deliberately left out. */
	public const EXCLUDED = 'excluded';

	public function isVideo(File $file): bool {
		if ($this->isMisfiled($file->getName())) {
			return false;
		}
		$mime = $file->getMimetype();
		if (str_starts_with($mime, 'video/')) {
			return true;
		}
		// Some containers are typed by extension alone on older installs.
		$extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
		return in_array($extension, ['mkv', 'avi', 'mov', 'wmv', 'flv', 'm2ts', 'mts', 'ts', 'vob', 'ogv', 'm4v', 'divx', 'rmvb', 'mpg', 'mpeg', '3gp', 'webm', 'mp4'], true);
	}

	/**
	 * Names that arrive here looking like video and are not.
	 *
	 * `.mts` is an AVCHD camera format, so a file ending in it is typed as video
	 * on sight — which sweeps up every TypeScript declaration file in every
	 * node_modules directory on the server. They are not videos, ffprobe finds
	 * nothing in them, and there is no reason to open thousands of them to
	 * discover that.
	 */
	private function isMisfiled(string $name): bool {
		$lower = strtolower($name);
		if (str_ends_with($lower, '.d.mts') || str_ends_with($lower, '.d.ts') || str_ends_with($lower, '.d.cts')) {
			return true;
		}
		return $this->isIgnoredName($name);
	}

	/**
	 * Names the administrator has said are not worth collecting.
	 *
	 * A film downloaded from anywhere often arrives beside a "sample" — thirty
	 * seconds of the middle of it, with the same name and none of the point.
	 * Matched anywhere in the name and without regard to case, because those
	 * files are named every way round.
	 */
	public function isIgnoredName(string $name): bool {
		$lower = mb_strtolower($name);
		foreach ($this->config->getArray('ignore_names') as $needle) {
			$needle = mb_strtolower(trim((string)$needle));
			if ($needle !== '' && str_contains($lower, $needle)) {
				return true;
			}
		}
		return false;
	}

	/** True when a path is one the administrator asked the app to leave alone. */
	public function isExcluded(string $path): bool {
		foreach ($this->config->getArray('excluded_paths') as $excluded) {
			$excluded = trim((string)$excluded, '/');
			if ($excluded === '') {
				continue;
			}
			if ($path === $excluded || str_starts_with($path, $excluded . '/')) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Note that a file needs inspecting. Cheap enough to call from a file hook.
	 */
	public function enqueue(File $file): void {
		$owner = $file->getOwner();
		$userId = $owner?->getUID();
		if ($userId === null) {
			return;
		}
		$path = $this->resolver->relativePath($userId, $file);
		if ($this->isExcluded($path)) {
			return;
		}
		$existing = $this->mapper->find($userId, $file->getId());
		if ($existing !== null) {
			$unchanged = $existing->getMtime() === $file->getMTime()
				&& $existing->getSize() === $file->getSize()
				&& $existing->getProbeVersion() === Probe::VERSION;
			$existing->setPath($path);
			$existing->setName($file->getName());
			$existing->setMtime($file->getMTime());
			$existing->setSize($file->getSize());
			if (!$unchanged) {
				$existing->setStatus('stale');
				$existing->setIndexedAt(0);
			}
			$this->mapper->update($existing);
			return;
		}
		$item = new Item();
		$item->setUserId($userId);
		$item->setFileId($file->getId());
		$item->setPath($path);
		$item->setName($file->getName());
		$item->setMimetype($file->getMimetype());
		$item->setSize($file->getSize());
		$item->setMtime($file->getMTime());
		$item->setTakenAt($file->getMTime());
		$item->setDateSource('mtime');
		$item->setStatus('pending');
		$item->setIndexedAt(0);
		try {
			$this->mapper->insert($item);
		} catch (\Throwable $e) {
			// A racing hook may have inserted the same row already.
			$this->logger->debug('Video Gallery could not queue a file: ' . $e->getMessage());
		}
	}

	public function relocate(File $file): void {
		$owner = $file->getOwner();
		$userId = $owner?->getUID();
		if ($userId === null) {
			return;
		}
		$item = $this->mapper->find($userId, $file->getId());
		if ($item === null) {
			$this->enqueue($file);
			return;
		}
		$path = $this->resolver->relativePath($userId, $file);
		if ($this->isExcluded($path)) {
			$this->mapper->delete($item);
			return;
		}
		$item->setPath($path);
		$item->setName($file->getName());
		$this->mapper->update($item);
	}

	public function forget(int $fileId): void {
		$this->mapper->deleteByFileId($fileId);
	}

	/**
	 * Bring one account's list of files in line with what is on disk.
	 *
	 * @return array{added: int, updated: int, removed: int, total: int}
	 */
	public function sync(string $userId): array {
		$stats = ['added' => 0, 'updated' => 0, 'removed' => 0, 'total' => 0];
		$found = $this->discover($userId);
		$known = $this->mapper->knownFileIds($userId);
		$stats['total'] = count($found);

		foreach ($found as $fileId => $row) {
			$existing = $known[$fileId] ?? null;
			if ($existing === null) {
				$item = new Item();
				$item->setUserId($userId);
				$item->setFileId($fileId);
				$item->setPath($row['path']);
				$item->setName($row['name']);
				$item->setMimetype($row['mimetype']);
				$item->setSize($row['size']);
				$item->setMtime($row['mtime']);
				$item->setTakenAt($row['mtime']);
				$item->setDateSource('mtime');
				$item->setStatus('pending');
				try {
					$this->mapper->insert($item);
					$stats['added']++;
				} catch (\Throwable) {
					// already there; nothing to do
				}
				continue;
			}
			$changed = $existing['mtime'] !== $row['mtime']
				|| $existing['size'] !== $row['size']
				|| $existing['probe_version'] !== Probe::VERSION;
			$item = $this->mapper->find($userId, $fileId);
			if ($item === null) {
				continue;
			}
			$pathChanged = $item->getPath() !== $row['path'];
			if (!$changed && !$pathChanged) {
				continue;
			}
			$item->setPath($row['path']);
			$item->setName($row['name']);
			$item->setMimetype($row['mimetype']);
			$item->setSize($row['size']);
			$item->setMtime($row['mtime']);
			if ($changed) {
				$item->setStatus('stale');
				$item->setIndexedAt(0);
			}
			$this->mapper->update($item);
			$stats['updated']++;
		}

		foreach (array_keys($known) as $fileId) {
			if (!isset($found[$fileId])) {
				$this->mapper->deleteByFileId($fileId, $userId);
				$stats['removed']++;
			}
		}
		$stats['removed'] += $this->dropNowExcluded($userId);
		return $stats;
	}

	/**
	 * Items already in the library that the rules would no longer collect.
	 *
	 * The rules can change after a file has been indexed — a minimum length
	 * raised, a word added to the ignore list — and a library that only applies
	 * them to new files would keep whatever it happened to gather first.
	 */
	private function dropNowExcluded(string $userId): int {
		$minimumMs = $this->config->getInt('min_duration_seconds') * 1000;
		$removed = 0;
		$offset = 0;
		$page = 1000;
		// Paged, because a library can hold far more than one query will return.
		while (true) {
	$batch = $this->mapper->search($userId, ['includeFailed' => true], $page, $offset);
			if ($batch === []) {
				break;
			}
			$kept = 0;
			foreach ($batch as $item) {
				// A name or a folder the rules reject is dropped outright:
				// discovery skips those, so nothing will find them again.
				if ($this->isIgnoredName($item->getName()) || $this->isExcluded($item->getPath())) {
					$this->mapper->deleteByFileId($item->getFileId(), $userId);
					$removed++;
					continue;
				}
				$known = $item->getDurationMs() > 0;
				$tooShort = $minimumMs > 0 && $known && $item->getDurationMs() < $minimumMs;
				if ($tooShort && $item->getStatus() !== self::EXCLUDED) {
					$item->setStatus(self::EXCLUDED);
					$item->setFailReason('shorter than the shortest video worth keeping');
					$this->mapper->update($item);
					$removed++;
					continue;
				}
				// The rule may have been relaxed since; something set aside that
				// now qualifies is simply allowed back.
				if (!$tooShort && $item->getStatus() === self::EXCLUDED && $known) {
					$item->setStatus('stale');
					$item->setFailReason(null);
					$this->mapper->update($item);
				}
				$kept++;
			}
			// Rows that went shift the window, so only what stayed advances it.
			$offset += $kept;
			if (count($batch) < $page) {
				break;
			}
		}
		return $removed;
	}

	/**
	 * Every video file visible to an account, straight from the file cache.
	 *
	 * @return array<int, array{path: string, name: string, size: int, mtime: int, mimetype: string}>
	 */
	public function discover(string $userId): array {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			return [];
		}
		$videoPart = $this->mimeLoader->getId('video');
		$found = [];
		$prefix = '/' . $userId . '/files/';

		foreach ($this->mountCache->getMountsForUser($user) as $mount) {
			$mountPoint = $mount->getMountPoint();
			// The home storage is mounted at /user/ and holds the Files tree under
			// files/; everything else is mounted somewhere inside it. Either shape
			// is wanted, and anything unrelated is not.
			if (!str_starts_with($mountPoint, $prefix) && !str_starts_with($prefix, $mountPoint)) {
				continue;
			}
			$storageId = $mount->getStorageId();
			$rootId = $mount->getRootId();
			$rootPath = $this->rootPath($storageId, $rootId);
			if ($rootPath === null) {
				continue;
			}
			$qb = $this->db->getQueryBuilder();
			$qb->select('fc.fileid', 'fc.path', 'fc.name', 'fc.size', 'fc.mtime', 'm.mimetype')
				->from('filecache', 'fc')
				->innerJoin('fc', 'mimetypes', 'm', $qb->expr()->eq('fc.mimetype', 'm.id'))
				->where($qb->expr()->eq('fc.storage', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('fc.mimepart', $qb->createNamedParameter($videoPart, IQueryBuilder::PARAM_INT)));
			if ($rootPath !== '') {
				$qb->andWhere($qb->expr()->like('fc.path', $qb->createNamedParameter(
					$this->db->escapeLikeParameter($rootPath) . '/%',
				)));
			}
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$internal = (string)$row['path'];
				$relative = $rootPath === '' ? $internal : ltrim(substr($internal, strlen($rootPath)), '/');
				// Build the path as the account sees it, then take off the leading
				// /user/files/. What is left over is the path shown in the app, and
				// anything outside that tree — versions, the recycle bin, app data —
				// falls away here because it does not start with the prefix.
				$full = rtrim($mountPoint, '/') . '/' . $relative;
				if (!str_starts_with($full, $prefix)) {
					continue;
				}
				$path = substr($full, strlen($prefix));
				if ($path === '' || $this->isExcluded($path) || $this->isMisfiled((string)$row['name'])) {
					continue;
				}
				$found[(int)$row['fileid']] = [
					'path' => $path,
					'name' => (string)$row['name'],
					'size' => max(0, (int)$row['size']),
					'mtime' => (int)$row['mtime'],
					'mimetype' => (string)$row['mimetype'],
				];
			}
			$result->closeCursor();
		}
		return $found;
	}

	private function rootPath(int $storageId, int $rootId): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('path')->from('filecache')
			->where($qb->expr()->eq('fileid', $qb->createNamedParameter($rootId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('storage', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$path = $result->fetchOne();
		$result->closeCursor();
		return $path === false ? null : (string)$path;
	}

	/**
	 * Open a queued file with ffprobe and fill in everything we know about it.
	 */
	public function inspect(Item $item): bool {
		$file = $this->resolver->getFile($item->getUserId(), $item->getFileId());
		if ($file === null) {
			$this->mapper->deleteByFileId($item->getFileId(), $item->getUserId());
			return false;
		}
		$resolved = $this->resolver->localPath($file);
		if ($resolved === null) {
			return $this->fail($item, 'the file could not be opened');
		}
		try {
			$data = $this->probe->inspect($resolved['path']);
		} finally {
			$this->resolver->release($resolved);
		}
		if ($data === null) {
			return $this->fail($item, 'ffprobe found no video stream');
		}

		// Too short to be anything: a stray frame, a recording that failed, the
		// half second a camera writes when a button is pressed by accident.
		$minimum = $this->config->getInt('min_duration_seconds');
		if ($minimum > 0 && $data['duration_ms'] < $minimum * 1000) {
			// Set aside rather than deleted. A deleted row is found again by the
			// very next sweep and opened again to reach the same conclusion; a
			// row marked as set aside is a decision the library remembers.
			$item->setDurationMs((int)$data['duration_ms']);
			$item->setStatus(self::EXCLUDED);
			$item->setFailReason('shorter than the shortest video worth keeping');
			$item->setProbeVersion(Probe::VERSION);
			$item->setIndexedAt(time());
			$this->mapper->update($item);
			return false;
		}

		$item->setContainer((string)$data['container']);
		$item->setDurationMs((int)$data['duration_ms']);
		// Matroska and a few others often carry no overall bitrate, and a figure
		// of zero is worse than an estimate: it is shown to people, and it is
		// what the playback decision weighs against the measured connection.
		$bitrate = (int)$data['bitrate'];
		if ($bitrate <= 0 && $data['duration_ms'] > 500 && $file->getSize() > 0) {
			$bitrate = (int)round(($file->getSize() * 8) / ($data['duration_ms'] / 1000));
		}
		$item->setBitrate($bitrate);
		$item->setWidth((int)$data['width']);
		$item->setHeight((int)$data['height']);
		$item->setRotation((int)$data['rotation']);
		$item->setFps((float)$data['fps']);
		$item->setVcodec((string)$data['vcodec']);
		$item->setVprofile((string)$data['vprofile']);
		$item->setVlevel((int)$data['vlevel']);
		$item->setPixFmt((string)$data['pix_fmt']);
		$item->setBitDepth((int)$data['bit_depth']);
		$item->setHdr((int)$data['hdr']);
		$item->setAcodec((string)$data['acodec']);
		$item->setAchannels((int)$data['achannels']);
		$item->setAudioTracks((string)json_encode($data['audio_tracks']));
		$item->setSubTracks((string)json_encode($data['sub_tracks']));
		$item->setChapters((string)json_encode($data['chapters'] ?? []));
		$item->setMimetype($file->getMimetype());
		$item->setSize($file->getSize());
		$item->setMtime($file->getMTime());

		// A date somebody set by hand is the right one, and reading the file
		// again is not a reason to reconsider it.
		if ($item->getDateLocked() !== 1) {
			$taken = $data['taken_at'];
			if (($taken['at'] ?? 0) > 0) {
				$item->setTakenAt((int)$taken['at']);
				$item->setDateSource((string)$taken['source']);
			} else {
				$item->setTakenAt($file->getMTime());
				$item->setDateSource('mtime');
			}
		}

		$item->setProbeVersion(Probe::VERSION);
		$item->setStatus('ok');
		$item->setFailReason(null);
		$item->setIndexedAt(time());
		$this->mapper->update($item);
		return true;
	}

	private function fail(Item $item, string $reason): bool {
		$item->setStatus('failed');
		$item->setFailReason(mb_substr($reason, 0, 250));
		$item->setIndexedAt(time());
		$item->setProbeVersion(Probe::VERSION);
		$this->mapper->update($item);
		return false;
	}

	/**
	 * Inspect a batch of queued files.
	 *
	 * @return array{done: int, failed: int, remaining: int}
	 */
	public function processQueue(int $limit, ?string $userId = null, ?callable $onEach = null): array {
		$done = 0;
		$failed = 0;
		$setAside = 0;
		foreach ($this->mapper->pending($limit, $userId) as $item) {
			$ok = false;
			try {
				$ok = $this->inspect($item);
			} catch (\Throwable $e) {
				$this->logger->warning('Video Gallery could not inspect ' . $item->getPath() . ': ' . $e->getMessage(), ['exception' => $e]);
				$this->fail($item, $e->getMessage());
			}
			if ($ok) {
				$done++;
			} elseif ($item->getStatus() === self::EXCLUDED) {
				// Read perfectly well, and deliberately left out. Not a failure.
				$setAside++;
			} else {
				$failed++;
			}
			if ($onEach !== null) {
				$onEach($item, $ok);
			}
		}
		return [
			'done' => $done,
			'failed' => $failed,
			'excluded' => $setAside,
			'remaining' => count($this->mapper->pending(1, $userId)),
		];
	}

	/** @return list<string> every account that has files to look at */
	public function userIds(): array {
		$ids = [];
		$this->userManager->callForAllUsers(static function ($user) use (&$ids): void {
			$ids[] = $user->getUID();
		});
		return $ids;
	}
}
