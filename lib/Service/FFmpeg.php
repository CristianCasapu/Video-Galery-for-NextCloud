<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Service;

use OCA\VideoGallery\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Finds ffmpeg, works out what this machine can really do with it, and runs it.
 *
 * The capability list is not read off the build flags: an encoder is only
 * reported as available after it has encoded a frame here, on this hardware,
 * as this user. A driver that is installed but unreachable from the web server
 * fails that test, which is exactly what we want to know.
 */
class FFmpeg {
	private const CACHE_KEY = 'capabilities';
	private const CACHE_TTL = 86400;
	private const SEARCH_PATHS = [
		'/usr/bin', '/usr/local/bin', '/opt/ffmpeg/bin', '/snap/bin',
		'/usr/lib/jellyfin-ffmpeg', '/opt/homebrew/bin',
	];

	/**
	 * Frames set aside on the graphics card beyond what the decoder needs, so
	 * the encoder can hold a run of them without starving it.
	 *
	 * Small on purpose, and the same figure the converter uses. Cards differ in
	 * how many surfaces they will allow at once: too few and decoding stops with
	 * "No decoder surfaces left", too many and it will not start at all.
	 */
	public const EXTRA_HW_FRAMES = 4;

	/** Encoders we know how to drive, in the order we would rather have them. */
	public const ENCODERS = [
		'nvenc' => ['encoder' => 'h264_nvenc', 'label' => 'NVIDIA NVENC', 'hw' => true],
		'qsv' => ['encoder' => 'h264_qsv', 'label' => 'Intel Quick Sync', 'hw' => true],
		'vaapi' => ['encoder' => 'h264_vaapi', 'label' => 'VA-API', 'hw' => true],
		'videotoolbox' => ['encoder' => 'h264_videotoolbox', 'label' => 'Apple VideoToolbox', 'hw' => true],
		'software' => ['encoder' => 'libx264', 'label' => 'Software (libx264)', 'hw' => false],
	];

	private ?array $caps = null;

