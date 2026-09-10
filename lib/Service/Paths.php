<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Service;

use OCP\ITempManager;
use Psr\Log\LoggerInterface;

/**
 * The one place that decides where scratch files go, and the only place allowed
 * to delete them.
 *
 * Everything the app writes to disk lives under a single root, and that root is
 * stamped with a marker file the moment it is created. No recursive delete ever
 * runs on a directory that is not inside a marked root, so a mistyped setting
 * cannot turn into a wiped system directory.
 */
class Paths {
	public const MARKER = '.videogallery-cache';
	private const AREAS = ['sessions', 'previews', 'sprites', 'posters', 'subtitles', 'work'];

	private ?string $resolved = null;

	public function __construct(
		private Config $config,
		private ITempManager $tempManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The cache root in use. Falls back to an auto-chosen location when the
	 * configured one is unusable, so a fresh install works before anyone has
	 * opened the settings page.
	 */
	public function root(): string {
		if ($this->resolved !== null) {
			return $this->resolved;
		}
		$configured = trim($this->config->getString('cache_root'));
		if ($configured !== '' && $this->prepare($configured)) {
			return $this->resolved = rtrim($configured, '/');
		}
		foreach ($this->candidates() as $candidate) {
			if ($this->prepare($candidate)) {
				if ($configured !== '') {
					$this->logger->warning('Video Gallery cache root {bad} is not usable, falling back to {good}', [
						'bad' => $configured,
						'good' => $candidate,
					]);
				}
				return $this->resolved = rtrim($candidate, '/');
			}
		}
		throw new \RuntimeException('Video Gallery has nowhere to write temporary files. Set a cache directory in the administration settings.');
	}

	/**
	 * Locations worth trying when nothing is configured, best first: a dedicated
	 * fast disk if the machine has one, then ordinary spool space, then the
	 * system temp directory as the last resort.
	 *
	 * @return list<string>
	 */
	public function candidates(): array {
		$names = ['videogallery'];
		$roots = ['/srv/cache', '/srv/nvme', '/mnt/cache', '/mnt/nvme', '/var/cache', '/var/tmp'];
		$out = [];
		foreach ($roots as $base) {
			if (is_dir($base) && is_writable($base)) {
				$out[] = $base . '/' . $names[0];
			}
		}
		$tmp = rtrim($this->tempManager->getTempBaseDir(), '/');
		if ($tmp !== '') {
			$out[] = $tmp . '/videogallery';
		}
		$out[] = sys_get_temp_dir() . '/videogallery';
		return array_values(array_unique($out));
	}

	/**
	 * Create the root and its areas if needed, and confirm we can really write there.
	 * Never touches a pre-existing directory that holds someone else's files.
	 */
	public function prepare(string $path): bool {
		$path = rtrim($path, '/');
		if ($path === '' || $path === '/') {
			return false;
		}
		if (!is_dir($path)) {
			$parent = dirname($path);
			if (!is_dir($parent) || !is_writable($parent)) {
				return false;
			}
			if (!@mkdir($path, 0770, true) && !is_dir($path)) {
				return false;
			}
		}
		if (!is_writable($path)) {
			return false;
		}
		$marker = $path . '/' . self::MARKER;
		if (!file_exists($marker)) {
			// Refuse to adopt a directory that already holds unrelated files: a
			// typo in the settings must not put someone's data at risk of a sweep.
			$existing = @scandir($path) ?: [];
			$existing = array_diff($existing, ['.', '..', 'lost+found']);
			if ($existing !== []) {
				return false;
			}
			if (@file_put_contents($marker, "Video Gallery scratch space. Everything here is disposable.\n") === false) {
				return false;
			}
		}
		foreach (self::AREAS as $area) {
			$dir = $path . '/' . $area;
			if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
				return false;
			}
		}
		return true;
	}

