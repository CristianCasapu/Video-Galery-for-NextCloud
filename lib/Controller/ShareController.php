<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Controller;

use OCA\VideoGallery\AppInfo\Application;
use OCA\VideoGallery\Db\ItemMapper;
use OCA\VideoGallery\Service\Config;
use OCA\VideoGallery\Service\ShareAccess;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * The gallery's side of sharing.
 *
 * Creating and changing shares is left to Nextcloud's own sharing API, which
 * already knows every rule an administrator may have set — who may share with
 * whom, whether links are allowed, whether a password is required, how long a
 * link may live. Reimplementing any of that here would mean reimplementing it
 * wrongly.
 *
 * What is left is the part only this app can do: telling the gallery what is
 * already shared, pointing a share at the player rather than at the file list,
 * and, where the short links app is installed, giving the same share a short
 * address as well as a long one.
 */
class ShareController extends OCSController {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private IShareManager $shareManager,
		private IRootFolder $rootFolder,
		private IAppManager $appManager,
		private ItemMapper $items,
		private ShareAccess $access,
		private Config $config,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function userId(): string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}

	/**
	 * Everything already shared about one file or folder.
	 */
	#[NoAdminRequired]
	public function forFile(int $fileId): DataResponse {
		$userId = $this->userId();
		if (!$this->config->getBool('sharing_enabled')) {
			return new DataResponse(['enabled' => false, 'shares' => []]);
		}
		$node = $this->node($userId, $fileId);
		if ($node === null) {
			return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		$shares = [];
		foreach ([IShare::TYPE_USER, IShare::TYPE_GROUP, IShare::TYPE_LINK, IShare::TYPE_EMAIL] as $type) {
			foreach ($this->shareManager->getSharesBy($userId, $type, $node, false, 100) as $share) {
				$shares[] = $this->describe($share);
			}
		}
		return new DataResponse([
			'enabled' => true,
			'linksAllowed' => $this->shareManager->shareApiAllowLinks(),
			'passwordRequired' => $this->shareManager->shareApiLinkEnforcePassword(),
			'shortLinks' => $this->shortLinksAvailable(),
			'isFolder' => $node instanceof Folder,
			'path' => $this->relativePath($userId, $node->getPath()),
			'name' => $node->getName(),
			'shares' => $shares,
		]);
	}

	/**
	 * A short address for a share, where the short links app is installed.
	 *
	 * A link to a two hour film is a thing people read out over the telephone,
	 * and forty characters of token is not something anybody reads out.
	 */
	#[NoAdminRequired]
	public function shortLink(string $shareId): DataResponse {
		if (!$this->shortLinksAvailable() || !$this->config->getBool('short_links')) {
			return new DataResponse(['message' => 'Short links are not available here.'], Http::STATUS_NOT_IMPLEMENTED);
		}
		$userId = $this->userId();
		$share = $this->shareById($shareId, $userId);
		if ($share === null) {
			return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
		if ($share->getSharedBy() !== $userId && $share->getShareOwner() !== $userId) {
			return new DataResponse(['message' => 'Not yours to shorten'], Http::STATUS_FORBIDDEN);
		}
		try {
			/** @var \OCA\Shortcloud\Service\LinkService $links */
			$links = \OCP\Server::get(\OCA\Shortcloud\Service\LinkService::class);
			$playerUrl = $this->urlGenerator->getAbsoluteURL(
				$this->urlGenerator->linkToRoute('videogallery.public.index', ['token' => $share->getToken()]),
			);

			// A short link the app makes for a share of its own accord points at
			// the file list, and it refuses to be repointed — rightly, since such
			// a link is meant to follow its share wherever it goes. So a separate
			// one is made for the player, and looked up next time rather than
			// piling up a new one on every visit.
			$link = null;
			// Matched on the address itself: the app's own search looks at slugs
			// and titles, not at where a link points.
			foreach ($links->listForUser($userId) as $candidate) {
				// Entities answer their getters through __call, so asking whether
				// the method exists says no even when calling it works.
				try {
					$target = (string)$candidate->getTarget();
				} catch (\Throwable) {
					continue;
				}
				if ($target === $playerUrl) {
					$link = $candidate;
					break;
				}
			}
			if ($link === null) {
				$title = $share->getNode()->getName();
				try {
					$link = $links->create($userId, $playerUrl, null, null, $title, null, true);
				} catch (\Throwable) {
					// Refused for some reason of its own; fall back to whatever it
					// already has for this share, which still reaches the file.
					$existing = $links->findForShare($share, $userId);
					$link = $existing[0] ?? null;
				}
			}
			if ($link === null) {
				return new DataResponse(['message' => 'A short link could not be made.'], Http::STATUS_INTERNAL_SERVER_ERROR);
			}
			$described = $link instanceof \JsonSerializable ? (array)$link->jsonSerialize() : [];
			return new DataResponse([
				'short' => $described['shortUrl'] ?? null,
				'slug' => $described['slug'] ?? null,
				'link' => $described,
			]);
		} catch (\Throwable $e) {
			$this->logger->warning('Video Gallery could not make a short link: ' . $e->getMessage());
			return new DataResponse(['message' => 'A short link could not be made.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * The folders worth offering to share, which are the ones holding videos.
	 */
	#[NoAdminRequired]
	public function folders(): DataResponse {
		$userId = $this->userId();
		$out = [];
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (\Throwable) {
			return new DataResponse(['folders' => []]);
		}
		foreach ($this->items->folders($userId) as $folder) {
			$path = $folder['path'];
			if ($path === '') {
				continue;
			}
			try {
				$node = $userFolder->get($path);
			} catch (\Throwable) {
				continue;
			}
			$out[] = [
				'path' => $path,
				'name' => $node->getName(),
				'fileId' => $node->getId(),
				'count' => $folder['count'],
			];
		}
		return new DataResponse(['folders' => $out]);
	}

	/** @return array<string, mixed> */
	private function describe(IShare $share): array {
		$attributes = $share->getAttributes();
		$isLink = in_array($share->getShareType(), [IShare::TYPE_LINK, IShare::TYPE_EMAIL], true);
		return [
			'id' => $share->getId(),
			'type' => $share->getShareType(),
			'with' => $share->getSharedWith(),
			'withDisplayName' => $share->getSharedWithDisplayName(),
			'label' => $share->getLabel(),
			'token' => $isLink ? $share->getToken() : null,
			// The link people are given opens the player, not the file list.
			'url' => $isLink ? $this->urlGenerator->getAbsoluteURL(
				$this->urlGenerator->linkToRoute('videogallery.public.index', ['token' => $share->getToken()]),
			) : null,
			'filesUrl' => $isLink ? $this->urlGenerator->getAbsoluteURL('/s/' . $share->getToken()) : null,
			'hasPassword' => $share->getPassword() !== null && $share->getPassword() !== '',
			'canDownload' => $attributes?->getAttribute('permissions', 'download') !== false && !$share->getHideDownload(),
			'expires' => $share->getExpirationDate()?->getTimestamp(),
			'note' => $share->getNote(),
			'permissions' => $share->getPermissions(),
		];
	}

	/**
	 * A share from the plain number the sharing API hands out.
	 *
	 * Shares are stored by several providers and each stamps its own prefix onto
	 * the identifier, while the numbers that travel through the API carry none.
	 * So the prefixes are tried in turn, exactly as the sharing API itself does.
	 */
	private function shareById(string $id, string $userId): ?IShare {
		if (str_contains($id, ':')) {
			try {
				return $this->shareManager->getShareById($id, $userId);
			} catch (\Throwable) {
				return null;
			}
		}
		foreach (['ocinternal', 'ocMailShare', 'ocFederatedSharing', 'ocRoomShare', 'ocCircleShare', 'deck'] as $prefix) {
			try {
				return $this->shareManager->getShareById($prefix . ':' . $id, $userId);
			} catch (\Throwable) {
				// Not this provider's; try the next.
			}
		}
		return null;
	}

	private function node(string $userId, int $fileId): ?\OCP\Files\Node {
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (\Throwable) {
			return null;
		}
		$found = $userFolder->getById($fileId);
		return $found[0] ?? null;
	}

	private function relativePath(string $userId, string $path): string {
		$marker = '/' . $userId . '/files/';
		$at = strpos($path, $marker);
		return $at === false ? $path : substr($path, $at + strlen($marker));
	}

	private function shortLinksAvailable(): bool {
		return $this->appManager->isEnabledForUser('shortcloud')
			&& class_exists('\\OCA\\Shortcloud\\Service\\LinkService');
	}
}
