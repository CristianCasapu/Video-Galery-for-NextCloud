<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Command;

use OCA\VideoGallery\Db\ItemMapper;
use OCA\VideoGallery\Db\SessionMapper;
use OCA\VideoGallery\Service\Environment;
use OCA\VideoGallery\Service\Janitor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Says whether this machine can do everything the app offers, and what to do
 * about anything it cannot.
 */
class Status extends Command {
	public function __construct(
		private Environment $environment,
		private Janitor $janitor,
		private ItemMapper $items,
		private SessionMapper $sessions,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('videogallery:status')
			->setDescription('Show what this server can play, and what is in the library')
			->addOption('probe', null, InputOption::VALUE_NONE, 'Test the hardware again rather than using the last result');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$report = $this->environment->report((bool)$input->getOption('probe'));

		$output->writeln('<comment>Environment</comment>');
		foreach ($report['checks'] as $check) {
			$mark = match ($check['status']) {
				Environment::OK => '<info>  ok  </info>',
				Environment::WARNING => '<comment> note </comment>',
				default => '<error> fail </error>',
			};
			$output->writeln($mark . ' ' . $check['summary']);
			if (!empty($check['hint'])) {
				$output->writeln('        ' . $check['hint']);
			}
			if (!empty($check['command'])) {
				foreach (explode("\n", (string)$check['command']) as $line) {
					$output->writeln('        <options=bold>' . $line . '</>');
				}
			}
		}

		$caps = $report['capabilities'];
		$output->writeln('');
		$output->writeln('<comment>Encoders that work here</comment>');
		foreach (($caps['available'] ?? []) as $key => $spec) {
			$output->writeln(sprintf('  %-14s %s%s', $key, $spec['label'], $key === $report['encoder'] ? ' <info>(in use)</info>' : ''));
		}
		foreach (($caps['notes'] ?? []) as $note) {
			$output->writeln('  <comment>' . $note . '</comment>');
		}

		$library = $this->items->stats();
		$cache = $this->janitor->report();
		$output->writeln('');
		$output->writeln('<comment>Library</comment>');
		$output->writeln(sprintf('  %d videos, %s, %s of footage', $library['total'], $this->human($library['bytes']), $this->duration($library['seconds'])));
		$output->writeln(sprintf('  %d read, %d waiting, %d could not be read', $library['ok'], $library['pending'], $library['failed']));
		$output->writeln('');
		$output->writeln('<comment>Working disk</comment>');
		$output->writeln('  ' . $cache['root']);
		$output->writeln(sprintf('  %s used of a %s limit, %s free on the disk', $this->human($cache['on_disk_bytes']), $this->human($cache['limit_bytes']), $this->human($cache['disk_free'])));
		$output->writeln(sprintf('  %d sessions running, %d directories, %d cached files', $cache['sessions_live'], $cache['session_dirs'], $cache['assets']));

		return $report['status'] === Environment::ERROR ? 1 : 0;
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

	private function duration(int $seconds): string {
		$hours = intdiv($seconds, 3600);
		return $hours > 24
			? sprintf('%d days %d hours', intdiv($hours, 24), $hours % 24)
			: sprintf('%d hours %d minutes', $hours, intdiv($seconds % 3600, 60));
	}
}
