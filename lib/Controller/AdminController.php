<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Controller;

use OCA\VideoGallery\AppInfo\Application;
use OCA\VideoGallery\Db\ItemMapper;
use OCA\VideoGallery\Db\SessionMapper;
use OCA\VideoGallery\Service\Config;
use OCA\VideoGallery\Service\Environment;
use OCA\VideoGallery\Service\FFmpeg;
use OCA\VideoGallery\Service\Indexer;
use OCA\VideoGallery\Service\Janitor;
use OCA\VideoGallery\Service\Paths;
use OCA\VideoGallery\Service\PlaybackMemory;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

class AdminController extends OCSController {
	public function __construct(
		IRequest $request,
		private Config $config,
		private Environment $environment,
		private FFmpeg $ffmpeg,
		private Paths $paths,
		private Janitor $janitor,
		private PlaybackMemory $memory,
		private Indexer $indexer,
		private ItemMapper $items,
		private SessionMapper $sessions,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	public function getSettings(): DataResponse {
		return new DataResponse([
			'settings' => $this->config->all(),
			'defaults' => Config::DEFAULTS,
			'environment' => $this->environment->report(),
			'cache' => $this->janitor->report(),
			'library' => $this->items->stats(),
			'candidates' => $this->paths->candidates(),
			'memory' => $this->memory->summary(30),
		]);
	}

	/** Throw away what has been learned, so it is worked out afresh. */
	public function forgetMemory(): DataResponse {
		return new DataResponse(['removed' => $this->memory->forget()]);
	}

	/** @param array<string, mixed> $settings */
	public function setSettings(array $settings = []): DataResponse {
		$before = $this->config->getString('cache_root');
		$applied = $this->config->setMany($settings);
		// A change of encoder or paths invalidates what we believe about the
		// hardware, so it is worked out again rather than trusted.
		if (($settings['encoder'] ?? null) !== null
			|| ($settings['ffmpeg_path'] ?? null) !== null
			|| ($settings['vaapi_device'] ?? null) !== null) {
			$this->ffmpeg->capabilities(true);
		}
		$report = $this->environment->report();
		if ($before !== $this->config->getString('cache_root')) {
			$report['moved'] = true;
		}
		return new DataResponse(['settings' => $applied, 'environment' => $report, 'cache' => $this->janitor->report()]);
	}

	/** Re-run the hardware tests and report what actually worked. */
	public function probe(): DataResponse {
		$report = $this->environment->report(true);
		$tests = [];
		foreach (array_keys(FFmpeg::ENCODERS) as $family) {
			$tests[$family] = $this->ffmpeg->smokeTest($family);
		}
		return new DataResponse(['environment' => $report, 'tests' => $tests]);
	}

	public function stats(): DataResponse {
		$live = [];
		foreach ($this->sessions->live() as $session) {
			$live[] = $session->jsonSerialize() + ['user' => $session->getUserId()];
		}
		return new DataResponse([
			'cache' => $this->janitor->report(),
			'library' => $this->items->stats(),
			'sessions' => $live,
		]);
	}

	/** Sweep now rather than waiting for the background job. */
	public function cleanup(bool $everything = false): DataResponse {
		$result = $everything ? $this->janitor->purgeCache() : $this->janitor->sweep();
		return new DataResponse(['result' => $result, 'cache' => $this->janitor->report()]);
	}

	/** Look at every account's files again. */
	public function reindex(bool $reprobe = false): DataResponse {
		if ($reprobe) {
			$this->items->markAllStale();
		}
		$totals = ['added' => 0, 'updated' => 0, 'removed' => 0, 'total' => 0];
		foreach ($this->indexer->userIds() as $userId) {
			foreach ($this->indexer->sync($userId) as $key => $value) {
				$totals[$key] = ($totals[$key] ?? 0) + $value;
			}
		}
		return new DataResponse(['sync' => $totals, 'library' => $this->items->stats()]);
	}

	/** Try a directory before saving it, so a bad path is caught here. */
	public function checkPath(string $path = ''): DataResponse {
		$path = trim($path);
		if ($path === '') {
			return new DataResponse(['ok' => false, 'message' => 'Give a directory to check.']);
		}
		if ($this->paths->prepare($path)) {
			$total = (int)@disk_total_space($path);
			$free = (int)@disk_free_space($path);
			return new DataResponse([
				'ok' => true,
				'message' => sprintf('Usable, with %.0f GB free of %.0f GB.', $free / 1073741824, $total / 1073741824),
				'free' => $free,
				'total' => $total,
			]);
		}
		$parent = dirname($path);
		$reason = match (true) {
			is_dir($path) && !is_writable($path) => 'The directory exists but cannot be written to.',
			is_dir($path) => 'The directory holds files that are not this app\'s, so it will not be used.',
			!is_dir($parent) => 'The parent directory ' . $parent . ' does not exist.',
			!is_writable($parent) => 'The parent directory ' . $parent . ' cannot be written to.',
			default => 'The directory could not be created.',
		};
		return new DataResponse(['ok' => false, 'message' => $reason]);
	}
}
