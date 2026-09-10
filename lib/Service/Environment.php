<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Service;

/**
 * Works out whether this installation can do everything the app offers, and
 * where it cannot, says what is missing and what to do about it.
 *
 * The app is meant to work on a plain server with nothing set up specially, so
 * every finding here is either something it can arrange for itself or something
 * it can explain in one line and one command.
 */
class Environment {
	public const OK = 'ok';
	public const WARNING = 'warning';
	public const ERROR = 'error';

	public function __construct(
		private FFmpeg $ffmpeg,
		private Paths $paths,
		private Config $config,
	) {
	}

	/**
	 * @param bool $refresh re-run the hardware probes rather than using the cache
	 * @return array<string, mixed>
	 */
	public function report(bool $refresh = false): array {
		$checks = [];
		$checks[] = $this->checkProcesses();
		$checks[] = $this->checkFfmpeg($refresh);
		$checks[] = $this->checkStorage();
		$checks[] = $this->checkEncoder($refresh);
		$contextCheck = $this->checkContext();
		if ($contextCheck !== null) {
			$checks[] = $contextCheck;
		}
		$checks[] = $this->checkCron();

		$worst = self::OK;
		foreach ($checks as $check) {
			if ($check['status'] === self::ERROR) {
				$worst = self::ERROR;
				break;
			}
			if ($check['status'] === self::WARNING) {
				$worst = self::WARNING;
			}
		}
		$caps = $this->ffmpeg->capabilities($refresh);
		return [
			'status' => $worst,
			'checks' => $checks,
			'capabilities' => $caps,
			'encoder' => $this->ffmpeg->chosenEncoder(),
			'playbackPossible' => $this->playbackPossible($checks),
		];
	}

	/** @param list<array<string, mixed>> $checks */
	private function playbackPossible(array $checks): bool {
		foreach ($checks as $check) {
			if ($check['id'] === 'storage' && $check['status'] === self::ERROR) {
				return false;
			}
			if ($check['id'] === 'ffmpeg' && $check['status'] === self::ERROR) {
				return false;
			}
		}
		return true;
	}

	/** @return array<string, mixed> */
	private function checkProcesses(): array {
		if ($this->ffmpeg->canRunProcesses()) {
			return $this->check('processes', self::OK, 'PHP can start helper programs.');
		}
		return $this->check(
			'processes',
			self::ERROR,
			'PHP is not allowed to start other programs, so nothing can be converted or previewed.',
			'Remove proc_open from disable_functions in php.ini for the web server, then restart PHP.',
			'sudo sed -i "s/proc_open,\\?//" /etc/php/*/fpm/php.ini && sudo systemctl restart php*-fpm',
		);
	}

	/** @return array<string, mixed> */
	private function checkFfmpeg(bool $refresh): array {
		$caps = $this->ffmpeg->capabilities($refresh);
		if (($caps['ffmpeg'] ?? null) === null) {
			return $this->check(
				'ffmpeg',
				self::ERROR,
				'ffmpeg was not found, so videos can only be played in formats the browser already understands.',
				'Install ffmpeg, or set its full path in the settings below.',
				'sudo apt install ffmpeg     # Debian and Ubuntu' . "\n"
				. 'sudo dnf install ffmpeg     # Fedora and RHEL' . "\n"
				. 'sudo apk add ffmpeg         # Alpine',
			);
		}
		if (($caps['ffprobe'] ?? null) === null) {
			return $this->check(
				'ffmpeg',
				self::WARNING,
				'ffmpeg is here but ffprobe is not, so files cannot be inspected.',
				'ffprobe normally comes with ffmpeg. Install the full package rather than a minimal build.',
			);
		}
		return $this->check('ffmpeg', self::OK, 'ffmpeg ' . ($caps['version'] ?? '') . ' at ' . $caps['ffmpeg'] . '.');
	}

	/** @return array<string, mixed> */
	private function checkStorage(): array {
		try {
			$root = $this->paths->root();
		} catch (\RuntimeException $e) {
			return $this->check(
				'storage',
				self::ERROR,
				'There is nowhere to write temporary files: ' . $e->getMessage(),
				'Point the setting below at a directory the web server can write to, ideally on a fast disk that is not the system disk.',
				'sudo mkdir -p /srv/cache/videogallery && sudo chown ' . $this->webUser() . ' /srv/cache/videogallery',
			);
		}
		$configured = trim($this->config->getString('cache_root'));
		$usage = $this->paths->diskUsage();
		$freeGb = $usage['free'] / 1024 / 1024 / 1024;
		$detail = sprintf('Working files go to %s, with %.0f GB free.', $root, $freeGb);

		if ($configured !== '' && rtrim($configured, '/') !== $root) {
			return $this->check(
				'storage',
				self::WARNING,
				'The configured directory ' . $configured . ' cannot be used, so ' . $root . ' was chosen instead.',
				'Create the directory and give the web server write access to it, or change the setting.',
				'sudo mkdir -p ' . escapeshellarg($configured) . ' && sudo chown ' . $this->webUser() . ' ' . escapeshellarg($configured),
			);
		}
		if ($freeGb < 5) {
			return $this->check(
				'storage',
				self::WARNING,
				$detail . ' That is not much room for converting a long film.',
				'Point the setting at a disk with more space.',
			);
		}
		if ($this->isSystemDisk($root)) {
			return $this->check(
				'storage',
				self::WARNING,
				$detail . ' This appears to be the same disk the system runs from.',
				'Converting video writes constantly. A separate fast disk keeps that off the system disk.',
			);
		}
		return $this->check('storage', self::OK, $detail);
	}

