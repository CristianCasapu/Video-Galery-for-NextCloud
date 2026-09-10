<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Command;

use OCA\VideoGallery\Db\ItemMapper;
use OCA\VideoGallery\Service\Indexer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Scan extends Command {
	public function __construct(
		private Indexer $indexer,
		private ItemMapper $items,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('videogallery:scan')
			->setDescription('Find video files and read what is inside them')
			->addArgument('user', InputArgument::OPTIONAL, 'Only this account')
			->addOption('discover-only', null, InputOption::VALUE_NONE, 'List the files but do not open them')
			->addOption('reprobe', null, InputOption::VALUE_NONE, 'Read every file again, even ones already done')
			->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'How many files to inspect', '100000');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$user = $input->getArgument('user');
		$users = $user !== null ? [$user] : $this->indexer->userIds();
		if ($input->getOption('reprobe')) {
			$changed = $this->items->markAllStale($user);
			$output->writeln('<info>Marked ' . $changed . ' items to be read again.</info>');
		}

		foreach ($users as $userId) {
			$output->writeln('<info>' . $userId . '</info>');
			$sync = $this->indexer->sync($userId);
			$output->writeln(sprintf(
				'  found %d, new %d, changed %d, gone %d',
				$sync['total'],
				$sync['added'],
				$sync['updated'],
				$sync['removed'],
			));
		}
		if ($input->getOption('discover-only')) {
			return 0;
		}

		$limit = max(1, (int)$input->getOption('limit'));
		$pending = min($limit, count($this->items->pending(min($limit, 100000), $user)));
		if ($pending === 0) {
			$output->writeln('<info>Nothing waiting to be read.</info>');
			return 0;
		}
		$output->writeln('Reading ' . $pending . ' files...');
		$progress = new ProgressBar($output, $pending);
		$progress->start();

		$done = 0;
		$failed = 0;
		while ($done + $failed < $limit) {
			$batch = min(50, $limit - $done - $failed);
			$result = $this->indexer->processQueue($batch, $user, static function ($item, $ok) use ($progress): void {
				$progress->advance();
			});
			if ($result['done'] === 0 && $result['failed'] === 0) {
				break;
			}
			$done += $result['done'];
			$failed += $result['failed'];
		}
		$progress->finish();
		$output->writeln('');
		$output->writeln(sprintf('<info>Read %d files.</info>%s', $done, $failed > 0 ? ' <comment>' . $failed . ' could not be read.</comment>' : ''));
		return 0;
	}
}
