<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\BackgroundJob;

use OCA\VideoGallery\Service\Config;
use OCA\VideoGallery\Service\PreviewService;
use OCA\VideoGallery\Service\Transcoder;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Makes cover pictures and hover clips ahead of time, so the library feels
 * instant rather than being built as it is browsed.
 *
 * It stands aside when someone is watching something: a viewer's stream is
 * worth more than a preview nobody has asked for yet.
 */
class PreviewJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private PreviewService $previews,
		private Transcoder $transcoder,
		private Config $config,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(10 * 60);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		if (!$this->config->getBool('prewarm_enabled') || !$this->config->getBool('preview_enabled')) {
			return;
		}
		if ($this->transcoder->activeCount() > 0) {
			// Someone is watching. Leave the hardware to them.
			return;
		}
		try {
			$made = $this->previews->prewarm($this->config->getInt('prewarm_batch'));
			$total = $made['poster'] + $made['loop'] + $made['sprite'];
			if ($total > 0) {
				$this->logger->info('Video Gallery prepared {count} preview files', ['count' => $total]);
			}
		} catch (\Throwable $e) {
			$this->logger->error('Video Gallery preview generation failed: ' . $e->getMessage(), ['exception' => $e]);
		}
	}
}
