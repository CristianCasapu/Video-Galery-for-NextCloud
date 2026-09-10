<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One playback session: a directory on the cache disk, and usually an ffmpeg
 * process feeding it. The row exists so nothing on that disk is ever anonymous.
 *
 * @method string getToken()
 * @method void setToken(string $token)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getMode()
 * @method void setMode(string $mode)
 * @method string getProfile()
 * @method void setProfile(string $profile)
 * @method string getEncoder()
 * @method void setEncoder(string $encoder)
 * @method string getDir()
 * @method void setDir(string $dir)
 * @method int getPid()
 * @method void setPid(int $pid)
 * @method int getStartSegment()
 * @method void setStartSegment(int $startSegment)
 * @method int getSegmentDur()
 * @method void setSegmentDur(int $segmentDur)
 * @method int getTotalSegments()
 * @method void setTotalSegments(int $totalSegments)
 * @method int getDurationMs()
 * @method void setDurationMs(int $durationMs)
 * @method string getState()
 * @method void setState(string $state)
 * @method int getAudioIndex()
 * @method void setAudioIndex(int $audioIndex)
 * @method int getGeneration()
 * @method void setGeneration(int $generation)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getLastSeen()
 * @method void setLastSeen(int $lastSeen)
 * @method string getSegmentType()
 * @method void setSegmentType(string $segmentType)
 * @method string|null getError()
 * @method void setError(?string $error)
 */
class Session extends Entity implements \JsonSerializable {
	public const STARTING = 'starting';
	public const RUNNING = 'running';
	public const FINISHED = 'finished';
	public const FAILED = 'failed';
	public const DEAD = 'dead';

	protected string $token = '';
	protected string $userId = '';
	protected int $fileId = 0;
	protected string $mode = '';
	protected string $profile = '';
	protected string $encoder = '';
	protected string $dir = '';
	protected int $pid = 0;
	protected int $startSegment = 0;
	protected int $segmentDur = 4;
	protected int $totalSegments = 0;
	protected int $durationMs = 0;
	protected string $segmentType = 'ts';
	protected string $state = self::STARTING;
	protected int $audioIndex = -1;
	protected int $generation = 1;
	protected int $createdAt = 0;
	protected int $lastSeen = 0;
	protected ?string $error = null;

	public function __construct() {
		$this->addType('fileId', 'integer');
		$this->addType('pid', 'integer');
		$this->addType('startSegment', 'integer');
		$this->addType('segmentDur', 'integer');
		$this->addType('totalSegments', 'integer');
		$this->addType('durationMs', 'integer');
		$this->addType('audioIndex', 'integer');
		$this->addType('generation', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('lastSeen', 'integer');
	}

	public function isLive(): bool {
		return in_array($this->state, [self::STARTING, self::RUNNING], true);
	}

	public function jsonSerialize(): array {
		return [
			'token' => $this->token,
			'fileId' => $this->fileId,
			'mode' => $this->mode,
			'profile' => $this->profile,
			'encoder' => $this->encoder,
			'state' => $this->state,
			'segmentDuration' => $this->segmentDur,
			'segmentType' => $this->segmentType,
			'totalSegments' => $this->totalSegments,
			'duration' => (int)round($this->durationMs / 1000),
			'startSegment' => $this->startSegment,
			'audioIndex' => $this->audioIndex,
			'generation' => $this->generation,
			'createdAt' => $this->createdAt,
			'error' => $this->error,
		];
	}
}