	/** @return array<string, mixed> */
	private function checkEncoder(bool $refresh): array {
		$caps = $this->ffmpeg->capabilities($refresh);
		if (($caps['ffmpeg'] ?? null) === null) {
			return $this->check('encoder', self::WARNING, 'No encoder, because ffmpeg is missing.');
		}
		$available = $caps['available'] ?? [];
		if ($available === []) {
			return $this->check(
				'encoder',
				self::ERROR,
				'ffmpeg is installed but could not encode a single test frame.',
				'Check that the ffmpeg build includes libx264, and look at the notes below.',
			);
		}
		$chosen = $this->ffmpeg->chosenEncoder();
		$label = (string)($available[$chosen]['label'] ?? $chosen);
		if ($this->ffmpeg->isHardware($chosen)) {
			return $this->check('encoder', self::OK, 'Converting with ' . $label . '.');
		}

		// Software works, but on a machine with a graphics card it is worth
		// finding out precisely why the card is not being used, because the
		// answer is usually one line of configuration.
		$gpu = $this->ffmpeg->gpuVisibility();
		if ($gpu['kind'] === 'nvidia' && $gpu['driver'] && !$gpu['devices']) {
			// The driver is loaded, yet this process cannot see the device nodes.
			// On a systemd unit that almost always means PrivateDevices, which
			// gives the service a private /dev with no graphics card in it.
			return $this->check(
				'encoder',
				self::WARNING,
				'Converting with ' . $label . ', although this machine has an NVIDIA card.',
				'The driver is loaded but the web server cannot reach the card: its service is started with a private /dev that the graphics devices are not in. '
				. 'Allowing them back is a deliberate loosening of that hardening, so it is left to you.',
				$this->privateDevicesFix(),
			);
		}
		if ($gpu['kind'] === 'dri' && $gpu['devices']) {
			return $this->check(
				'encoder',
				self::WARNING,
				'Converting with ' . $label . '.',
				'This machine has a graphics device at /dev/dri. Adding the web server user to the render group usually enables hardware conversion.',
				'sudo usermod -aG render ' . $this->webUser() . ' && sudo systemctl restart php*-fpm',
			);
		}
		if (in_array('h264_nvenc', $caps['encoders'] ?? [], true) || in_array('cuda', $caps['hwaccels'] ?? [], true)) {
			return $this->check(
				'encoder',
				self::WARNING,
				'Converting with ' . $label . '.',
				'This ffmpeg knows about NVENC but it did not work here. The graphics driver is usually the reason.',
				'nvidia-smi     # run as the web server user to see what it says',
			);
		}
		return $this->check(
			'encoder',
			self::WARNING,
			'Converting with ' . $label . '.',
			'Converting will use the processor. That works, but one stream can occupy several cores.',
		);
	}

	/**
	 * Whether the same install behaves differently from a terminal, which is the
	 * one difference nobody expects and everybody hits.
	 *
	 * @return array<string, mixed>|null
	 */
	private function checkContext(): ?array {
		$here = $this->ffmpeg->context();
		$other = $here === 'web' ? 'cli' : 'web';
		$otherCaps = $this->ffmpeg->cachedCapabilities($other);
		if ($otherCaps === null) {
			return null;
		}
		$hardwareHere = $this->ffmpeg->isHardware($this->ffmpeg->chosenEncoder());
		$hardwareThere = false;
		foreach (array_keys($otherCaps['available'] ?? []) as $family) {
			$hardwareThere = $hardwareThere || $this->ffmpeg->isHardware((string)$family);
		}
		if ($hardwareHere || !$hardwareThere) {
			return null;
		}
		return $this->check(
			'context',
			self::WARNING,
			$here === 'web'
				? 'The graphics card works from the command line but not from the web server, so pages that play video will convert on the processor.'
				: 'The graphics card works for the web server but not from the command line, so background jobs will convert on the processor.',
			'The two run as different services with different permissions. The fix below applies to the web server.',
			$here === 'web' ? $this->privateDevicesFix() : null,
		);
	}

	/** The drop-in that gives a hardened service its graphics devices back. */
	private function privateDevicesFix(): string {
		return "sudo systemctl edit php8.4-fpm\n"
			. "# then add, and save:\n"
			. "[Service]\n"
			. "PrivateDevices=no\n"
			. "DeviceAllow=/dev/nvidia0 rw\n"
			. "DeviceAllow=/dev/nvidiactl rw\n"
			. "DeviceAllow=/dev/nvidia-uvm rw\n"
			. "DeviceAllow=/dev/nvidia-uvm-tools rw\n"
			. "DeviceAllow=/dev/nvidia-modeset rw\n"
			. "# then:\n"
			. 'sudo systemctl daemon-reload && sudo systemctl restart php8.4-fpm';
	}

	/** @return array<string, mixed> */
	private function checkCron(): array {
		// The app works without background jobs, only less eagerly: previews are
		// then made the first time someone hovers rather than beforehand.
		return $this->check(
			'background',
			self::OK,
			'Indexing and preview making run as background jobs. With cron set up as Nextcloud recommends, the library keeps itself up to date.',
		);
	}

	private function isSystemDisk(string $path): bool {
		$rootDevice = @stat('/');
		$pathDevice = @stat($path);
		return $rootDevice !== false && $pathDevice !== false && $rootDevice['dev'] === $pathDevice['dev'];
	}

	private function webUser(): string {
		if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
			$info = @posix_getpwuid(posix_geteuid());
			if (is_array($info) && isset($info['name'])) {
				return (string)$info['name'];
			}
		}
		return 'www-data';
	}

	/** @return array<string, mixed> */
	private function check(string $id, string $status, string $summary, ?string $hint = null, ?string $command = null): array {
		return [
			'id' => $id,
			'status' => $status,
			'summary' => $summary,
			'hint' => $hint,
			'command' => $command,
		];
	}
}
