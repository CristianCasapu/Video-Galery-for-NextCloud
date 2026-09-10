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
 * @method string getPath()
 * @method void setPath(string $path)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getMimetype()
 * @method void setMimetype(string $mimetype)
 * @method int getSize()
 * @method void setSize(int $size)
 * @method int getMtime()
 * @method void setMtime(int $mtime)
 * @method int getTakenAt()
 * @method void setTakenAt(int $takenAt)
 * @method string getDateSource()
 * @method void setDateSource(string $dateSource)
 * @method int getDurationMs()
 * @method void setDurationMs(int $durationMs)
 * @method int getWidth()
 * @method void setWidth(int $width)
 * @method int getHeight()
 * @method void setHeight(int $height)
 * @method int getRotation()
 * @method void setRotation(int $rotation)
 * @method float getFps()
 * @method void setFps(float $fps)
 * @method int getBitrate()
 * @method void setBitrate(int $bitrate)
 * @method string getContainer()
 * @method void setContainer(string $container)
 * @method string getVcodec()
 * @method void setVcodec(string $vcodec)
 * @method string getVprofile()
 * @method void setVprofile(string $vprofile)
 * @method int getVlevel()
 * @method void setVlevel(int $vlevel)
 * @method string getPixFmt()
 * @method void setPixFmt(string $pixFmt)
 * @method int getBitDepth()
 * @method void setBitDepth(int $bitDepth)
 * @method int getHdr()
 * @method void setHdr(int $hdr)
 * @method string getAcodec()
 * @method void setAcodec(string $acodec)
 * @method int getAchannels()
 * @method void setAchannels(int $achannels)
 * @method string|null getAudioTracks()
 * @method void setAudioTracks(?string $audioTracks)
 * @method string|null getSubTracks()
 * @method void setSubTracks(?string $subTracks)
 * @method string|null getChapters()
 * @method void setChapters(?string $chapters)
 * @method string getPlayMode()
 * @method void setPlayMode(string $playMode)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getFailReason()
 * @method void setFailReason(?string $failReason)
 * @method int getProbeVersion()
 * @method void setProbeVersion(int $probeVersion)
 * @method int getIndexedAt()
 * @method void setIndexedAt(int $indexedAt)
 * @method int getAssets()
 * @method void setAssets(int $assets)
 */
class Item extends Entity implements \JsonSerializable {
	/** Bit flags recording which cached assets exist for this item. */
	public const ASSET_POSTER = 1;
	public const ASSET_LOOP = 2;
	public const ASSET_SPRITE = 4;

	protected string $userId = '';
	protected int $fileId = 0;
	protected string $path = '';
	protected string $name = '';
	protected string $mimetype = '';
	protected int $size = 0;
	protected int $mtime = 0;
	protected int $takenAt = 0;
	protected string $dateSource = 'mtime';
	protected int $durationMs = 0;
	protected int $width = 0;
	protected int $height = 0;
	protected int $rotation = 0;
	protected float $fps = 0.0;
	protected int $bitrate = 0;
	protected string $container = '';
	protected string $vcodec = '';
	protected string $vprofile = '';
	protected int $vlevel = 0;
	protected string $pixFmt = '';
	protected int $bitDepth = 8;
	protected int $hdr = 0;
	protected string $acodec = '';
	protected int $achannels = 0;
	protected ?string $audioTracks = null;
	protected ?string $subTracks = null;
	protected ?string $chapters = null;
	protected string $playMode = '';
	protected string $status = 'pending';
	protected ?string $failReason = null;
	protected int $probeVersion = 0;
	protected int $indexedAt = 0;
	protected int $assets = 0;

	public function __construct() {
		$this->addType('fileId', 'integer');
		$this->addType('size', 'integer');
		$this->addType('mtime', 'integer');
		$this->addType('takenAt', 'integer');
		$this->addType('durationMs', 'integer');
		$this->addType('width', 'integer');
		$this->addType('height', 'integer');
		$this->addType('rotation', 'integer');
		$this->addType('fps', 'float');
		$this->addType('bitrate', 'integer');
		$this->addType('vlevel', 'integer');
		$this->addType('bitDepth', 'integer');
		$this->addType('hdr', 'integer');
		$this->addType('achannels', 'integer');
		$this->addType('probeVersion', 'integer');
		$this->addType('indexedAt', 'integer');
		$this->addType('assets', 'integer');
	}

	/** @return list<array<string, mixed>> */
	public function audioTrackList(): array {
		$decoded = json_decode((string)$this->audioTracks, true);
		return is_array($decoded) ? $decoded : [];
	}

	/** @return list<array<string, mixed>> */
	public function subTrackList(): array {
		$decoded = json_decode((string)$this->subTracks, true);
		return is_array($decoded) ? $decoded : [];
	}

	/** @return list<array<string, mixed>> */
	public function chapterList(): array {
		$decoded = json_decode((string)$this->chapters, true);
		return is_array($decoded) ? $decoded : [];
	}

	public function hasAsset(int $flag): bool {
		return ($this->assets & $flag) === $flag;
	}

	/** Landscape or portrait, once the rotation flag is taken into account. */
	public function displayWidth(): int {
		return in_array($this->rotation, [90, 270], true) ? $this->height : $this->width;
	}

	public function displayHeight(): int {
		return in_array($this->rotation, [90, 270], true) ? $this->width : $this->height;
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'fileId' => $this->fileId,
			'path' => $this->path,
			'name' => $this->name,
			'basename' => pathinfo($this->name, PATHINFO_FILENAME),
			'folder' => trim(dirname($this->path), '.'),
			'mimetype' => $this->mimetype,
			'size' => $this->size,
			'mtime' => $this->mtime,
			'takenAt' => $this->takenAt,
			'dateSource' => $this->dateSource,
			'duration' => (int)round($this->durationMs / 1000),
			'durationMs' => $this->durationMs,
			'width' => $this->displayWidth(),
			'height' => $this->displayHeight(),
			'rotation' => $this->rotation,
			'fps' => $this->fps,
			'bitrate' => $this->bitrate,
			'container' => $this->container,
			'vcodec' => $this->vcodec,
			'vprofile' => $this->vprofile,
			'pixFmt' => $this->pixFmt,
			'bitDepth' => $this->bitDepth,
			'hdr' => $this->hdr === 1,
			'acodec' => $this->acodec,
			'achannels' => $this->achannels,
			'audioTracks' => $this->audioTrackList(),
			'subTracks' => $this->subTrackList(),
			'chapters' => $this->chapterList(),
			'status' => $this->status,
			'hasPoster' => $this->hasAsset(self::ASSET_POSTER),
			'hasLoop' => $this->hasAsset(self::ASSET_LOOP),
			'hasSprite' => $this->hasAsset(self::ASSET_SPRITE),
		];
	}
}
