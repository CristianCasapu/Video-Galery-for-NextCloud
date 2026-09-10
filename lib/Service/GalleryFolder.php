<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Service;

use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use Psr\Log\LoggerInterface;

/**
 * The folder each account's own videos are expected to live in.
 *
 * The library collects videos from everywhere, which is the point of it. But
 * "everywhere" is not somewhere to put things, and a person who has just
 * recorded something needs one obvious place for it. So there is a named
 * folder, made once, given its own row at the top of the page, and offered as
 * the destination whenever the app has something to file.
 */
class GalleryFolder {
	public function __construct(
		private Config $config,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
	}

	/** The configured name, cleaned of anything that is not a plain folder name. */
	public function name(): string {
		$name = trim($this->config->getString('default_folder'), " \t\n\r\0\x0B/");
		if ($name === '' || str_contains($name, '..')) {
			return '';
		}
		return $name;
	}

	/**
	 * Make sure the folder is there, creating it the first time.
	 *
	 * Never fails loudly: a read-only home or an account without a Files tree
	 * is a reason to do without the folder, not a reason to refuse to show
	 * anybody their videos.
	 *
	 * @return string the path, or an empty string when there is none
	 */
	public function ensure(string $userId): string {
		$name = $this->name();
		if ($name === '' || $userId === '') {
			return '';
		}
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			if ($userFolder->nodeExists($name)) {
				return $name;
			}
			$userFolder->newFolder($name);
			$this->logger->info('Video Gallery created the {name} folder for {user}', ['name' => $name, 'user' => $userId]);
			return $name;
		} catch (NotPermittedException) {
			return '';
		} catch (NotFoundException) {
			return '';
		} catch (\Throwable $e) {
			$this->logger->debug('Video Gallery could not prepare the default folder: ' . $e->getMessage());
			return '';
		}
	}

	/** Whether a path sits inside the default folder. */
	public function contains(string $path): bool {
		$name = $this->name();
		return $name !== '' && ($path === $name || str_starts_with($path, $name . '/'));
	}
}