	/** True when the path lies inside a directory this app created and owns. */
	public function isOwned(string $path): bool {
		$path = rtrim($path, '/');
		if ($path === '' || $path === '/') {
			return false;
		}
		$root = null;
		try {
			$root = rtrim($this->root(), '/');
		} catch (\RuntimeException) {
			return false;
		}
		if ($path !== $root && !str_starts_with($path . '/', $root . '/')) {
			return false;
		}
		return file_exists($root . '/' . self::MARKER);
	}

	public function sessionDir(string $token): string {
		return $this->root() . '/sessions/' . $token;
	}

	public function workDir(string $token): string {
		return $this->root() . '/work/' . $token;
	}

	/**
	 * Preview assets are spread over two levels of subdirectory, so a library of
	 * a hundred thousand videos never puts a hundred thousand entries in one
	 * directory.
	 */
	public function assetPath(int $fileId, string $kind): string {
		$hash = substr(md5((string)$fileId), 0, 4);
		$name = match ($kind) {
			'loop' => $fileId . '.mp4',
			'poster' => $fileId . '.jpg',
			'sprite' => $fileId . '.jpg',
			default => $fileId . '.' . $kind,
		};
		$area = match ($kind) {
			'loop' => 'previews',
			'poster' => 'posters',
			'sprite' => 'sprites',
			default => 'previews',
		};
		return $this->root() . '/' . $area . '/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $name;
	}

	/** The path an asset is recorded under in the database, relative to the root. */
	public function relative(string $absolute): string {
		$root = rtrim($this->root(), '/') . '/';
		return str_starts_with($absolute, $root) ? substr($absolute, strlen($root)) : $absolute;
	}

	public function absolute(string $relative): string {
		return rtrim($this->root(), '/') . '/' . ltrim($relative, '/');
	}

	public function ensureParent(string $file): bool {
		$dir = dirname($file);
		return is_dir($dir) || @mkdir($dir, 0770, true) || is_dir($dir);
	}

	/**
	 * Remove a file or a whole directory tree, but only from inside our own root.
	 *
	 * @return int bytes freed
	 */
	public function remove(string $path): int {
		if (!$this->isOwned($path) || !file_exists($path)) {
			return 0;
		}
		if (is_file($path) || is_link($path)) {
			$size = is_file($path) ? (int)@filesize($path) : 0;
			return @unlink($path) ? $size : 0;
		}
		$freed = 0;
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($iterator as $entry) {
			/** @var \SplFileInfo $entry */
			if ($entry->isDir() && !$entry->isLink()) {
				@rmdir($entry->getPathname());
			} else {
				$freed += (int)@filesize($entry->getPathname());
				@unlink($entry->getPathname());
			}
		}
		@rmdir($path);
		return $freed;
	}

	/** @return array{total: int, free: int, used: int} bytes on the cache filesystem */
	public function diskUsage(): array {
		try {
			$root = $this->root();
		} catch (\RuntimeException) {
			return ['total' => 0, 'free' => 0, 'used' => 0];
		}
		$total = (int)@disk_total_space($root);
		$free = (int)@disk_free_space($root);
		return ['total' => $total, 'free' => $free, 'used' => $total - $free];
	}

	/** Bytes currently held by our own files under the root. */
	public function cacheSize(): int {
		try {
			$root = $this->root();
		} catch (\RuntimeException) {
			return 0;
		}
		$bytes = 0;
		foreach (self::AREAS as $area) {
			$dir = $root . '/' . $area;
			if (!is_dir($dir)) {
				continue;
			}
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::LEAVES_ONLY,
			);
			foreach ($iterator as $entry) {
				/** @var \SplFileInfo $entry */
				if ($entry->isFile()) {
					$bytes += $entry->getSize();
				}
			}
		}
		return $bytes;
	}

	/** @return list<string> every session directory currently on disk */
	public function sessionDirs(): array {
		try {
			$dir = $this->root() . '/sessions';
		} catch (\RuntimeException) {
			return [];
		}
		$out = [];
		foreach (@scandir($dir) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			if (is_dir($dir . '/' . $entry)) {
				$out[] = $entry;
			}
		}
		return $out;
	}
}
