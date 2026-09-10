<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Service;

use OCA\DAV\Connector\Sabre\PublicAuth;
use OCA\VideoGallery\Db\Item;
use OCA\VideoGallery\Db\ItemMapper;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\ISession;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;

/**
 * Everything about watching something through a share link.
 *
 * A link stands in for an account: whoever holds it may see what it points at,
 * and nothing else. That "nothing else" is the whole job of this class. A link
 * to one film must not become a way to ask for another; a link to a folder must
 * reach everything inside it and stop at its edge; and a link whose owner said
 * "viewing only" must not quietly hand over the file itself.
 *
 * The share's own settings are the authority throughout — this app adds no
 * permissions of its own and takes none away.
 */
class ShareAccess {
	public function __construct(
		private IShareManager $shareManager,
		private ItemMapper $items,
		private ISession $session,
		private Config $config,
	) {
	}

	/** Whether links may be used to watch at all. */
	public function enabled(): bool {
		return $this->config->getBool('sharing_enabled');
	}

	/**
	 * The share behind a token, if it is one this app can serve.
	 *
	 * Only link shares: a share with a person or a group is reached through
	 * their own account, where the whole library is available anyway.
	 */
	public function find(string $token): ?IShare {
		if (!$this->enabled() || $token === '') {
			return null;
		}
		try {
			$share = $this->shareManager->getShareByToken($token);
		} catch (ShareNotFound) {
			return null;
		}
		if (!in_array($share->getShareType(), [IShare::TYPE_LINK, IShare::TYPE_EMAIL], true)) {
			return null;
		}
		if (($share->getPermissions() & \OCP\Constants::PERMISSION_READ) === 0) {
			return null;
		}
		return $share;
	}

	public function needsPassword(IShare $share): bool {
		return $share->getPassword() !== null && $share->getPassword() !== '';
	}

	/**
	 * Whether this visitor has already given the password for this share.
	 *
	 * Recorded the way the rest of Nextcloud records it, so a visitor who has
	 * unlocked a link in the Files app does not have to unlock it again here,
	 * and so unlocking it here counts everywhere else.
	 */
	public function isUnlocked(IShare $share): bool {
		if (!$this->needsPassword($share)) {
			return true;
		}
		$allowed = $this->session->get(PublicAuth::DAV_AUTHENTICATED);
		return is_array($allowed) && in_array($share->getId(), $allowed, true);
	}

	/** Check a password and remember the answer if it was right. */
	public function unlock(IShare $share, string $password): bool {
		if (!$this->shareManager->checkPassword($share, $password)) {
			return false;
		}
		$allowed = $this->session->get(PublicAuth::DAV_AUTHENTICATED);
		$allowed = is_array($allowed) ? $allowed : [];
		$allowed[] = $share->getId();
		$this->session->set(PublicAuth::DAV_AUTHENTICATED, $allowed);
		return true;
	}

	/**
	 * Whether the file itself may be handed over, as opposed to merely watched.
	 *
	 * This is the share's download permission, and this app honours it in the
	 * two places where it means something: the original file is not served, and
	 * no link is offered to a player on the device. Watching still works, since
	 * a converted stream is watching rather than downloading.
	 *
	 * It is worth being plain about what this is not: anything that can be
	 * watched can be recorded, and no setting in any application changes that.
	 * It stops a file being taken by accident or in passing, not by somebody
	 * determined.
	 */
	public function canDownload(IShare $share): bool {
		if (($share->getPermissions() & \OCP\Constants::PERMISSION_READ) === 0) {
			return false;
		}
		$attributes = $share->getAttributes();
		if ($attributes?->getAttribute('permissions', 'download') === false) {
			return false;
		}
		return !$share->getHideDownload();
	}

	/** The account whose library holds what this share points at. */
	public function ownerId(IShare $share): string {
		return $share->getShareOwner();
	}

	/** What the share points at, as a node in the owner's tree. */
	public function node(IShare $share): ?Node {
		try {
			return $share->getNode();
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * One video reachable through this share.
	 *
	 * A share of a single file reaches exactly that file. A share of a folder
	 * reaches whatever is inside it, at any depth — and nothing outside, which
	 * is checked against the folder itself rather than against a path that
	 * arrived with the request.
	 */
	public function item(IShare $share, int $fileId): ?Item {
		$node = $this->node($share);
		if ($node === null) {
			return null;
		}
		if ($node instanceof File) {
			if ($node->getId() !== $fileId) {
				return null;
			}
		} elseif ($node instanceof Folder) {
			$inside = $node->getById($fileId);
			if ($inside === []) {
				return null;
			}
		} else {
			return null;
		}
		return $this->items->find($this->ownerId($share), $fileId);
	}

	/**
	 * The videos a share opens onto, and the folders they sit in.
	 *
	 * @return array{items: list<array<string, mixed>>, folders: list<array<string, mixed>>, total: int}
	 */
	public function contents(IShare $share, string $folder = '', string $sort = 'name_asc', int $limit = 500, int $offset = 0): array {
		$node = $this->node($share);
		$ownerId = $this->ownerId($share);
		if ($node === null) {
			return ['items' => [], 'folders' => [], 'total' => 0];
		}

		if ($node instanceof File) {
			$item = $this->items->find($ownerId, $node->getId());
			return [
				'items' => $item === null ? [] : [$item->jsonSerialize()],
				'folders' => [],
				'total' => $item === null ? 0 : 1,
			];
		}

		// The share's own folder, as a path inside the owner's library, plus
		// whatever the visitor has browsed into below it.
		$root = $this->rootPath($share, $node);
		$scope = $folder === '' ? $root : rtrim($root, '/') . '/' . trim($folder, '/');

		// The videos in this folder itself. What is in the folders below it
		// belongs to those folders, and is reached by opening them.
		$filter = ['sort' => $sort, 'directOnly' => true];
		if ($scope !== '') {
			$filter['folder'] = $scope;
		}
		$found = $this->items->search($ownerId, $filter, $limit, $offset);

		$prefix = $scope === '' ? '' : rtrim($scope, '/') . '/';
		$items = [];
		foreach ($found as $item) {
			$row = $item->jsonSerialize();
			// The visitor has no business knowing where this sits in somebody
			// else's account, so paths are given relative to the share.
			$row['path'] = $prefix === '' ? $item->getPath() : substr($item->getPath(), strlen($prefix));
			$row['folder'] = $folder;
			$items[] = $row;
		}

		return [
			'items' => $items,
			'folders' => $this->items->subfolders($ownerId, $scope),
			'total' => $this->items->count($ownerId, $filter),
		];
	}

	/** Where the shared folder sits in the owner's library. */
	public function rootPath(IShare $share, ?Node $node = null): string {
		$node ??= $this->node($share);
		if ($node === null) {
			return '';
		}
		$path = $node->getPath();
		$marker = '/files/';
		$at = strpos($path, $marker);
		return $at === false ? '' : trim(substr($path, $at + strlen($marker)), '/');
	}

	/** A short description of the share, for the page it opens. */
	public function describe(IShare $share): array {
		$node = $this->node($share);
		return [
			'token' => $share->getToken(),
			'label' => $share->getLabel() ?: ($node?->getName() ?? ''),
			'name' => $node?->getName() ?? '',
			'isFolder' => $node instanceof Folder,
			'canDownload' => $this->canDownload($share),
			'owner' => $share->getShareOwner(),
			'ownerDisplayName' => $share->getShareOwner(),
			'expires' => $share->getExpirationDate()?->getTimestamp(),
			'note' => $share->getNote(),
		];
	}
}
