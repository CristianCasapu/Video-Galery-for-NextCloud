<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Service;

use OCA\VideoGallery\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Every administrator-facing setting, with its default and its type, in one place.
 * Nothing else in the app reads app config directly.
 */
class Config {
	public const DEFAULTS = [
		// Where the scratch disk is. Everything the app writes outside the database
		// lives under this one directory and nowhere else.
		'cache_root' => '/srv/cache/videogallery',
		'cache_max_gb' => 50.0,

		'transcode_enabled' => true,
		'direct_play_enabled' => true,
		// auto picks the best of nvenc / vaapi / software that actually works here.
		'encoder' => 'auto',
		'nvenc_device' => 0,
		'vaapi_device' => '/dev/dri/renderD128',
		'nvenc_preset' => 'p4',
		'x264_preset' => 'veryfast',
		'hw_decode' => true,
		'max_sessions' => 3,
		'software_fallback' => true,
		'queue_wait_seconds' => 25,
		// Shorter segments mean the first one is finished sooner, which is the
		// wait between pressing play and the picture appearing.
		'segment_duration' => 3,
		'session_ttl' => 60,
		'session_max_life' => 86400,
		'quality_ladder' => [
			['id' => 'original', 'label' => 'Original', 'height' => 0, 'bitrate' => 0],
			['id' => '1080p', 'label' => '1080p', 'height' => 1080, 'bitrate' => 8000],
			['id' => '720p', 'label' => '720p', 'height' => 720, 'bitrate' => 4000],
			['id' => '480p', 'label' => '480p', 'height' => 480, 'bitrate' => 1500],
			['id' => '360p', 'label' => '360p', 'height' => 360, 'bitrate' => 800],
		],

		// Measuring the link before playing, so a file is only sent untouched when
		// the connection can actually carry it.
		'bandwidth_probe_enabled' => true,
		'bandwidth_probe_bytes' => 3145728,
		// Headroom over the measured speed a stream must fit inside. 1.0 would mean
		// betting that a link never dips below its average, which it always does.
		'bandwidth_safety_factor' => 1.5,
		'bandwidth_probe_ttl' => 900,
		'adaptive_downshift' => true,
		'stall_threshold' => 3,
		// The player keeps reporting what it is actually getting, and the quality
		// is walked back up as soon as the link proves it can hold the next rung.
		'governor_enabled' => true,
		'governor_interval' => 10,
		'min_buffer_seconds' => 6.0,
		'upshift_stable_seconds' => 45,
		'upshift_hysteresis' => 1.3,
		'shift_cooldown' => 20,

		'preview_enabled' => true,
		'preview_seconds' => 6,
		'preview_height' => 480,
		'preview_start_percent' => 20,
		'preview_ttl_days' => 120,
		'prewarm_enabled' => true,
		'prewarm_batch' => 25,
		'sprite_enabled' => true,
		'sprite_columns' => 10,
		'sprite_rows' => 10,
		'sprite_width' => 240,

		// Learning from what has actually worked, rather than deciding afresh
		// every time from what a browser claims about itself.
		'memory_enabled' => true,
		// Start the encoder the moment a file is opened, instead of waiting for
		// the player to ask for the first segment.
		'instant_start' => true,

		// The folder a viewer's own videos are expected to live in. Created for
		// each account, given its own row, and offered as the place to put things.
		'default_folder' => 'Video',

		'index_batch' => 200,
		'min_duration_seconds' => 0,
		'excluded_paths' => [],
		'external_player_enabled' => true,
		'external_token_ttl' => 21600,

		'ffmpeg_path' => '',
		'ffprobe_path' => '',
		'nice_level' => 5,
		'io_class' => 'idle',
	];

