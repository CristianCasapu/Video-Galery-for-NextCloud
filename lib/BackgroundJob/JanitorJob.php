<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\BackgroundJob;

use OCA\VideoGallery\Service\Janitor;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * The regular sweep. Runs often, because a session abandoned by a closed
 * browser tab holds an encoder and a directory until something notices.
 */
class JanitorJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private Janitor $janitor,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(5 * 60);
		$this->setTimeSensitivity(self::TIME_SENSITIVE);
	}

	protected function run($argument): void {
		try {
			$report = $this->janitor->sweep();
			if (array_sum($report) > 0) {
				$this->logger->debug('Video Gallery swept up', $report);
			}
		} catch (\Throwable $e) {
			$this->logger->error('Video Gallery cleanup failed: ' . $e->getMessage(), ['exception' => $e]);
		}
	}
}