	public function __construct(
		private Config $config,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	/** True when PHP is allowed to start processes at all. */
	public function canRunProcesses(): bool {
		$disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
		return !in_array('proc_open', $disabled, true) && function_exists('proc_open');
	}

	public function ffmpeg(): ?string {
		return $this->locate('ffmpeg', 'ffmpeg_path');
	}

	public function ffprobe(): ?string {
		return $this->locate('ffprobe', 'ffprobe_path');
	}

	private function locate(string $name, string $settingKey): ?string {
		$configured = trim($this->config->getString($settingKey));
		if ($configured !== '') {
			return (is_file($configured) && is_executable($configured)) ? $configured : null;
		}
		foreach (self::SEARCH_PATHS as $dir) {
			$candidate = $dir . '/' . $name;
			if (is_file($candidate) && is_executable($candidate)) {
				return $candidate;
			}
		}
		// Last resort: ask the shell, in case it lives somewhere unusual.
		if ($this->canRunProcesses()) {
			$found = trim((string)@shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
			if ($found !== '' && is_file($found) && is_executable($found)) {
				return $found;
			}
		}
		return null;
	}

	/**
	 * Run a command and collect its output.
	 *
	 * @param list<string> $args
	 * @return array{code: int, out: string, err: string, timedOut: bool}
	 */
	public function run(array $args, int $timeout = 60, ?string $cwd = null): array {
		if (!$this->canRunProcesses()) {
			return ['code' => -1, 'out' => '', 'err' => 'proc_open is disabled in this PHP installation', 'timedOut' => false];
		}
		$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$process = @proc_open($args, $descriptors, $pipes, $cwd, $this->environment());
		if (!is_resource($process)) {
			return ['code' => -1, 'out' => '', 'err' => 'could not start ' . ($args[0] ?? '?'), 'timedOut' => false];
		}
		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$out = '';
		$err = '';
		$deadline = microtime(true) + $timeout;
		$timedOut = false;
		while (true) {
			$out .= (string)stream_get_contents($pipes[1]);
			$err .= (string)stream_get_contents($pipes[2]);
			$status = proc_get_status($process);
			if (!$status['running']) {
				break;
			}
			if (microtime(true) > $deadline) {
				$timedOut = true;
				proc_terminate($process, 9);
				break;
			}
			usleep(20000);
		}
		$out .= (string)stream_get_contents($pipes[1]);
		$err .= (string)stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($process);
		return ['code' => $timedOut ? -2 : $code, 'out' => $out, 'err' => $err, 'timedOut' => $timedOut];
	}

	/**
	 * A predictable environment for child processes: the web server's own
	 * environment can be nearly empty, which breaks driver loading.
	 *
	 * @return array<string, string>
	 */
	public function environment(): array {
		$env = [
			'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
			'HOME' => sys_get_temp_dir(),
			'LANG' => 'C',
			'LC_ALL' => 'C',
		];
		foreach (['LD_LIBRARY_PATH', 'CUDA_VISIBLE_DEVICES', 'NVIDIA_VISIBLE_DEVICES', 'XDG_RUNTIME_DIR'] as $pass) {
			$value = getenv($pass);
			if ($value !== false && $value !== '') {
				$env[$pass] = $value;
			}
		}
		return $env;
	}

	/**
	 * What this machine can do, cached for a day. Pass true after changing
	 * settings or hardware.
	 *
	 * @return array<string, mixed>
	 */
	public function capabilities(bool $refresh = false): array {
		if ($this->caps !== null && !$refresh) {
			return $this->caps;
		}
		$key = self::CACHE_KEY . '_' . $this->context();
		if (!$refresh) {
			$cached = $this->appConfig->getValueString(Application::APP_ID, $key, '');
			if ($cached !== '') {
				$decoded = json_decode($cached, true);
				if (is_array($decoded) && ($decoded['probed_at'] ?? 0) > time() - self::CACHE_TTL) {
					return $this->caps = $decoded;
				}
			}
		}
		$caps = $this->detect();
		$this->appConfig->setValueString(Application::APP_ID, $key, (string)json_encode($caps));
		return $this->caps = $caps;
	}

	/**
	 * What was found the last time the other execution context was tested, if it
	 * ever was. Never triggers a probe of its own: a web request cannot test the
	 * command line's environment, only read what it recorded.
	 *
	 * @return array<string, mixed>|null
	 */
	public function cachedCapabilities(string $context): ?array {
		$cached = $this->appConfig->getValueString(Application::APP_ID, self::CACHE_KEY . '_' . $context, '');
		if ($cached === '') {
			return null;
		}
		$decoded = json_decode($cached, true);
		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * Which of the two worlds this code is running in.
	 *
	 * A command run from a terminal and a request served by the web server are
	 * the same installation but not the same environment, and the difference
	 * matters here more than anywhere: a hardened web server unit can be denied
	 * the graphics device while the very same command works perfectly from a
	 * shell. Testing in one and believing the answer in the other is how an
	 * encoder comes to be reported as working right up until somebody presses
	 * play.
	 */
	public function context(): string {
		return PHP_SAPI === 'cli' ? 'cli' : 'web';
	}

	/**
	 * What the graphics hardware looks like from here.
	 *
	 * The kernel driver is visible through /proc even where the device nodes
	 * themselves have been hidden, so the two can be told apart: no driver at
	 * all is one thing, and a driver this process is not allowed to reach is
	 * quite another, with quite a different remedy.
	 *
	 * @return array{driver: bool, devices: bool, kind: string}
	 */
	public function gpuVisibility(): array {
		$nvidiaDriver = is_readable('/proc/driver/nvidia/version');
		$nvidiaDevices = file_exists('/dev/nvidiactl') && is_readable('/dev/nvidiactl');
		if ($nvidiaDriver || $nvidiaDevices) {
			return ['driver' => $nvidiaDriver, 'devices' => $nvidiaDevices, 'kind' => 'nvidia'];
		}
		$driDevices = is_dir('/dev/dri') && (glob('/dev/dri/renderD*') ?: []) !== [];
		return ['driver' => is_dir('/sys/class/drm'), 'devices' => $driDevices, 'kind' => 'dri'];
	}

	/** @return array<string, mixed> */
	private function detect(): array {
		$caps = [
			'probed_at' => time(),
			'proc_open' => $this->canRunProcesses(),
			'ffmpeg' => $this->ffmpeg(),
			'ffprobe' => $this->ffprobe(),
			'version' => null,
			'encoders' => [],
			'hwaccels' => [],
			'decoders' => [],
			'available' => [],
			'best' => 'software',
			'notes' => [],
		];
		if (!$caps['proc_open']) {
			$caps['notes'][] = 'PHP may not start processes here: proc_open is listed in disable_functions.';
			return $caps;
		}
		if ($caps['ffmpeg'] === null) {
			$caps['notes'][] = 'ffmpeg was not found. Install it, or point the settings at its path.';
			return $caps;
		}

		$version = $this->run([$caps['ffmpeg'], '-hide_banner', '-version'], 15);
		if (preg_match('/ffmpeg version (\S+)/', $version['out'], $m)) {
			$caps['version'] = $m[1];
		}

		$encoders = $this->run([$caps['ffmpeg'], '-hide_banner', '-loglevel', 'error', '-encoders'], 20);
		foreach (explode("\n", $encoders['out']) as $line) {
			if (preg_match('/^\s*[VAS][\w.]*\s+(\S+)/', $line, $m)) {
				$caps['encoders'][] = $m[1];
			}
		}
		$hwaccels = $this->run([$caps['ffmpeg'], '-hide_banner', '-loglevel', 'error', '-hwaccels'], 20);
		foreach (explode("\n", $hwaccels['out']) as $line) {
			$line = trim($line);
			if ($line !== '' && !str_contains($line, ':')) {
				$caps['hwaccels'][] = $line;
			}
		}
		$decoders = $this->run([$caps['ffmpeg'], '-hide_banner', '-loglevel', 'error', '-decoders'], 20);
		foreach (explode("\n", $decoders['out']) as $line) {
			if (preg_match('/^\s*[VAS][\w.]*\s+(\S+)/', $line, $m)) {
				$caps['decoders'][] = $m[1];
			}
		}

		// Built in is not the same as usable: try each one for real.
		foreach (self::ENCODERS as $key => $spec) {
			if (!in_array($spec['encoder'], $caps['encoders'], true)) {
				continue;
			}
			$result = $this->smokeTest($key);
			if ($result['ok']) {
				$caps['available'][$key] = ['encoder' => $spec['encoder'], 'label' => $spec['label'], 'hw' => $spec['hw']];
			} elseif ($spec['hw']) {
				$caps['notes'][] = $spec['label'] . ' is built into ffmpeg but did not work here: ' . $result['error'];
			}
		}
		foreach (array_keys(self::ENCODERS) as $key) {
			if (isset($caps['available'][$key])) {
				$caps['best'] = $key;
				break;
			}
		}
		$caps['hevc_nvenc'] = in_array('hevc_nvenc', $caps['encoders'], true) && isset($caps['available']['nvenc']);
		// Decoding on the card is a separate question from encoding on it, and
		// the answer is often different. This build and this driver may encode
		// happily and refuse to decode a single frame.
		$decode = $this->decodeTest($caps['best']);
		$caps['hw_decode'] = $decode['ok'];
		$caps['hw_decode_error'] = $decode['error'];
		if (!$decode['ok'] && $decode['error'] !== '' && $this->isHardware((string)$caps['best'])) {
			$caps['notes'][] = 'Decoding on the graphics card did not work, so files will be decoded by the processor and encoded on the card: ' . $decode['error'];
		}
		$caps['context'] = $this->context();
		$caps['gpu'] = $this->gpuVisibility();
		return $caps;
	}

	/**
	 * Encode one second of a test pattern with the given encoder family.
	 *
	 * @return array{ok: bool, error: string, ms: int}
	 */
	public function smokeTest(string $family): array {
		$binary = $this->ffmpeg();
		if ($binary === null) {
			return ['ok' => false, 'error' => 'ffmpeg not found', 'ms' => 0];
		}
		$spec = self::ENCODERS[$family] ?? null;
		if ($spec === null) {
			return ['ok' => false, 'error' => 'unknown encoder ' . $family, 'ms' => 0];
		}
		$args = [$binary, '-hide_banner', '-loglevel', 'error'];
		if ($family === 'vaapi') {
			$args = array_merge($args, ['-vaapi_device', $this->config->getString('vaapi_device')]);
		}
		$args = array_merge($args, ['-f', 'lavfi', '-i', 'testsrc=size=320x240:rate=25', '-t', '1']);
		if ($family === 'vaapi') {
			$args = array_merge($args, ['-vf', 'format=nv12,hwupload']);
		}
		$args = array_merge($args, ['-c:v', $spec['encoder'], '-f', 'null', '-']);

		$started = microtime(true);
		$result = $this->run($args, 30);
		$ms = (int)round((microtime(true) - $started) * 1000);
		if ($result['code'] === 0) {
			return ['ok' => true, 'error' => '', 'ms' => $ms];
		}
		$error = trim($result['err']);
		if ($error === '') {
			$error = $result['timedOut'] ? 'timed out' : 'exit code ' . $result['code'];
		}
		return ['ok' => false, 'error' => $this->firstLine($error), 'ms' => $ms];
	}

	/**
	 * Try decoding something on the card and scaling it there.
	 *
	 * Encoding and decoding are separate pieces of silicon with separate driver
	 * paths, and a machine that encodes perfectly can fail to decode at all —
	 * usually a version gap between the ffmpeg build and the installed driver.
	 * The failure is not graceful either: with the frames meant to stay on the
	 * card, a decoder that will not start takes the whole filter chain down with
	 * it. So it is tried here, once, on a file made for the purpose.
	 *
	 * @return array{ok: bool, error: string}
	 */
	public function decodeTest(string $family): array {
		$binary = $this->ffmpeg();
		if ($binary === null || !$this->isHardware($family)) {
			return ['ok' => false, 'error' => ''];
		}
		$sample = sys_get_temp_dir() . '/videogallery-decode-' . bin2hex(random_bytes(4)) . '.mp4';
		try {
			// Something real to decode: a second of H.264, the format nearly
			// every file in a library will be or will become.
			$made = $this->run([
				$binary, '-hide_banner', '-loglevel', 'error', '-y',
				// A realistic size: the surface budget a card can spare depends on
				// the picture, and a postage stamp proves nothing about a film.
				'-f', 'lavfi', '-i', 'testsrc=size=1280x720:rate=25', '-t', '2',
				'-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', $sample,
			], 60);
			if ($made['code'] !== 0 || !is_file($sample)) {
				return ['ok' => false, 'error' => 'could not make a sample to decode'];
			}

			$args = match ($family) {
				'nvenc' => [$binary, '-hide_banner', '-loglevel', 'error', '-y',
					'-hwaccel', 'cuda', '-hwaccel_output_format', 'cuda',
					'-extra_hw_frames', (string)self::EXTRA_HW_FRAMES, '-i', $sample,
					'-vf', 'scale_cuda=320:240', '-c:v', 'h264_nvenc', '-f', 'null', '-'],
				'vaapi' => [$binary, '-hide_banner', '-loglevel', 'error', '-y',
					'-hwaccel', 'vaapi', '-hwaccel_device', $this->config->getString('vaapi_device'),
					'-hwaccel_output_format', 'vaapi', '-i', $sample,
					'-vf', 'scale_vaapi=320:240', '-c:v', 'h264_vaapi', '-f', 'null', '-'],
				'qsv' => [$binary, '-hide_banner', '-loglevel', 'error', '-y',
					'-hwaccel', 'qsv', '-hwaccel_output_format', 'qsv', '-i', $sample,
					'-vf', 'scale_qsv=320:240', '-c:v', 'h264_qsv', '-f', 'null', '-'],
				default => null,
			};
			if ($args === null) {
				return ['ok' => false, 'error' => ''];
			}
			$result = $this->run($args, 60);
			if ($result['code'] === 0) {
				return ['ok' => true, 'error' => ''];
			}
			return ['ok' => false, 'error' => $this->firstLine(trim($result['err']) ?: 'exit code ' . $result['code'])];
		} finally {
			@unlink($sample);
		}
	}

	/** Whether frames can be decoded and kept on the card here. */
	public function canDecodeOnCard(): bool {
		return (bool)($this->capabilities()['hw_decode'] ?? false);
	}

	/** The encoder family to actually use, honouring the setting but never picking a broken one. */
	public function chosenEncoder(): string {
		$caps = $this->capabilities();
		$wanted = $this->config->getString('encoder');
		if ($wanted !== 'auto' && isset($caps['available'][$wanted])) {
			return $wanted;
		}
		if ($wanted !== 'auto' && $wanted !== '') {
			$this->logger->warning('Video Gallery: encoder {wanted} is not usable, falling back to {best}', [
				'wanted' => $wanted,
				'best' => $caps['best'],
			]);
		}
		return (string)($caps['best'] ?? 'software');
	}

	public function encoderName(string $family, string $codec = 'h264'): string {
		if ($family === 'software') {
			return $codec === 'hevc' ? 'libx265' : 'libx264';
		}
		return $codec . '_' . $family;
	}

	public function isHardware(string $family): bool {
		return (bool)(self::ENCODERS[$family]['hw'] ?? false);
	}

	private function firstLine(string $text): string {
		$lines = array_values(array_filter(array_map('trim', explode("\n", $text))));
		$last = end($lines);
		return mb_substr($last === false ? $text : $last, 0, 240);
	}
}