	/** Values that are stored as JSON rather than as a scalar. */
	private const JSON_KEYS = ['quality_ladder', 'excluded_paths'];

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public function get(string $key): mixed {
		if (!array_key_exists($key, self::DEFAULTS)) {
			throw new \InvalidArgumentException('Unknown setting ' . $key);
		}
		$default = self::DEFAULTS[$key];
		$raw = $this->appConfig->getValueString(Application::APP_ID, $key, '__unset__');
		if ($raw === '__unset__') {
			return $default;
		}
		if (in_array($key, self::JSON_KEYS, true)) {
			$decoded = json_decode($raw, true);
			return is_array($decoded) ? $decoded : $default;
		}
		return match (true) {
			is_bool($default) => $raw === '1' || $raw === 'true',
			is_int($default) => (int)$raw,
			is_float($default) => (float)$raw,
			default => $raw,
		};
	}

	public function getString(string $key): string {
		return (string)$this->get($key);
	}

	public function getInt(string $key): int {
		return (int)$this->get($key);
	}

	public function getFloat(string $key): float {
		return (float)$this->get($key);
	}

	public function getBool(string $key): bool {
		return (bool)$this->get($key);
	}

	/** @return array<mixed> */
	public function getArray(string $key): array {
		$value = $this->get($key);
		return is_array($value) ? $value : [];
	}

	public function set(string $key, mixed $value): void {
		if (!array_key_exists($key, self::DEFAULTS)) {
			throw new \InvalidArgumentException('Unknown setting ' . $key);
		}
		$default = self::DEFAULTS[$key];
		if (in_array($key, self::JSON_KEYS, true)) {
			$stored = json_encode(is_array($value) ? array_values($value) : []);
		} else {
			$stored = match (true) {
				is_bool($default) => (filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0'),
				is_int($default) => (string)(int)$value,
				is_float($default) => (string)(float)$value,
				default => (string)$value,
			};
		}
		$this->appConfig->setValueString(Application::APP_ID, $key, (string)$stored);
	}

	/** @return array<string, mixed> */
	public function all(): array {
		$out = [];
		foreach (array_keys(self::DEFAULTS) as $key) {
			$out[$key] = $this->get($key);
		}
		return $out;
	}

	/**
	 * Apply a batch of settings, ignoring keys the app does not know about and
	 * clamping the ones where a wild value would hurt.
	 *
	 * @param array<string, mixed> $values
	 * @return array<string, mixed> the settings as they now stand
	 */
	public function setMany(array $values): array {
		foreach ($values as $key => $value) {
			if (!array_key_exists($key, self::DEFAULTS)) {
				continue;
			}
			$this->set($key, $this->clamp($key, $value));
		}
		return $this->all();
	}

	private function clamp(string $key, mixed $value): mixed {
		return match ($key) {
			'max_sessions' => max(1, min(16, (int)$value)),
			'segment_duration' => max(2, min(10, (int)$value)),
			'session_ttl' => max(20, min(600, (int)$value)),
			'cache_max_gb' => max(1.0, (float)$value),
			'preview_seconds' => max(2, min(30, (int)$value)),
			'preview_height' => max(180, min(1080, (int)$value)),
			'preview_start_percent' => max(0, min(90, (int)$value)),
			'prewarm_batch' => max(0, min(500, (int)$value)),
			'index_batch' => max(10, min(5000, (int)$value)),
			'nice_level' => max(0, min(19, (int)$value)),
			'queue_wait_seconds' => max(0, min(120, (int)$value)),
			'bandwidth_probe_bytes' => max(262144, min(33554432, (int)$value)),
			'bandwidth_safety_factor' => max(1.0, min(4.0, (float)$value)),
			'bandwidth_probe_ttl' => max(60, min(86400, (int)$value)),
			'stall_threshold' => max(1, min(20, (int)$value)),
			'governor_interval' => max(3, min(60, (int)$value)),
			'min_buffer_seconds' => max(1.0, min(60.0, (float)$value)),
			'upshift_stable_seconds' => max(10, min(600, (int)$value)),
			'upshift_hysteresis' => max(1.0, min(3.0, (float)$value)),
			'shift_cooldown' => max(5, min(300, (int)$value)),
			default => $value,
		};
	}
}
