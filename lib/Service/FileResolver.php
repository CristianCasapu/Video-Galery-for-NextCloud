<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Service;

use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;

/**
 * Turns a file id belonging to a user into something ffmpeg can open.
 *
 * On ordinary local storage that is the file itself. On object storage or an
 * external mount there is no path on this machine, so the file is copied to the
 * scratch disk first and the caller is told to dispose of the copy afterwards.
 */
class FileResolver {
	public function __construct(
		private IRootFolder $rootFolder,
		private Paths $paths,
	) {
	}

	public function getFile(string $userId, int $fileId): ?File {
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (\Throwable) {
			return null;
		}
		$nodes = $userFolder->getById($fileId);
		foreach ($nodes as $node) {
			if ($node instanceof File) {
				return $node;
			}
		}
		return null;
	}

	/**
	 * @return array{path: string, temporary: bool}|null
	 */
	public function localPath(File $file): ?array {
		try {
			$storage = $file->getStorage();
			$internal = $file->getInternalPath();
			if ($storage->isLocal()) {
				$local = $storage->getLocalFile($internal);
				if (is_string($local) && $local !== '' && is_file($local)) {
					return ['path' => $local, 'temporary' => false];
				}
			}
		} catch (\Throwable) {
			// fall through to the copy below
		}
		return $this->copyToScratch($file);
	}

	/**
	 * @return array{path: string, temporary: bool}|null
	 */
	private function copyToScratch(File $file): ?array {
		try {
			$extension = pathinfo($file->getName(), PATHINFO_EXTENSION);
			$target = $this->paths->root() . '/work/remote-' . $file->getId() . '-' . bin2hex(random_bytes(4))
				. ($extension !== '' ? '.' . $extension : '');
			$this->paths->ensureParent($target);
			$source = $file->fopen('r');
			if (!is_resource($source)) {
				return null;
			}
			$sink = @fopen($target, 'wb');
			if (!is_resource($sink)) {
				fclose($source);
				return null;
			}
			stream_copy_to_stream($source, $sink);
			fclose($source);
			fclose($sink);
			return ['path' => $target, 'temporary' => true];
		} catch (\Throwable) {
			return null;
		}
	}

	/** Drop a copy made by localPath(), if it made one. */
	public function release(array $resolved): void {
		if (($resolved['temporary'] ?? false) === true) {
			$this->paths->remove($resolved['path']);
		}
	}

	public function relativePath(string $userId, Node $node): string {
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			return ltrim(substr($node->getPath(), strlen($userFolder->getPath())), '/');
		} catch (NotFoundException) {
			return $node->getName();
		}
	}
}
