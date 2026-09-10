<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Service;

use OCA\VideoGallery\Db\Asset;
use OCA\VideoGallery\Db\AssetMapper;
use OCA\VideoGallery\Db\Item;
use OCA\VideoGallery\Db\ItemMapper;
use Psr\Log\LoggerInterface;

/**
 * Makes the pictures the library is built out of: the cover frame, the short
 * silent clip that plays under the pointer, and the strip of thumbnails shown
 * while dragging along the progress bar.
 *
 * Each one is made once and kept. Two viewers hovering the same card at the
 * same moment do not start two encoders: the second waits on a lock and then
 * finds the file already there.
 */
class PreviewService {
	public function __construct(
		private AssetMapper $assets,
		private ItemMapper $items,
		private FFmpeg $ffmpeg,
		private FileResolver $resolver,
		private Paths $paths,
		private Config $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The path to a ready asset, making it first if need be.
	 *
	 * @param 'poster'|'loop'|'sprite' $kind
	 */
	public function ensure(Item $item, string $kind, bool $allowGenerate = true): ?string {
		$path = $this->paths->assetPath($item->getFileId(), $kind);
		if (is_file($path)) {
			$this->assets->touch($item->getFileId(), $kind);
			return $path;
		}
		$existing = $this->assets->find($item->getFileId(), $kind);
		if ($existing !== null) {
			// The row outlived its file; drop it so it can be made again.
			try {
				$this->assets->delete($existing);
			} catch (\Throwable) {
			}
			$this->items->setAssetFlag($item->getFileId(), $this->flagFor($kind), false);
		}
		if (!$allowGenerate || !$this->config->getBool('preview_enabled')) {
			return null;
		}
		return $this->generate($item, $kind);
	}

	/** Make one asset, under a lock so it is only made once. */
	public function generate(Item $item, string $kind): ?string {
		$path = $this->paths->assetPath($item->getFileId(), $kind);
		$this->paths->ensureParent($path);

		$lockFile = $path . '.lock';
		$lock = @fopen($lockFile, 'c');
		if ($lock === false) {
			return null;
		}
		try {
			if (!flock($lock, LOCK_EX | LOCK_NB)) {
				// Someone else is making it. Wait for them rather than duplicating
				// the work, then use what they produced.
				flock($lock, LOCK_EX);
				flock($lock, LOCK_UN);
				return is_file($path) ? $path : null;
			}
			if (is_file($path)) {
				return $path;
			}

			$file = $this->resolver->getFile($item->getUserId(), $item->getFileId());
			if ($file === null) {
				return null;
			}
			$resolved = $this->resolver->localPath($file);
			if ($resolved === null) {
				return null;
			}
			try {
				$ok = match ($kind) {
					Asset::POSTER => $this->makePoster($item, $resolved['path'], $path),
					Asset::LOOP => $this->makeLoop($item, $resolved['path'], $path),
					Asset::SPRITE => $this->makeSprite($item, $resolved['path'], $path),
					default => false,
				};
			} finally {
				$this->resolver->release($resolved);
			}
			if (!$ok || !is_file($path) || filesize($path) === 0) {
				@unlink($path);
				return null;
			}
			$this->record($item, $kind, $path);
			return $path;
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
			@unlink($lockFile);
		}
	}

	private function record(Item $item, string $kind, string $path): void {
		$asset = new Asset();
		$asset->setUserId($item->getUserId());
		$asset->setFileId($item->getFileId());
		$asset->setKind($kind);
		$asset->setRelPath($this->paths->relative($path));
		$asset->setSize((int)filesize($path));
		$asset->setVersion(1);
		$asset->setCreatedAt(time());
		$asset->setLastUsed(time());
		try {
			$this->assets->insert($asset);
		} catch (\Throwable) {
			// A racing generator already recorded it.
		}
		$this->items->setAssetFlag($item->getFileId(), $this->flagFor($kind), true);
	}

	private function flagFor(string $kind): int {
		return match ($kind) {
			Asset::POSTER => Item::ASSET_POSTER,
			Asset::LOOP => Item::ASSET_LOOP,
			Asset::SPRITE => Item::ASSET_SPRITE,
			default => 0,
		};
	}

	/**
	 * The moment a still is taken from. The very first frame of a video is
	 * usually black, a logo, or a hand reaching for the camera.
	 */
	private function sampleAt(Item $item): float {
		$seconds = $item->getDurationMs() / 1000;
		if ($seconds <= 0) {
			return 0.0;
		}
		$percent = $this->config->getInt('preview_start_percent') / 100;
		$at = $seconds * $percent;
		if ($seconds < 10) {
			$at = $seconds * 0.2;
		}
		return max(0.0, min($at, max(0.0, $seconds - 1)));
	}

	private function makePoster(Item $item, string $input, string $output): bool {
		$binary = $this->ffmpeg->ffmpeg();
		if ($binary === null) {
			return false;
		}
		$height = max(360, $this->config->getInt('preview_height'));
		$args = [
			$binary, '-hide_banner', '-loglevel', 'error', '-nostdin', '-y',
			'-ss', (string)$this->sampleAt($item),
			'-i', $input,
			'-frames:v', '1',
			// A frame with something in it: the least similar to a flat colour
			// out of a short run, which skips fades and black leader.
			'-vf', 'thumbnail=60,' . $this->scaleFilter($height) . $this->rotateFilter($item),
			'-q:v', '3',
			$output,
		];
		$result = $this->ffmpeg->run($args, 120);
		if ($result['code'] !== 0 || !is_file($output)) {
			// Some files will not give up a frame that way; take a plain one.
			$fallback = [
				$binary, '-hide_banner', '-loglevel', 'error', '-nostdin', '-y',
				'-ss', (string)$this->sampleAt($item),
				'-i', $input,
				'-frames:v', '1',
				'-vf', $this->scaleFilter($height) . $this->rotateFilter($item),
				'-q:v', '3',
				$output,
			];
			$result = $this->ffmpeg->run($fallback, 120);
		}
		return $result['code'] === 0;
	}

	/**
	 * The short clip that plays under the pointer: a few seconds, no sound,
	 * small enough that it starts instantly on any connection.
	 */
	private function makeLoop(Item $item, string $input, string $output): bool {
		$binary = $this->ffmpeg->ffmpeg();
		if ($binary === null) {
			return false;
		}
		$seconds = min((float)$this->config->getInt('preview_seconds'), max(1.0, $item->getDurationMs() / 1000));
		$height = $this->config->getInt('preview_height');
		$family = $this->ffmpeg->chosenEncoder();

		$args = [
			$binary, '-hide_banner', '-loglevel', 'error', '-nostdin', '-y',
			'-ss', (string)$this->sampleAt($item),
			'-t', (string)$seconds,
			'-i', $input,
			'-an', '-sn', '-dn',
			'-vf', $this->scaleFilter($height) . $this->rotateFilter($item) . ',fps=' . $this->config->getInt('preview_fps'),
		];
		// Hardware encoding when it is there, but always software decoding: these
		// are short clips and a decoder set up per clip costs more than it saves.
		if ($family === 'nvenc') {
			$args = array_merge($args, ['-c:v', 'h264_nvenc', '-preset', 'p4',
				'-cq', (string)$this->config->getInt('preview_crf'), '-profile:v', 'main']);
		} elseif ($family === 'vaapi') {
			$args = array_merge($args, ['-vaapi_device', $this->config->getString('vaapi_device')]);
			$args[count($args) - 3] = '-vf';
			$args[count($args) - 2] = $this->scaleFilter($height) . $this->rotateFilter($item)
				. ',fps=' . $this->config->getInt('preview_fps') . ',format=nv12,hwupload';
			$args = array_merge($args, ['-c:v', 'h264_vaapi']);
		} else {
			$args = array_merge($args, ['-c:v', 'libx264', '-preset', 'veryfast',
				'-crf', (string)$this->config->getInt('preview_crf'), '-profile:v', 'main', '-pix_fmt', 'yuv420p']);
		}
		$args = array_merge($args, [
			'-movflags', '+faststart+frag_keyframe+empty_moov',
			'-max_muxing_queue_size', '512',
			$output,
		]);
		$result = $this->ffmpeg->run($args, 180);
		if ($result['code'] === 0) {
			return true;
		}

		// The graphics card may be out of reach from whichever process this is.
		// A six second clip is no hardship for the processor, so rather than
		// giving up, make it the plain way.
		if ($family === 'software') {
			return false;
		}
		$fallback = [
			$binary, '-hide_banner', '-loglevel', 'error', '-nostdin', '-y',
			'-ss', (string)$this->sampleAt($item),
			'-t', (string)$seconds,
			'-i', $input,
			'-an', '-sn', '-dn',
			'-vf', $this->scaleFilter($height) . $this->rotateFilter($item) . ',fps=' . $this->config->getInt('preview_fps'),
			'-c:v', 'libx264', '-preset', 'veryfast',
			'-crf', (string)$this->config->getInt('preview_crf'), '-profile:v', 'main', '-pix_fmt', 'yuv420p',
			'-movflags', '+faststart+frag_keyframe+empty_moov',
			'-max_muxing_queue_size', '512',
			$output,
		];
		return $this->ffmpeg->run($fallback, 240)['code'] === 0;
	}

	/**
	 * One image holding a grid of stills taken evenly across the film, so
	 * dragging the progress bar can show where you are going without asking the
	 * server for anything.
	 */
	private function makeSprite(Item $item, string $input, string $output): bool {
		$binary = $this->ffmpeg->ffmpeg();
		if ($binary === null || $item->getDurationMs() <= 0) {
			return false;
		}
		$columns = max(2, $this->config->getInt('sprite_columns'));
		$rows = max(2, $this->config->getInt('sprite_rows'));
		$width = max(80, $this->config->getInt('sprite_width'));
		$tiles = $columns * $rows;
		$seconds = $item->getDurationMs() / 1000;
		// One frame every so many seconds, chosen so the grid spans the film.
		$interval = max(0.5, $seconds / $tiles);

		$args = [
			$binary, '-hide_banner', '-loglevel', 'error', '-nostdin', '-y',
			'-i', $input,
			'-frames:v', '1',
			'-vf', sprintf(
				'fps=1/%.4f,scale=%d:-2%s,tile=%dx%d',
				$interval,
				$width,
				$this->rotateFilter($item),
				$columns,
				$rows,
			),
			'-q:v', '5',
			$output,
		];
		$result = $this->ffmpeg->run($args, 600);
		return $result['code'] === 0;
	}

	private function scaleFilter(int $height): string {
		// Even dimensions, because H.264 insists on them.
		return 'scale=-2:' . ($height - ($height % 2));
	}

	private function rotateFilter(Item $item): string {
		return match ($item->getRotation()) {
			90 => ',transpose=1',
			180 => ',transpose=1,transpose=1',
			270 => ',transpose=2',
			default => '',
		};
	}

	/** How the sprite is laid out, so the player can index into it. */
	public function spriteLayout(Item $item): array {
		$columns = max(2, $this->config->getInt('sprite_columns'));
		$rows = max(2, $this->config->getInt('sprite_rows'));
		$tiles = $columns * $rows;
		$seconds = $item->getDurationMs() / 1000;
		return [
			'columns' => $columns,
			'rows' => $rows,
			'tiles' => $tiles,
			'width' => max(80, $this->config->getInt('sprite_width')),
			'interval' => $tiles > 0 ? round($seconds / $tiles, 4) : 0,
		];
	}

	/**
	 * Work through items that have no cached assets yet, newest first.
	 *
	 * @return array<string, int>
	 */
	public function prewarm(int $limit, ?string $userId = null): array {
		$made = ['poster' => 0, 'loop' => 0, 'sprite' => 0, 'failed' => 0];
		if (!$this->config->getBool('preview_enabled')) {
			return $made;
		}
		$wanted = [Asset::POSTER => Item::ASSET_POSTER, Asset::LOOP => Item::ASSET_LOOP];
		if ($this->config->getBool('sprite_enabled')) {
			$wanted[Asset::SPRITE] = Item::ASSET_SPRITE;
		}
		$budget = $limit;
		foreach ($wanted as $kind => $flag) {
			if ($budget <= 0) {
				break;
			}
			foreach ($this->items->missingAssets($flag, $budget, $userId) as $item) {
				try {
					$path = $this->generate($item, $kind);
					if ($path !== null) {
						$made[$kind]++;
					} else {
						$made['failed']++;
					}
				} catch (\Throwable $e) {
					$made['failed']++;
					$this->logger->debug('Video Gallery could not make a ' . $kind . ' for ' . $item->getName() . ': ' . $e->getMessage());
				}
				$budget--;
				if ($budget <= 0) {
					break;
				}
			}
		}
		return $made;
	}
}
