<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Migration;

use OCA\VideoGallery\Service\Janitor;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * After an update, anything that was mid-flight belongs to a version that no
 * longer exists. Nothing is resumed across an upgrade, so it is all cleared.
 */
class SweepCache implements IRepairStep {
	public function __construct(
		private Janitor $janitor,
	) {
	}

	public function getName(): string {
		return 'Clear Video Gallery working files';
	}

	public function run(IOutput $output): void {
		try {
			$report = $this->janitor->sweep();
			$output->info(sprintf(
				'Video Gallery: ended %d sessions and freed %.1f MB.',
				$report['sessions_ended'],
				$report['bytes_freed'] / 1048576,
			));
		} catch (\Throwable $e) {
			$output->warning('Video Gallery could not clear its working files: ' . $e->getMessage());
		}
	}
}
