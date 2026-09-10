<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Service;

use OCA\VideoGallery\Db\Asset;
use OCA\VideoGallery\Db\AssetMapper;
use OCA\VideoGallery\Db\Item;

/**
 * Works out where a stream will be cut into segments, before any of it exists.
 *
 * The playlist handed to the player lists every segment of the film from the
 * outset, including the ones nothing has produced yet — that is what lets
 * someone drag the progress bar to the ninety minute mark and have it start
 * playing there. For that to hold together, the durations written in the
 * playlist have to be the durations the segments really turn out to have.
 *
 * When the video is being re-encoded that is easy: a keyframe is forced at
 * every boundary, so the boundaries are wherever we say they are. When the
 * video is being copied through untouched it cannot be: a stream can only be
 * cut where a keyframe already is, and those sit wherever the original encoder
 * put them. So the keyframes are read out of the file once, and the same rule
 * ffmpeg uses to group them is applied here, which yields exactly the cuts it
 * will make.
 */
class SegmentPlan {
	public function __construct(
		private Probe $probe,
		private AssetMapper $assets,
		private Paths $paths,
	) {
	}

	/**
	 * Segment start times, in seconds, for a session of the given mode.
	 *
	 * @return list<float>
	 */
	public function build(Item $item, string $localPath, string $mode, int $segmentDuration): array {
		$totalSeconds = $item->getDurationMs() / 1000;
		if ($totalSeconds <= 0) {
			return [0.0];
		}
		if ($mode === PlaybackDecision::TRANSCODE) {
			return $this->fixed($totalSeconds, $segmentDuration);
		}

		$keyframes = $this->cachedKeyframes($item, $localPath);
		if (count($keyframes) < 2) {
			// Nothing to go on. Even boundaries are then a guess, but a guess that
			// only costs a small drift in the seek bar rather than a broken stream.
			return $this->fixed($totalSeconds, $segmentDuration);
		}
		return $this->group($keyframes, $totalSeconds, $segmentDuration);
	}

	/** @return list<float> */
	private function fixed(float $totalSeconds, int $segmentDuration): array {
		$boundaries = [];
		for ($t = 0.0; $t < $totalSeconds; $t += $segmentDuration) {
			$boundaries[] = round($t, 6);
		}
		return $boundaries === [] ? [0.0] : $boundaries;
	}

	/**
	 * Group keyframes the way the HLS muxer does when it is copying: a new
	 * segment begins at the first keyframe that is at least a segment's worth
	 * past where the current one started.
	 *
	 * @param list<float> $keyframes
	 * @return list<float>
	 */
	private function group(array $keyframes, float $totalSeconds, int $segmentDuration): array {
		$boundaries = [$keyframes[0] <= 0.05 ? 0.0 : $keyframes[0]];
		$current = $boundaries[0];
		foreach ($keyframes as $keyframe) {
			if ($keyframe - $current >= $segmentDuration - 0.001) {
				$boundaries[] = round($keyframe, 6);
				$current = $keyframe;
			}
		}
		// A trailing sliver shorter than a second is not worth its own segment;
		// it is left as part of the one before it.
		$last = end($boundaries);
		if (count($boundaries) > 1 && $totalSeconds - $last < 1.0) {
			array_pop($boundaries);
		}
		return $boundaries;
	}

	/**
	 * Keyframe positions for a file, read once and kept, because walking a large
	 * film to find them is not something to repeat every time it is opened.
	 *
	 * @return list<float>
	 */
	private function cachedKeyframes(Item $item, string $localPath): array {
		$path = $this->cachePath($item->getFileId());
		if (is_file($path)) {
			$decoded = json_decode((string)file_get_contents($path), true);
			if (is_array($decoded) && $decoded !== []) {
				$this->assets->touch($item->getFileId(), 'keyframes');
				return array_map('floatval', $decoded);
			}
		}
		$keyframes = $this->probe->keyframes($localPath);
		if ($keyframes === []) {
			return [];
		}
		$this->paths->ensureParent($path);
		if (@file_put_contents($path, (string)json_encode($keyframes)) !== false) {
			$this->record($item, $path);
		}
		return $keyframes;
	}

	private function cachePath(int $fileId): string {
		$hash = substr(md5((string)$fileId), 0, 4);
		return $this->paths->root() . '/previews/' . substr($hash, 0, 2) . '/' . $fileId . '.keyframes.json';
	}

	private function record(Item $item, string $path): void {
		if ($this->assets->find($item->getFileId(), 'keyframes') !== null) {
			return;
		}
		$asset = new Asset();
		$asset->setUserId($item->getUserId());
		$asset->setFileId($item->getFileId());
		$asset->setKind('keyframes');
		$asset->setRelPath($this->paths->relative($path));
		$asset->setSize((int)filesize($path));
		$asset->setCreatedAt(time());
		$asset->setLastUsed(time());
		try {
			$this->assets->insert($asset);
		} catch (\Throwable) {
			// recorded concurrently
		}
	}

	/**
	 * Segment durations that go with a set of boundaries.
	 *
	 * @param list<float> $boundaries
	 * @return list<float>
	 */
	public function durations(array $boundaries, float $totalSeconds): array {
		$durations = [];
		$count = count($boundaries);
		for ($i = 0; $i < $count; $i++) {
			$end = $i + 1 < $count ? $boundaries[$i + 1] : $totalSeconds;
			$durations[] = max(0.001, round($end - $boundaries[$i], 6));
		}
		return $durations;
	}
}
