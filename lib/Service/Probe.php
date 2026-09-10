<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Service;

/**
 * Reads what is inside a video file: streams, codecs, and the date it was shot.
 */
class Probe {
	public const VERSION = 1;

	public function __construct(
		private FFmpeg $ffmpeg,
	) {
	}

	/**
	 * @return array<string, mixed>|null null when the file is not a video we can read
	 */
	public function inspect(string $path): ?array {
		$binary = $this->ffmpeg->ffprobe();
		if ($binary === null) {
			return null;
		}
		$result = $this->ffmpeg->run([
			$binary, '-hide_banner', '-loglevel', 'error',
			'-print_format', 'json',
			'-show_format', '-show_streams', '-show_chapters',
			$path,
		], 60);
		if ($result['code'] !== 0) {
			return null;
		}
		$data = json_decode($result['out'], true);
		if (!is_array($data) || !isset($data['streams'])) {
			return null;
		}
		return $this->summarise($data, $path);
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>|null
	 */
	private function summarise(array $data, string $path): ?array {
		$format = $data['format'] ?? [];
		$video = null;
		$audioTracks = [];
		$subTracks = [];

		foreach ($data['streams'] as $stream) {
			$type = $stream['codec_type'] ?? '';
			if ($type === 'video') {
				// Cover art inside a music-video style container is a video stream
				// too; it is not the film.
				$disposition = $stream['disposition'] ?? [];
				if (($disposition['attached_pic'] ?? 0) === 1) {
					continue;
				}
				if ($video === null) {
					$video = $stream;
				}
			} elseif ($type === 'audio') {
				$audioTracks[] = [
					'index' => (int)($stream['index'] ?? 0),
					'codec' => (string)($stream['codec_name'] ?? ''),
					'profile' => (string)($stream['profile'] ?? ''),
					'channels' => (int)($stream['channels'] ?? 0),
					'layout' => (string)($stream['channel_layout'] ?? ''),
					'language' => $this->language($stream),
					'title' => (string)($stream['tags']['title'] ?? ''),
					'default' => (int)(($stream['disposition']['default'] ?? 0)),
					'bitrate' => (int)($stream['bit_rate'] ?? 0),
				];
			} elseif ($type === 'subtitle') {
				$subTracks[] = [
					'index' => (int)($stream['index'] ?? 0),
					'codec' => (string)($stream['codec_name'] ?? ''),
					'language' => $this->language($stream),
					'title' => (string)($stream['tags']['title'] ?? ''),
					'default' => (int)(($stream['disposition']['default'] ?? 0)),
					'forced' => (int)(($stream['disposition']['forced'] ?? 0)),
					// Bitmap subtitles cannot become WebVTT; they would need burning in.
					'textual' => in_array((string)($stream['codec_name'] ?? ''), ['subrip', 'srt', 'ass', 'ssa', 'mov_text', 'webvtt', 'text'], true),
				];
			}
		}
		if ($video === null) {
			return null;
		}

		$durationSeconds = (float)($format['duration'] ?? $video['duration'] ?? 0);
		$pixFmt = (string)($video['pix_fmt'] ?? '');

		return [
			'probe_version' => self::VERSION,
			'container' => $this->container($format),
			'duration_ms' => (int)round($durationSeconds * 1000),
			'bitrate' => (int)($format['bit_rate'] ?? 0),
			'width' => (int)($video['width'] ?? 0),
			'height' => (int)($video['height'] ?? 0),
			'rotation' => $this->rotation($video),
			'fps' => $this->frameRate($video),
			'vcodec' => (string)($video['codec_name'] ?? ''),
			'vprofile' => (string)($video['profile'] ?? ''),
			'vlevel' => (int)($video['level'] ?? 0),
			'pix_fmt' => $pixFmt,
			'bit_depth' => $this->bitDepth($video, $pixFmt),
			'hdr' => $this->isHdr($video) ? 1 : 0,
			'acodec' => (string)($audioTracks[0]['codec'] ?? ''),
			'achannels' => (int)($audioTracks[0]['channels'] ?? 0),
			'audio_tracks' => $audioTracks,
			'sub_tracks' => $subTracks,
			'chapters' => $this->chapters($data),
			'taken_at' => $this->takenAt($format, $video, $path),
		];
	}

	/**
	 * Chapter marks, where the file carries them. Shown as divisions along the
	 * progress bar, which is how you find the part you actually wanted.
	 *
	 * @param array<string, mixed> $data
	 * @return list<array{start: float, end: float, title: string}>
	 */
	private function chapters(array $data): array {
		$out = [];
		foreach ($data['chapters'] ?? [] as $chapter) {
			$start = (float)($chapter['start_time'] ?? 0);
			$end = (float)($chapter['end_time'] ?? 0);
			if ($end <= $start) {
				continue;
			}
			$out[] = [
				'start' => round($start, 3),
				'end' => round($end, 3),
				'title' => mb_substr(trim((string)($chapter['tags']['title'] ?? '')), 0, 160),
			];
		}
		// A single chapter spanning the whole file tells nobody anything.
		return count($out) > 1 ? $out : [];
	}

	private function container(array $format): string {
		$names = explode(',', (string)($format['format_name'] ?? ''));
		return trim($names[0] ?? '');
	}

	private function language(array $stream): string {
		$language = (string)($stream['tags']['language'] ?? $stream['tags']['LANGUAGE'] ?? '');
		return ($language === '' || $language === 'und') ? '' : mb_substr($language, 0, 8);
	}

	private function frameRate(array $video): float {
		foreach (['avg_frame_rate', 'r_frame_rate'] as $key) {
			$raw = (string)($video[$key] ?? '');
			if (!str_contains($raw, '/')) {
				continue;
			}
			[$num, $den] = array_map('floatval', explode('/', $raw, 2));
			if ($den > 0 && $num > 0) {
				return round($num / $den, 3);
			}
		}
		return 0.0;
	}

	private function rotation(array $video): int {
		$rotate = $video['tags']['rotate'] ?? null;
		if ($rotate !== null) {
			return ((int)$rotate % 360 + 360) % 360;
		}
		foreach ($video['side_data_list'] ?? [] as $side) {
			if (isset($side['rotation'])) {
				return ((int)round((float)$side['rotation']) % 360 + 360) % 360;
			}
		}
		return 0;
	}

	private function bitDepth(array $video, string $pixFmt): int {
		if (isset($video['bits_per_raw_sample']) && (int)$video['bits_per_raw_sample'] > 0) {
			return (int)$video['bits_per_raw_sample'];
		}
		if (preg_match('/(\d{1,2})(le|be)$/', $pixFmt, $m)) {
			return (int)$m[1];
		}
		return 8;
	}

	private function isHdr(array $video): bool {
		$transfer = (string)($video['color_transfer'] ?? '');
		$primaries = (string)($video['color_primaries'] ?? '');
		if (in_array($transfer, ['smpte2084', 'arib-std-b67'], true)) {
			return true;
		}
		if ($primaries === 'bt2020') {
			return true;
		}
		foreach ($video['side_data_list'] ?? [] as $side) {
			$type = (string)($side['side_data_type'] ?? '');
			if (str_contains($type, 'Mastering display') || str_contains($type, 'Dolby Vision')) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The moment the video was shot. Container metadata first, then a date in the
	 * file name (phones and cameras write them there), and the caller falls back
	 * to the file's own timestamp when both come up empty.
	 */
	private function takenAt(array $format, array $video, string $path): array {
		$candidates = [
			$format['tags']['creation_time'] ?? null,
			$format['tags']['com.apple.quicktime.creationdate'] ?? null,
			$format['tags']['date'] ?? null,
			$video['tags']['creation_time'] ?? null,
		];
		foreach ($candidates as $candidate) {
			if (!is_string($candidate) || trim($candidate) === '') {
				continue;
			}
			$timestamp = strtotime($candidate);
			// Cameras with a dead clock stamp 1970 or 1904; those are not dates.
			if ($timestamp !== false && $timestamp > 315532800 && $timestamp < time() + 86400) {
				return ['at' => $timestamp, 'source' => 'metadata'];
			}
		}
		$fromName = $this->dateFromName(basename($path));
		if ($fromName !== null) {
			return ['at' => $fromName, 'source' => 'filename'];
		}
		return ['at' => 0, 'source' => 'mtime'];
	}

	private function dateFromName(string $name): ?int {
		$patterns = [
			// VID_20240115_183045, 20240115_183045, IMG-20240115-WA0001
			'/(?<y>19\d{2}|20\d{2})(?<m>0[1-9]|1[0-2])(?<d>0[1-9]|[12]\d|3[01])[-_ .]?(?<H>[0-2]\d)?(?<M>[0-5]\d)?(?<S>[0-5]\d)?/',
			// 2024-01-15 18.30.45
			'/(?<y>19\d{2}|20\d{2})-(?<m>0[1-9]|1[0-2])-(?<d>0[1-9]|[12]\d|3[01])[-_ .T]?(?<H>[0-2]\d)?[-.:]?(?<M>[0-5]\d)?[-.:]?(?<S>[0-5]\d)?/',
		];
		foreach ($patterns as $pattern) {
			if (!preg_match($pattern, $name, $m)) {
				continue;
			}
			$timestamp = mktime(
				(int)($m['H'] ?? 12),
				(int)($m['M'] ?? 0),
				(int)($m['S'] ?? 0),
				(int)$m['m'],
				(int)$m['d'],
				(int)$m['y'],
			);
			if ($timestamp !== false && $timestamp > 315532800 && $timestamp < time() + 86400) {
				return $timestamp;
			}
		}
		return null;
	}

	/**
	 * Where the keyframes are.
	 *
	 * Read from the packet headers rather than by decoding: the file still has
	 * to be walked through, but no picture is ever reconstructed, which is the
	 * difference between seconds and minutes on a long film.
	 *
	 * @return list<float>
	 */
	public function keyframes(string $path, float $upTo = 0.0): array {
		$binary = $this->ffmpeg->ffprobe();
		if ($binary === null) {
			return [];
		}
		$args = [
			$binary, '-hide_banner', '-loglevel', 'error',
			'-select_streams', 'v:0',
			'-show_packets',
			'-show_entries', 'packet=pts_time,flags',
			'-print_format', 'csv=p=0',
		];
		if ($upTo > 0) {
			$args = array_merge($args, ['-read_intervals', '%' . $upTo]);
		}
		$args[] = $path;
		$result = $this->ffmpeg->run($args, 900);
		if ($result['code'] !== 0) {
			return [];
		}
		$times = [];
		foreach (explode("\n", $result['out']) as $line) {
			$fields = explode(',', trim($line));
			if (count($fields) < 2) {
				continue;
			}
			[$time, $flags] = $fields;
			// A packet marked K starts a picture that stands on its own, which is
			// the only place a stream can be cut without breaking it.
			if (!str_contains($flags, 'K') || !is_numeric($time)) {
				continue;
			}
			$times[] = (float)$time;
		}
		sort($times);
		return $times;
	}
}
