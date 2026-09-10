<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Service;

use OCA\VideoGallery\AppInfo\Application;
use OCA\VideoGallery\Db\SessionMapper;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Every number the converter runs on, and where each one comes from.
 *
 * There is no figure here that suits every machine. How many frames a card will
 * hold at once, which of its presets are quick enough to be worth using, how
 * many conversions it will run before it refuses — these differ between two
 * cards from the same maker in the same year, and the gap between a modest card
 * and a good one is wider still.
 *
 * So each setting has three states. Left alone it is worked out from the
 * hardware that was actually found. Measured, by the tuner below, it becomes
 * what this machine proved it could do. Set by hand, it is simply obeyed —
 * because an administrator who has a reason usually has a good one.
 */
class Tuning {
	private const BENCHMARK_KEY = 'tuning_benchmark';

	public function __construct(
		private Config $config,
		private FFmpeg $ffmpeg,
		private SessionMapper $sessions,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	// -- the cards ----------------------------------------------------------

	/**
	 * The cards this app may use, as configured, filled in from what was found.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function devices(): array {
		$found = $this->ffmpeg->capabilities()['devices'] ?? [];
		$configured = [];
		foreach ($this->config->getArray('devices') as $entry) {
			if (is_array($entry) && isset($entry['index'])) {
				$configured[(int)$entry['index']] = $entry;
			}
		}
		$out = [];
		foreach ($found as $device) {
			$index = (int)$device['index'];
			$settings = $configured[$index] ?? [];
			$out[] = $device + [
				'enabled' => (bool)($settings['enabled'] ?? true),
				'max_sessions' => (int)($settings['max_sessions'] ?? $device['encoders'] ?? 2),
				'label' => (string)($settings['label'] ?? $device['name']),
				'active' => $this->sessions->countOnDevice($index),
			];
		}
		return $out;
	}

	/**
	 * The card to send the next conversion to.
	 *
	 * Whichever enabled card is carrying least, so that a machine with several
	 * spreads its work instead of queueing behind one. A card already at its
	 * limit is passed over entirely.
	 *
	 * @return array{index: int, full: bool}
	 */
	public function pickDevice(string $family): array {
		if ($family !== 'nvenc') {
			// Only NVIDIA is addressed by index; the others take a device path
			// from the settings.
			return ['index' => $this->config->getInt('nvenc_device'), 'full' => false];
		}
		$best = null;
		$anyRoom = false;
		foreach ($this->devices() as $device) {
			if ($device['kind'] !== 'nvidia' || !$device['enabled']) {
				continue;
			}
			$room = $device['max_sessions'] - $device['active'];
			if ($room > 0) {
				$anyRoom = true;
			}
			if ($best === null || $device['active'] < $best['active']) {
				$best = $device;
			}
		}
		if ($best === null) {
			return ['index' => $this->config->getInt('nvenc_device'), 'full' => false];
		}
		return ['index' => (int)$best['index'], 'full' => !$anyRoom];
	}

	/** How many conversions may run at once across every enabled card. */
	public function totalSessionLimit(): int {
		$configured = $this->config->getInt('max_sessions');
		$fromCards = 0;
		foreach ($this->devices() as $device) {
			if ($device['enabled'] && $device['kind'] === 'nvidia') {
				$fromCards += (int)$device['max_sessions'];
			}
		}
		// The overall setting is a ceiling, not a target: it never conjures
		// capacity a card does not have, and never limits a machine that has more
		// unless somebody meant it to.
		return $fromCards > 0 ? min(max($configured, 1), $fromCards) : max($configured, 1);
	}

	// -- the numbers --------------------------------------------------------

	/** Frames set aside on the card beyond the decoder's own needs. */
	public function extraHwFrames(): int {
		$configured = $this->config->getInt('extra_hw_frames');
		if ($configured > 0) {
			return $configured;
		}
		$measured = (int)($this->benchmark()['extra_hw_frames'] ?? 0);
		return $measured > 0 ? $measured : FFmpeg::EXTRA_HW_FRAMES;
	}

	/** The encoder preset, measured if it has been, configured if it was. */
	public function nvencPreset(): string {
		$configured = trim($this->config->getString('nvenc_preset'));
		if ($configured !== '' && $configured !== 'auto') {
			return $configured;
		}
		return (string)($this->benchmark()['nvenc_preset'] ?? 'p4');
	}

	/**
	 * How many frames the encoder may hold for its lookahead.
	 *
	 * Looking ahead lets it spend its bits more wisely and costs it nothing on
	 * its own. It costs a great deal when the decoder is on the same card, since
	 * both draw frames from one small pool — so there are two figures, and which
	 * applies depends on where the frames are.
	 */
	public function lookahead(bool $framesOnCard): int {
		return $framesOnCard
			? $this->config->getInt('nvenc_lookahead_on_card')
			: $this->config->getInt('nvenc_lookahead');
	}

	public function bFrames(bool $framesOnCard): int {
		return $framesOnCard
			? $this->config->getInt('nvenc_bframes_on_card')
			: $this->config->getInt('nvenc_bframes');
	}

	public function throttleAhead(): int {
		return $this->config->getInt('throttle_ahead_seconds');
	}

	public function restartDistance(): int {
		return $this->config->getInt('restart_distance_segments');
	}

	public function segmentWait(bool $copying): int {
		return $copying
			? $this->config->getInt('copy_segment_wait_seconds')
			: $this->config->getInt('segment_wait_seconds');
	}

	/** @return array{codec: string, bitrate: int, channels: int} */
	public function audio(): array {
		return [
			'codec' => $this->config->getString('audio_codec') ?: 'aac',
			'bitrate' => $this->config->getInt('audio_bitrate'),
			'channels' => $this->config->getInt('audio_channels'),
		];
	}

	/** @return array{maxrate: float, bufsize: float} */
	public function rateFactors(): array {
		return [
			'maxrate' => $this->config->getFloat('maxrate_factor'),
			'bufsize' => $this->config->getFloat('bufsize_factor'),
		];
	}

	// -- measuring ----------------------------------------------------------

	/** @return array<string, mixed> what the last measurement found */
	public function benchmark(): array {
		$stored = $this->appConfig->getValueString(Application::APP_ID, self::BENCHMARK_KEY, '');
		if ($stored === '') {
			return [];
		}
		$decoded = json_decode($stored, true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Find out what this machine is actually good at.
	 *
	 * Encodes the same short piece of video several ways and keeps the times.
	 * Two questions are answered: how many frames the card will let the decoder
	 * and the encoder hold between them, and which preset gives the most
	 * throughput without giving away quality. Both have to be measured, because
	 * both depend on the card, its driver, and the ffmpeg it is driven by.
	 *
	 * @return array<string, mixed>
	 */
	public function autoTune(): array {
		$binary = $this->ffmpeg->ffmpeg();
		$family = $this->ffmpeg->chosenEncoder();
		$result = [
			'ran_at' => time(),
			'encoder' => $family,
			'presets' => [],
			'extra_hw_frames' => 0,
			'nvenc_preset' => 'p4',
			'notes' => [],
		];
		if ($binary === null) {
			$result['notes'][] = 'ffmpeg is not available, so nothing could be measured.';
			return $this->storeBenchmark($result);
		}

		$sample = $this->makeSample($binary);
		if ($sample === null) {
			$result['notes'][] = 'A sample to measure against could not be made.';
			return $this->storeBenchmark($result);
		}

		try {
			if ($family === 'nvenc') {
				$result['extra_hw_frames'] = $this->measureSurfaces($binary, $sample, $result);
				$decode = $this->compareDecodePaths($binary, $sample, $result['extra_hw_frames']);
				$result['decode'] = $decode;
				$result['hw_decode'] = $decode['use_card'];
				if (!$decode['use_card']) {
					// Nothing is gained by keeping frames on the card here, so
					// they are not kept there — and the frame allowance stops
					// mattering at all.
					$result['extra_hw_frames'] = 0;
				}
			}
			$result['presets'] = $this->measurePresets($binary, $sample, $family, $result['extra_hw_frames']);
			$result['nvenc_preset'] = $this->chooseWorkingPreset($binary, $sample, $family, $result, $result['extra_hw_frames']);
		} finally {
			@unlink($sample);
		}
		return $this->storeBenchmark($result);
	}

	/** @param array<string, mixed> $result */
	private function storeBenchmark(array $result): array {
		$this->appConfig->setValueString(Application::APP_ID, self::BENCHMARK_KEY, (string)json_encode($result));
		return $result;
	}

	/**
	 * Something to measure against that behaves like a real file.
	 *
	 * The point matters more than it looks. A stream encoded with the default
	 * settings keeps only a few reference frames, and a decoder handling it asks
	 * the card for correspondingly few. Films do not look like that: they are
	 * encoded for quality, keep many references, and ask for many more frames at
	 * once — which is exactly the pressure that decides whether a setting holds
	 * up. A polite sample would pass every test and teach us nothing.
	 */
	private function makeSample(string $binary): ?string {
		$path = sys_get_temp_dir() . '/videogallery-tune-' . bin2hex(random_bytes(4)) . '.mp4';
		$made = $this->ffmpeg->run([
			$binary, '-hide_banner', '-loglevel', 'error', '-y',
			// Moving detail, so the encoder has something to work at rather than
			// a flat colour it can compress into nothing.
			'-f', 'lavfi', '-i', 'testsrc2=size=1920x1080:rate=30',
			'-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000',
			'-t', '20',
			'-c:v', 'libx264', '-preset', 'veryfast', '-pix_fmt', 'yuv420p',
			// As demanding of the decoder as the files people actually keep.
			// Four reference frames: what an ordinary film asks of a decoder.
			// A stream encoded with the defaults asks for almost nothing and
			// would pass every test; one encoded for archival quality asks for
			// far more than most files ever will.
			'-refs', '4', '-bf', '3', '-g', '90',
			'-c:a', 'aac', '-b:a', '128k',
			$path,
		], 180);
		return ($made['code'] === 0 && is_file($path)) ? $path : null;
	}

	/**
	 * The largest frame allowance the card accepts.
	 *
	 * Both ends fail, and differently: too few and decoding stops part way with
	 * "No decoder surfaces left", too many and it will not start at all. So the
	 * allowance is walked upwards and the last one that worked is kept.
	 *
	 * @param array<string, mixed> $result
	 */
	private function measureSurfaces(string $binary, string $sample, array &$result): int {
		$best = 0;
		foreach ([2, 4, 6, 8, 12, 16] as $frames) {
			$run = $this->ffmpeg->run([
				$binary, '-hide_banner', '-loglevel', 'error', '-y',
				'-hwaccel', 'cuda', '-hwaccel_output_format', 'cuda',
				'-extra_hw_frames', (string)$frames,
				'-i', $sample,
				'-map', '0:v:0', '-an',
				'-vf', 'scale_cuda=1280:720',
				'-c:v', 'h264_nvenc', '-preset', 'p4', '-b:v', '4000k',
				'-f', 'null', '-',
			], 120);
			if ($run['code'] === 0 && !str_contains($run['err'], 'surfaces left')) {
				$best = $frames;
			} elseif ($best > 0) {
				// It worked and now it does not: the ceiling has been found.
				break;
			}
		}
		if ($best === 0) {
			$result['notes'][] = 'No frame allowance worked on this card, so decoding will be done by the processor.';
		}
		return $best;
	}

	/**
	 * Whether decoding on the card is actually worth doing here.
	 *
	 * It is usually assumed to be, and it usually is — but not always, and the
	 * exceptions are invisible without measuring. Keeping frames on the card
	 * avoids copying them back and forth, which is a real saving; against that,
	 * the decoder and the encoder then compete for the same small pool of
	 * frames, and on a card where that pool is tight the contention costs more
	 * than the copying did. There is no way to tell which case a machine is in
	 * except to try both and time them.
	 *
	 * @return array{on_card: float, in_memory: float, use_card: bool}
	 */
	private function compareDecodePaths(string $binary, string $sample, int $extraFrames): array {
		$onCard = 0.0;
		if ($extraFrames > 0) {
			$onCard = $this->timePath($binary, $sample, [
				'-hwaccel', 'cuda', '-hwaccel_output_format', 'cuda', '-extra_hw_frames', (string)$extraFrames,
			], 'scale_cuda=1280:720');
		}
		$inMemory = $this->timePath($binary, $sample, [], 'scale=1280:720');

		// The card has to be meaningfully better, not merely different: swapping
		// to it for a few per cent would be trading robustness for nothing.
		return [
			'on_card' => $onCard,
			'in_memory' => $inMemory,
			'use_card' => $onCard > 0 && $onCard > $inMemory * 1.15,
		];
	}

	/** How many times real time one decode path manages. */
	private function timePath(string $binary, string $sample, array $inputArgs, string $filter): float {
		$args = array_merge([$binary, '-hide_banner', '-loglevel', 'error', '-y'], $inputArgs, [
			'-i', $sample,
			'-map', '0:v:0', '-map', '0:a:0?', '-sn', '-dn',
			'-vf', $filter,
			'-c:v', 'h264_nvenc', '-preset', 'p4', '-b:v', '4000k',
			'-c:a', 'aac', '-b:a', '160k', '-ac', '2',
			'-f', 'null', '-',
		]);
		$started = microtime(true);
		$run = $this->ffmpeg->run($args, 180);
		$seconds = microtime(true) - $started;
		if ($run['code'] !== 0 || $seconds <= 0.01 || str_contains($run['err'], 'surfaces left')) {
			return 0.0;
		}
		return round(20 / $seconds, 2);
	}

	/**
	 * How fast each preset is here, in multiples of real time.
	 *
	 * @return array<string, float>
	 */
	private function measurePresets(string $binary, string $sample, string $family, int $extraFrames): array {
		$presets = match ($family) {
			'nvenc' => ['p1', 'p2', 'p3', 'p4', 'p5', 'p6'],
			'qsv' => ['veryfast', 'faster', 'fast', 'medium'],
			'software' => ['ultrafast', 'veryfast', 'faster', 'fast'],
			default => ['default'],
		};
		$speeds = [];
		foreach ($presets as $preset) {
			$args = [$binary, '-hide_banner', '-loglevel', 'error', '-y'];
			if ($family === 'nvenc' && $extraFrames > 0) {
				$args = array_merge($args, ['-hwaccel', 'cuda', '-hwaccel_output_format', 'cuda',
					'-extra_hw_frames', (string)$extraFrames]);
			}
			$args = array_merge($args, ['-i', $sample]);
			$args = array_merge($args, match ($family) {
				'nvenc' => ['-vf', ($extraFrames > 0 ? 'scale_cuda' : 'scale') . '=1280:720',
					'-c:v', 'h264_nvenc', '-preset', $preset, '-b:v', '4000k'],
				'qsv' => ['-vf', 'scale=1280:720', '-c:v', 'h264_qsv', '-preset', $preset, '-b:v', '4000k'],
				'vaapi' => ['-vaapi_device', $this->config->getString('vaapi_device'),
					'-vf', 'format=nv12,hwupload,scale_vaapi=1280:720', '-c:v', 'h264_vaapi', '-b:v', '4000k'],
				default => ['-vf', 'scale=1280:720', '-c:v', 'libx264', '-preset', $preset, '-crf', '23'],
			});
			$args = array_merge($args, ['-an', '-f', 'null', '-']);

			$started = microtime(true);
			$run = $this->ffmpeg->run($args, 180);
			$seconds = microtime(true) - $started;
			if ($run['code'] === 0 && $seconds > 0.01 && !str_contains($run['err'], 'surfaces left')) {
				// Twenty seconds of source, so this is how many times real time
				// it manages.
				$speeds[$preset] = round(20 / $seconds, 2);
			}
		}
		return $speeds;
	}

	/**
	 * The best preset that survives the real pipeline.
	 *
	 * Speed alone is not enough to go on. The slower presets look further ahead
	 * and hold more frames while they do it, and those frames come out of the
	 * same pool the decoder is drawing on — so a preset can measure perfectly
	 * well on a short clip and then fail on a real file with sound and
	 * segmenting, which is the only test that counts. Each candidate is
	 * therefore put through exactly what playback will put it through, best
	 * first, and the first one that comes out whole is the one kept.
	 *
	 * @param array<string, mixed> $result
	 */
	private function chooseWorkingPreset(string $binary, string $sample, string $family, array &$result, int $extraFrames): string {
		$speeds = $result['presets'] ?? [];
		if ($speeds === []) {
			return 'p4';
		}
		// Slowest first, since slower means better looking at the same bitrate.
		$candidates = array_keys($speeds);
		$enough = 6.0;
		$ordered = array_reverse($candidates);

		foreach ($ordered as $preset) {
			if (($speeds[$preset] ?? 0) < $enough && count($ordered) > 1) {
				continue;
			}
			if ($this->verifyCombination($binary, $sample, $family, $preset, $extraFrames)) {
				return $preset;
			}
			$result['notes'][] = 'Preset ' . $preset . ' was fast enough but could not hold together with the frames this card allows, so it was passed over.';
		}
		// Nothing cleared the margin, or nothing survived: take the quickest that
		// does survive, and failing that the quickest of all.
		foreach ($candidates as $preset) {
			if ($this->verifyCombination($binary, $sample, $family, $preset, $extraFrames)) {
				return $preset;
			}
		}
		return (string)($candidates[0] ?? 'p4');
	}

	/**
	 * Put one combination through what playback will put it through: hardware
	 * decoding, scaling, encoding, sound, and cutting into segments.
	 */
	private function verifyCombination(string $binary, string $sample, string $family, string $preset, int $extraFrames): bool {
		if ($family !== 'nvenc') {
			return true;
		}
		$dir = sys_get_temp_dir() . '/videogallery-verify-' . bin2hex(random_bytes(4));
		if (!@mkdir($dir, 0700, true)) {
			return true;
		}
		try {
			$args = [$binary, '-hide_banner', '-loglevel', 'error', '-nostdin', '-y'];
			if ($extraFrames > 0) {
				$args = array_merge($args, ['-hwaccel', 'cuda', '-hwaccel_output_format', 'cuda',
					'-extra_hw_frames', (string)$extraFrames]);
			}
			$args = array_merge($args, [
				'-i', $sample,
				'-copyts', '-avoid_negative_ts', 'disabled', '-start_at_zero',
				// Mapped exactly as playback maps it, sound and all.
				'-map', '0:v:0', '-map', '0:a:0?', '-sn', '-dn',
				'-vf', ($extraFrames > 0 ? 'scale_cuda' : 'scale') . '=1280:720',
				'-c:v', 'h264_nvenc', '-preset', $preset,
				'-tune', $this->config->getString('nvenc_tune'),
				'-rc', $this->config->getString('nvenc_rc'),
				'-cq', (string)$this->config->getInt('nvenc_cq'),
				'-bf', (string)$this->bFrames($extraFrames > 0),
				'-b:v', '4000k',
				'-force_key_frames', 'expr:gte(t,n_forced*3)',
				'-c:a', 'aac', '-b:a', '160k', '-ac', '2',
				'-f', 'hls', '-hls_time', '3', '-hls_playlist_type', 'vod',
				'-hls_segment_type', 'mpegts', '-hls_list_size', '0',
				'-hls_flags', 'independent_segments+temp_file',
				'-hls_segment_filename', $dir . '/seg%d.ts',
				$dir . '/out.m3u8',
			]);
			if ($this->lookahead($extraFrames > 0) > 0) {
				array_splice($args, -11, 0, ['-rc-lookahead', (string)$this->lookahead($extraFrames > 0)]);
			}
			$run = $this->ffmpeg->run($args, 120);
			$produced = count(glob($dir . '/seg*.ts') ?: []);
			return $run['code'] === 0
				&& $produced > 0
				&& !str_contains($run['err'], 'surfaces left')
				&& !str_contains($run['err'], 'cuvidCreateDecoder');
		} finally {
			foreach (glob($dir . '/*') ?: [] as $file) {
				@unlink($file);
			}
			@rmdir($dir);
		}
	}

	/**
	 * The preset worth using.
	 *
	 * Not simply the fastest: the quickest presets give away real quality, and
	 * beyond a certain speed there is nothing left to gain — a card that can do
	 * eight times real time is already far ahead of anyone watching. So the
	 * slowest, and therefore best-looking, preset that still clears a
	 * comfortable margin is the one chosen.
	 *
	 * @param array<string, float> $speeds
	 */
	private function fastestAcceptable(array $speeds): ?string {
		$enough = 6.0;
		$chosen = null;
		foreach ($speeds as $preset => $speed) {
			if ($speed >= $enough) {
				$chosen = $preset;
			}
		}
		if ($chosen !== null) {
			return $chosen;
		}
		// Nothing clears the margin; take whatever was quickest.
		arsort($speeds);
		return array_key_first($speeds);
	}
}
