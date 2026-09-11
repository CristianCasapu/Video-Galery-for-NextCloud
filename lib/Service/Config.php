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
		// How many previews may be made at once. A page of forty cards asks for
		// forty pictures in the same second, and without a limit that is forty
		// encoders competing for the same processor.
		'preview_concurrency' => 2,
		'preview_threads' => 2,
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
		// A clip shorter than a second is a stray frame or a botched recording,
		// never something anybody meant to keep.
		'min_duration_seconds' => 1,
		'excluded_paths' => [],
		// Names that mean "this is not the film". Matched anywhere in the name,
		// without regard to case.
		'ignore_names' => ['sample'],
		'external_player_enabled' => true,
		'external_token_ttl' => 21600,

		'ffmpeg_path' => '',
		'ffprobe_path' => '',
		'nice_level' => 5,
		'io_class' => 'idle',

		// -- Tuning ---------------------------------------------------------
		//
		// Everything below is a number that used to be written into the code.
		// What suits one machine suits another badly: the frames a card will
		// hold at once, the presets its encoder is quick at, how far ahead it is
		// worth working. Zero means "work it out from the hardware".

		// Graphics cards to use, as [{index, enabled, max_sessions, label}].
		// Empty means every card found, sharing the overall session limit.
		'devices' => [],
		'auto_tune' => true,
		// Frames set aside on the card beyond the decoder's own needs. Too few
		// and decoding stops; too many and it will not start.
		'extra_hw_frames' => 0,
		'decoder_threads' => 0,

		'nvenc_tune' => 'hq',
		'nvenc_rc' => 'vbr',
		'nvenc_cq' => 23,
		'nvenc_bframes' => 3,
		// Held frames, which come out of the same pool the decoder draws on.
		'nvenc_lookahead' => 20,
		'nvenc_lookahead_on_card' => 0,
		'nvenc_bframes_on_card' => 2,
		'nvenc_multipass' => 'disabled',
		'nvenc_spatial_aq' => false,
		'nvenc_profile' => 'high',

		'x264_crf' => 23,
		'x265_crf' => 26,
		'vaapi_quality' => 0,
		'qsv_preset' => 'faster',

		'audio_codec' => 'aac',
		'audio_bitrate' => 160,
		'audio_channels' => 2,

		'throttle_ahead_seconds' => 90,
		'restart_distance_segments' => 3,
		'segment_wait_seconds' => 30,
		'copy_segment_wait_seconds' => 60,
		'maxrate_factor' => 1.5,
		'bufsize_factor' => 3.0,

		'preview_fps' => 24,
		'preview_crf' => 30,
		'rail_size' => 24,

		// -- What kind of thing is in a folder ------------------------------
		//
		// A library is kept in folders, and the folders mean different things:
		// a course is watched in order from where you left off, a folder of
		// phone clips is looked at newest first, a film is a film. The rules
		// for telling them apart are here rather than in the code, because
		// everybody names things differently and in their own language.
		//
		// Each entry is {id, label, words, namePattern, show, sequence}:
		//   words       matched anywhere in the folder's path, case ignored
		//   namePattern a regular expression; a folder whose file names mostly
		//               match it belongs here even if its own name says nothing
		//   show        'entry' to open at the part worth watching next,
		//               'latest' to show the newest first
		//   sequence    whether one part follows another, which is what makes
		//               the next one start by itself
		// The first entry that matches wins, so order is priority.
		'categories' => [
			[
				'id' => 'course',
				'label' => 'Courses',
				'words' => ['course', 'courses', 'curs', 'cursuri', 'lectie', 'lecție', 'lectii', 'lecții',
					'lesson', 'lessons', 'lecture', 'lectures', 'tutorial', 'tutoriale', 'training',
					'bootcamp', 'academy', 'masterclass', 'workshop', 'module', 'modul', 'class'],
				'namePattern' => '',
				'show' => 'entry',
				'sequence' => true,
			],
			[
				'id' => 'series',
				'label' => 'Series',
				'words' => ['series', 'serial', 'seriale', 'season', 'sezon', 'episode', 'episod',
					'episoade', 'chapter', 'capitol', 'part', 'partea'],
				'namePattern' => '(?:s\\d{1,2}[\\s._-]?e\\d{1,2}|episode[\\s._-]?\\d{1,3}|ep[\\s._-]?\\d{1,3})',
				'show' => 'entry',
				'sequence' => true,
			],
			[
				'id' => 'camera',
				'label' => 'Camera',
				'words' => ['camera', 'camere', 'dcim', 'instantupload', 'instant upload',
					'camera uploads', 'telefon', 'phone', 'poze', 'photos', 'pictures', 'imagini'],
				'namePattern' => '^(?:img|vid|video|pxl|dji|mov|dsc|gopr|mvi|wa|photo|screenrecord)[-_ ]?\\d',
				'show' => 'latest',
				'sequence' => false,
			],
			[
				'id' => 'film',
				'label' => 'Films',
				'words' => ['film', 'filme', 'movie', 'movies', 'cinema', 'documentar', 'documentary'],
				'namePattern' => '',
				'show' => 'latest',
				'sequence' => false,
			],
		],
		// What a folder of plainly numbered files is, when nothing else matches.
		'sequence_category' => 'course',
		'other_label' => 'Folders',

		// -- Watching in order ----------------------------------------------
		'autoplay_next' => true,
		'autoplay_delay' => 8,
		'series_minimum' => 3,

		// -- Sharing ---------------------------------------------------------
		'sharing_enabled' => true,
		'short_links' => true,
	];

	/** Values that are stored as JSON rather than as a scalar. */
	private const JSON_KEYS = ['quality_ladder', 'excluded_paths', 'ignore_names', 'devices', 'categories'];

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
			'preview_concurrency' => max(1, min(32, (int)$value)),
			'preview_threads' => max(0, min(64, (int)$value)),
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
			'extra_hw_frames' => max(0, min(64, (int)$value)),
			'decoder_threads' => max(0, min(64, (int)$value)),
			'nvenc_cq' => max(1, min(51, (int)$value)),
			'nvenc_bframes' => max(0, min(5, (int)$value)),
			'nvenc_lookahead' => max(0, min(32, (int)$value)),
			'nvenc_lookahead_on_card' => max(0, min(32, (int)$value)),
			'nvenc_bframes_on_card' => max(0, min(5, (int)$value)),
			'x264_crf', 'x265_crf' => max(0, min(51, (int)$value)),
			'audio_bitrate' => max(32, min(1024, (int)$value)),
			'audio_channels' => max(1, min(8, (int)$value)),
			'throttle_ahead_seconds' => max(10, min(3600, (int)$value)),
			'restart_distance_segments' => max(1, min(50, (int)$value)),
			'segment_wait_seconds' => max(5, min(300, (int)$value)),
			'copy_segment_wait_seconds' => max(5, min(600, (int)$value)),
			'maxrate_factor' => max(1.0, min(4.0, (float)$value)),
			'bufsize_factor' => max(1.0, min(10.0, (float)$value)),
			'preview_fps' => max(1, min(60, (int)$value)),
			'preview_crf' => max(0, min(51, (int)$value)),
			'rail_size' => max(6, min(100, (int)$value)),
			'autoplay_delay' => max(0, min(60, (int)$value)),
			'series_minimum' => max(2, min(50, (int)$value)),
			'min_duration_seconds' => max(0, min(3600, (int)$value)),
			default => $value,
		};
	}
}
