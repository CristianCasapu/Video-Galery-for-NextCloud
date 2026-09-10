<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A file this app put on the cache disk. Every such file has a row; a file
 * without one is rubbish and gets swept.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getRelPath()
 * @method void setRelPath(string $relPath)
 * @method int getSize()
 * @method void setSize(int $size)
 * @method int getVersion()
 * @method void setVersion(int $version)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getLastUsed()
 * @method void setLastUsed(int $lastUsed)
 */
class Asset extends Entity implements \JsonSerializable {
	public const POSTER = 'poster';
	public const LOOP = 'loop';
	public const SPRITE = 'sprite';

	protected string $userId = '';
	protected int $fileId = 0;
	protected string $kind = '';
	protected string $relPath = '';
	protected int $size = 0;
	protected int $version = 1;
	protected int $createdAt = 0;
	protected int $lastUsed = 0;

	public function __construct() {
		$this->addType('fileId', 'integer');
		$this->addType('size', 'integer');
		$this->addType('version', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('lastUsed', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'fileId' => $this->fileId,
			'kind' => $this->kind,
			'size' => $this->size,
			'createdAt' => $this->createdAt,
			'lastUsed' => $this->lastUsed,
		];
	}
}
