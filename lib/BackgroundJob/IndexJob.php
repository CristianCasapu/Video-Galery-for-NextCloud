<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\BackgroundJob;

use OCA\VideoGallery\Service\Config;
use OCA\VideoGallery\Service\Indexer;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Finds new videos and inspects the ones waiting.
 *
 * Discovery is cheap and runs for one account per pass, cycling through them,
 * so a server with many accounts spreads the work rather than doing it all at
 * once. Inspection is the expensive half and is capped per run.
 */
class IndexJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private Indexer $indexer,
		private Config $config,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(15 * 60);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$users = $this->indexer->userIds();
		if ($users === []) {
			return;
		}
		// Carry on from where the last pass stopped.
		$cursor = $this->appConfig->getValueInt('videogallery', 'index_cursor', 0);
		$userId = $users[$cursor % count($users)];
		$this->appConfig->setValueInt('videogallery', 'index_cursor', ($cursor + 1) % max(1, count($users)));

		try {
			$sync = $this->indexer->sync($userId);
			$queue = $this->indexer->processQueue($this->config->getInt('index_batch'));
			if ($sync['added'] > 0 || $queue['done'] > 0) {
				$this->logger->info('Video Gallery indexed {added} new and inspected {done} files for {user}', [
					'added' => $sync['added'],
					'done' => $queue['done'],
					'user' => $userId,
				]);
			}
		} catch (\Throwable $e) {
			$this->logger->error('Video Gallery indexing failed: ' . $e->getMessage(), ['exception' => $e]);
		}
	}
}
