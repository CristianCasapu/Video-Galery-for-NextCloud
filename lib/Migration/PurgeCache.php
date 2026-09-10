<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Migration;

use OCA\VideoGallery\Service\Janitor;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Removing the app takes its files with it. Nothing is left on the cache disk
 * for someone to find months later and wonder about.
 */
class PurgeCache implements IRepairStep {
	public function __construct(
		private Janitor $janitor,
	) {
	}

	public function getName(): string {
		return 'Remove everything Video Gallery put on disk';
	}

	public function run(IOutput $output): void {
		try {
			$report = $this->janitor->purgeCache();
			$output->info(sprintf(
				'Video Gallery: stopped %d encoders and freed %.1f MB.',
				$report['processes_killed'],
				$report['bytes_freed'] / 1048576,
			));
		} catch (\Throwable $e) {
			$output->warning('Video Gallery could not empty its cache: ' . $e->getMessage());
		}
	}
}
