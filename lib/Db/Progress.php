<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method int getPositionMs()
 * @method void setPositionMs(int $positionMs)
 * @method int getDurationMs()
 * @method void setDurationMs(int $durationMs)
 * @method int getFinished()
 * @method void setFinished(int $finished)
 * @method int getAudioIndex()
 * @method void setAudioIndex(int $audioIndex)
 * @method int getSubIndex()
 * @method void setSubIndex(int $subIndex)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Progress extends Entity implements \JsonSerializable {
	protected string $userId = '';
	protected int $fileId = 0;
	protected int $positionMs = 0;
	protected int $durationMs = 0;
	protected int $finished = 0;
	protected int $audioIndex = -1;
	protected int $subIndex = -1;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('fileId', 'integer');
		$this->addType('positionMs', 'integer');
		$this->addType('durationMs', 'integer');
		$this->addType('finished', 'integer');
		$this->addType('audioIndex', 'integer');
		$this->addType('subIndex', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	public function percent(): float {
		return $this->durationMs > 0 ? min(100.0, ($this->positionMs / $this->durationMs) * 100) : 0.0;
	}

	public function jsonSerialize(): array {
		return [
			'fileId' => $this->fileId,
			'position' => (int)round($this->positionMs / 1000),
			'positionMs' => $this->positionMs,
			'duration' => (int)round($this->durationMs / 1000),
			'percent' => round($this->percent(), 1),
			'finished' => $this->finished === 1,
			'audioIndex' => $this->audioIndex,
			'subIndex' => $this->subIndex,
			'updatedAt' => $this->updatedAt,
		];
	}
}
