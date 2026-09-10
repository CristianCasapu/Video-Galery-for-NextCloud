<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Service;

use OCA\VideoGallery\Db\Asset;
use OCA\VideoGallery\Db\AssetMapper;
use OCA\VideoGallery\Db\Item;

/**
 * Pulls subtitle tracks out of the container and converts them to the one
 * format a browser understands.
 *
 * Only text tracks can make the trip. Picture-based subtitles, the kind on
 * DVDs and Blu-rays, are images rather than words, and the only way to show
 * them is to paint them onto the video during transcoding.
 */
class SubtitleService {
	public function __construct(
		private AssetMapper $assets,
		private FFmpeg $ffmpeg,
		private FileResolver $resolver,
		private Paths $paths,
	) {
	}

	/** Where a converted track is kept. */
	public function path(int $fileId, int $index): string {
		$hash = substr(md5((string)$fileId), 0, 4);
		return $this->paths->root() . '/subtitles/' . substr($hash, 0, 2) . '/' . $fileId . '-' . $index . '.vtt';
	}

	/** The track as WebVTT, converting it the first time it is asked for. */
	public function ensure(Item $item, int $index): ?string {
		$track = null;
		foreach ($item->subTrackList() as $candidate) {
			if ((int)($candidate['index'] ?? -1) === $index) {
				$track = $candidate;
				break;
			}
		}
		if ($track === null || ($track['textual'] ?? false) !== true) {
			return null;
		}

		$path = $this->path($item->getFileId(), $index);
		if (is_file($path)) {
			$this->assets->touch($item->getFileId(), 'sub' . $index);
			return $path;
		}
		$this->paths->ensureParent($path);

		$file = $this->resolver->getFile($item->getUserId(), $item->getFileId());
		if ($file === null) {
			return null;
		}
		$resolved = $this->resolver->localPath($file);
		if ($resolved === null) {
			return null;
		}
		$binary = $this->ffmpeg->ffmpeg();
		if ($binary === null) {
			$this->resolver->release($resolved);
			return null;
		}
		try {
			$result = $this->ffmpeg->run([
				$binary, '-hide_banner', '-loglevel', 'error', '-nostdin', '-y',
				'-i', $resolved['path'],
				'-map', '0:' . $index,
				'-c:s', 'webvtt',
				'-f', 'webvtt',
				$path,
			], 180);
		} finally {
			$this->resolver->release($resolved);
		}
		if ($result['code'] !== 0 || !is_file($path) || filesize($path) === 0) {
			@unlink($path);
			return null;
		}
		$this->record($item, $index, $path);
		return $path;
	}

	private function record(Item $item, int $index, string $path): void {
		$asset = new Asset();
		$asset->setUserId($item->getUserId());
		$asset->setFileId($item->getFileId());
		$asset->setKind('sub' . $index);
		$asset->setRelPath($this->paths->relative($path));
		$asset->setSize((int)filesize($path));
		$asset->setCreatedAt(time());
		$asset->setLastUsed(time());
		try {
			$this->assets->insert($asset);
		} catch (\Throwable) {
			// already recorded by a concurrent request
		}
	}

	/**
	 * The tracks worth offering in the player, with the ones that cannot be
	 * converted marked as such rather than silently dropped.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function describe(Item $item): array {
		$out = [];
		foreach ($item->subTrackList() as $track) {
			$out[] = [
				'index' => (int)($track['index'] ?? 0),
				'language' => (string)($track['language'] ?? ''),
				'label' => $this->label($track),
				'default' => (int)($track['default'] ?? 0) === 1,
				'forced' => (int)($track['forced'] ?? 0) === 1,
				'available' => ($track['textual'] ?? false) === true,
				'codec' => (string)($track['codec'] ?? ''),
			];
		}
		return $out;
	}

	/** @param array<string, mixed> $track */
	private function label(array $track): string {
		$title = trim((string)($track['title'] ?? ''));
		if ($title !== '') {
			return $title;
		}
		$language = (string)($track['language'] ?? '');
		if ($language !== '') {
			return strtoupper($language);
		}
		return 'Track ' . (int)($track['index'] ?? 0);
	}
}
