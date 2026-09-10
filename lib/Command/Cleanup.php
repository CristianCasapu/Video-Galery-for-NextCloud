<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Command;

use OCA\VideoGallery\Service\Janitor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Cleanup extends Command {
	public function __construct(
		private Janitor $janitor,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('videogallery:cleanup')
			->setDescription('Stop stale playback sessions and clear the working disk')
			->addOption('all', null, InputOption::VALUE_NONE, 'Empty the cache completely, not just what has expired');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$everything = (bool)$input->getOption('all');
		$result = $everything ? $this->janitor->purgeCache() : $this->janitor->sweep();
		foreach ($result as $key => $value) {
			if ($key === 'bytes_freed') {
				$output->writeln(sprintf('  %-20s %s', 'freed', $this->human((int)$value)));
				continue;
			}
			$output->writeln(sprintf('  %-20s %d', str_replace('_', ' ', $key), $value));
		}
		$report = $this->janitor->report();
		$output->writeln('');
		$output->writeln(sprintf(
			'<info>%s now holds %s across %d files, with %s free on the disk.</info>',
			$report['root'],
			$this->human($report['on_disk_bytes']),
			$report['assets'],
			$this->human($report['disk_free']),
		));
		return 0;
	}

	private function human(int $bytes): string {
		$units = ['B', 'kB', 'MB', 'GB', 'TB'];
		$index = 0;
		$value = (float)$bytes;
		while ($value >= 1024 && $index < count($units) - 1) {
			$value /= 1024;
			$index++;
		}
		return sprintf('%.1f %s', $value, $units[$index]);
	}
}
