<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Service;

use OCA\VideoGallery\Db\Asset;
use OCA\VideoGallery\Db\AssetMapper;
use OCA\VideoGallery\Db\Item;
use OCA\VideoGallery\Db\ItemMapper;
use OCA\VideoGallery\Db\ProgressMapper;
use OCA\VideoGallery\Db\Session;
use OCA\VideoGallery\Db\SessionMapper;
use OCA\VideoGallery\Service\PlaybackMemory;
use Psr\Log\LoggerInterface;

/**
 * Takes out the rubbish.
 *
 * The rule this app is built around is that nothing reaches the cache disk
 * without a database row naming it, and nothing keeps a row without a file
 * behind it. That makes tidying up a matter of comparing the two lists rather
 * than guessing, and it means a crashed process, a killed browser tab or a
 * power cut all leave the same recognisable, removable mess.
 */
class Janitor {
	public function __construct(
		private SessionMapper $sessions,
		private AssetMapper $assets,
		private ItemMapper $items,
		private ProgressMapper $progress,
		private Paths $paths,
		private Config $config,
		private PlaybackMemory $memory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The full round: dead sessions, stray directories, stale assets, and the
	 * size limit. Safe to run as often as you like.
	 *
	 * @return array<string, int>
	 */
	public function sweep(): array {
		$report = [
			'sessions_ended' => 0,
			'processes_killed' => 0,
			'orphan_dirs' => 0,
			'orphan_rows' => 0,
			'assets_expired' => 0,
			'assets_trimmed' => 0,
			'assets_missing' => 0,
			'work_files' => 0,
			'memories_pruned' => 0,
			'bytes_freed' => 0,
		];
		$this->merge($report, $this->sweepSessions());
		$this->merge($report, $this->sweepOrphans());
		$this->merge($report, $this->sweepWork());
		$this->merge($report, $this->expireAssets());
		$this->merge($report, $this->enforceSizeLimit());
		$this->merge($report, ['memories_pruned' => $this->memory->prune()]);
		return $report;
	}

	/** @param array<string, int> $into @param array<string, int> $from */
	private function merge(array &$into, array $from): void {
		foreach ($from as $key => $value) {
			$into[$key] = ($into[$key] ?? 0) + $value;
		}
	}

	/**
	 * End sessions whose player has stopped asking for segments, and any that
	 * have simply been running too long.
	 *
	 * @return array<string, int>
	 */
	public function sweepSessions(): array {
		$ended = 0;
		$killed = 0;
		$freed = 0;
		$ttl = $this->config->getInt('session_ttl');
		$maxLife = $this->config->getInt('session_max_life');
		foreach ($this->sessions->expired($ttl, $maxLife) as $session) {
			if ($this->endSession($session, 'expired')) {
				$killed++;
			}
			$freed += $this->dropSession($session);
			$ended++;
		}
		return ['sessions_ended' => $ended, 'processes_killed' => $killed, 'bytes_freed' => $freed];
	}

	/**
	 * Stop a session's encoder.
	 *
	 * @return bool whether a process was actually signalled
	 */
	public function endSession(Session $session, string $reason = 'closed'): bool {
		$pid = $session->getPid();
		if ($pid <= 0) {
			return false;
		}
		if (!$this->isOurProcess($pid, $session)) {
			// The number is stale: that process either finished long ago or now
			// belongs to something else entirely. Signalling it would be reckless.
			return false;
		}
		$this->signal($pid, self::SIG_TERM);
		// Give it a moment to close its files before insisting.
		for ($i = 0; $i < 20 && $this->isAlive($pid); $i++) {
			usleep(50000);
		}
		if ($this->isAlive($pid)) {
			$this->signal($pid, self::SIG_KILL);
		}
		$this->logger->debug('Video Gallery stopped encoder {pid} for session {token} ({reason})', [
			'pid' => $pid,
			'token' => $session->getToken(),
			'reason' => $reason,
		]);
		return true;
	}

	/** Remove a session's directory and its row. Returns bytes freed. */
	public function dropSession(Session $session): int {
		$freed = 0;
		$dir = $session->getDir();
		if ($dir !== '' && $this->paths->isOwned($dir)) {
			$freed = $this->paths->remove($dir);
		}
		try {
			$this->sessions->delete($session);
		} catch (\Throwable $e) {
			$this->logger->debug('Video Gallery could not delete a session row: ' . $e->getMessage());
		}
		return $freed;
	}

	/**
	 * Directories on disk with no row, and rows with no directory.
	 *
	 * @return array<string, int>
	 */
	public function sweepOrphans(): array {
		$dirs = 0;
		$rows = 0;
		$freed = 0;

		$known = [];
		foreach ($this->sessions->all() as $session) {
			$known[$session->getToken()] = $session;
		}
		foreach ($this->paths->sessionDirs() as $token) {
			if (isset($known[$token])) {
				continue;
			}
			// A directory nobody is recorded as owning. It cannot be in use,
			// because a live session always has its row.
			$freed += $this->paths->remove($this->paths->sessionDir($token));
			$dirs++;
		}
		foreach ($known as $token => $session) {
			$dir = $session->getDir();
			if ($dir === '' || is_dir($dir)) {
				continue;
			}
			if ($session->isLive() && $session->getLastSeen() > time() - 30) {
				// Just created; the directory may be a heartbeat away.
				continue;
			}
			$this->endSession($session, 'directory gone');
			$this->dropSession($session);
			$rows++;
		}
		return ['orphan_dirs' => $dirs, 'orphan_rows' => $rows, 'bytes_freed' => $freed];
	}

	/**
	 * Leftovers in the working area: copies pulled off remote storage, half
	 * written previews, extracted subtitles.
	 *
	 * @return array<string, int>
	 */
	public function sweepWork(): array {
		$removed = 0;
		$freed = 0;
		try {
			$dir = $this->paths->root() . '/work';
		} catch (\RuntimeException) {
			return ['work_files' => 0, 'bytes_freed' => 0];
		}
		if (!is_dir($dir)) {
			return ['work_files' => 0, 'bytes_freed' => 0];
		}
		$cutoff = time() - 3600;
		foreach (@scandir($dir) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$path = $dir . '/' . $entry;
			$mtime = @filemtime($path);
			if ($mtime !== false && $mtime > $cutoff) {
				continue;
			}
			$freed += $this->paths->remove($path);
			$removed++;
		}
		return ['work_files' => $removed, 'bytes_freed' => $freed];
	}

	/**
	 * Drop cached previews nobody has looked at for a long time, and reconcile
	 * rows against what is really on disk.
	 *
	 * @return array<string, int>
	 */
	public function expireAssets(): array {
		$expired = 0;
		$missing = 0;
		$freed = 0;
		$ttlDays = $this->config->getInt('preview_ttl_days');
		if ($ttlDays > 0) {
			foreach ($this->assets->olderThan($ttlDays * 86400, 500) as $asset) {
				$freed += $this->dropAsset($asset);
				$expired++;
			}
		}
		foreach ($this->assets->all() as $asset) {
			// Rows whose file has vanished (a manually cleared cache disk, say).
			if (!is_file($this->paths->absolute($asset->getRelPath()))) {
				$this->forgetAsset($asset);
				$missing++;
				continue;
			}
			// Pictures made for a video that has since left the library: the file
			// was deleted, or it turned out to be something the rules now say to
			// leave alone. Either way nothing will ever ask for these again.
			if ($this->items->byFileId($asset->getFileId()) === []) {
				$freed += $this->dropAsset($asset);
				$missing++;
			}
		}
		return ['assets_expired' => $expired, 'assets_missing' => $missing, 'bytes_freed' => $freed];
	}

	/**
	 * Keep the cache inside the size the administrator allowed, dropping the
	 * least recently used previews first.
	 *
	 * @return array<string, int>
	 */
	public function enforceSizeLimit(): array {
		$limitBytes = (int)($this->config->getFloat('cache_max_gb') * 1024 * 1024 * 1024);
		if ($limitBytes <= 0) {
			return ['assets_trimmed' => 0, 'bytes_freed' => 0];
		}
		$used = $this->assets->totalSize();
		if ($used <= $limitBytes) {
			return ['assets_trimmed' => 0, 'bytes_freed' => 0];
		}
		$trimmed = 0;
		$freed = 0;
		// Aim a little under the limit so this does not run again immediately.
		$target = (int)($limitBytes * 0.9);
		while ($used > $target) {
			$batch = $this->assets->coldest(200);
			if ($batch === []) {
				break;
			}
			foreach ($batch as $asset) {
				$size = $asset->getSize();
				$freed += $this->dropAsset($asset);
				$used -= $size;
				$trimmed++;
				if ($used <= $target) {
					break;
				}
			}
		}
		return ['assets_trimmed' => $trimmed, 'bytes_freed' => $freed];
	}

	/** Remove one cached asset, file and row, and clear its flag on the item. */
	public function dropAsset(Asset $asset): int {
		$freed = $this->paths->remove($this->paths->absolute($asset->getRelPath()));
		$this->forgetAsset($asset);
		return $freed;
	}

	private function forgetAsset(Asset $asset): void {
		$flag = match ($asset->getKind()) {
			Asset::POSTER => Item::ASSET_POSTER,
			Asset::LOOP => Item::ASSET_LOOP,
			Asset::SPRITE => Item::ASSET_SPRITE,
			default => 0,
		};
		if ($flag > 0) {
			$this->items->setAssetFlag($asset->getFileId(), $flag, false);
		}
		try {
			$this->assets->delete($asset);
		} catch (\Throwable $e) {
			$this->logger->debug('Video Gallery could not delete an asset row: ' . $e->getMessage());
		}
	}

	/** Everything cached for one file: used when the file itself changes or goes. */
	public function purgeFile(int $fileId): int {
		$freed = 0;
		foreach ($this->assets->forFile($fileId) as $asset) {
			$freed += $this->dropAsset($asset);
		}
		foreach ($this->sessions->all() as $session) {
			if ($session->getFileId() === $fileId) {
				$this->endSession($session, 'file changed');
				$freed += $this->dropSession($session);
			}
		}
		return $freed;
	}

	/** Everything belonging to one account. */
	public function purgeUser(string $userId): array {
		$freed = 0;
		foreach ($this->sessions->forUser($userId) as $session) {
			$this->endSession($session, 'account removed');
			$freed += $this->dropSession($session);
		}
		foreach ($this->assets->all() as $asset) {
			if ($asset->getUserId() === $userId) {
				$freed += $this->dropAsset($asset);
			}
		}
		$items = $this->items->deleteForUser($userId);
		$this->progress->deleteForUser($userId);
		$this->assets->deleteForUser($userId);
		$this->sessions->deleteForUser($userId);
		return ['items' => $items, 'bytes_freed' => $freed];
	}

	/**
	 * Stop everything and empty the cache disk. The library index survives, so
	 * previews are simply made again on demand.
	 */
	public function purgeCache(): array {
		$freed = 0;
		$killed = 0;
		foreach ($this->sessions->all() as $session) {
			if ($this->endSession($session, 'cache purge')) {
				$killed++;
			}
			$freed += $this->dropSession($session);
		}
		foreach ($this->assets->all() as $asset) {
			$freed += $this->dropAsset($asset);
		}
		foreach (['sessions', 'previews', 'sprites', 'posters', 'subtitles', 'work'] as $area) {
			try {
				$dir = $this->paths->root() . '/' . $area;
			} catch (\RuntimeException) {
				break;
			}
			foreach (@scandir($dir) ?: [] as $entry) {
				if ($entry === '.' || $entry === '..') {
					continue;
				}
				$freed += $this->paths->remove($dir . '/' . $entry);
			}
		}
		return ['bytes_freed' => $freed, 'processes_killed' => $killed];
	}

	/** What the cache disk currently holds. @return array<string, mixed> */
	public function report(): array {
		$disk = $this->paths->diskUsage();
		$live = $this->sessions->live();
		try {
			$root = $this->paths->root();
		} catch (\RuntimeException $e) {
			$root = $e->getMessage();
		}
		return [
			'root' => $root,
			'tracked_bytes' => $this->assets->totalSize(),
			'on_disk_bytes' => $this->paths->cacheSize(),
			'limit_bytes' => (int)($this->config->getFloat('cache_max_gb') * 1024 * 1024 * 1024),
			'disk_total' => $disk['total'],
			'disk_free' => $disk['free'],
			'sessions_live' => count($live),
			'sessions_total' => count($this->sessions->all()),
			'session_dirs' => count($this->paths->sessionDirs()),
			'assets' => count($this->assets->all()),
		];
	}

	// -- process handling ---------------------------------------------------

	public function isAlive(int $pid): bool {
		if ($pid <= 0) {
			return false;
		}
		if (is_dir('/proc/' . $pid)) {
			return true;
		}
		if (function_exists('posix_kill')) {
			return @posix_kill($pid, 0);
		}
		return false;
	}

	/**
	 * Confirm a process id still belongs to the encoder we started for this
	 * session, by looking at what it was actually run with. Process ids get
	 * reused, and a stale one must never be signalled blindly.
	 */
	private function isOurProcess(int $pid, Session $session): bool {
		if (!$this->isAlive($pid)) {
			return false;
		}
		$cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');
		if ($cmdline === false) {
			// No /proc to check against: trust the row only while it is fresh.
			return $session->getCreatedAt() > time() - $this->config->getInt('session_max_life');
		}
		$cmdline = str_replace("\0", ' ', $cmdline);
		return str_contains($cmdline, $session->getDir()) || str_contains($cmdline, $session->getToken());
	}

	/**
	 * Signal numbers, without depending on the constants.
	 *
	 * SIGSTOP and SIGCONT are defined by the pcntl extension, which is normally
	 * built for the command line only — so under the web server the constants
	 * simply are not there. Their numbers on Linux are fixed, and this is the
	 * one place that needs to know them.
	 */
	public const SIG_TERM = 15;
	public const SIG_KILL = 9;
	public const SIG_STOP = 19;
	public const SIG_CONT = 18;

	/** Halt a process where it stands, without ending it. */
	public function pauseProcess(int $pid): void {
		if ($pid > 0 && $this->isAlive($pid)) {
			$this->signal($pid, self::SIG_STOP);
		}
	}

	/** Let a halted process carry on. */
	public function resumeProcess(int $pid): void {
		if ($pid > 0 && $this->isAlive($pid)) {
			$this->signal($pid, self::SIG_CONT);
		}
	}

	private function signal(int $pid, int $signal): void {
		if (function_exists('posix_kill')) {
			@posix_kill($pid, $signal);
			return;
		}
		if (function_exists('proc_open')) {
			$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
			$process = @proc_open(['kill', '-' . $signal, (string)$pid], $descriptors, $pipes);
			if (is_resource($process)) {
				foreach ($pipes as $pipe) {
					fclose($pipe);
				}
				proc_close($process);
			}
		}
	}
}
