<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Command;

use OCA\VideoGallery\Service\PreviewService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Preview extends Command {
	public function __construct(
		private PreviewService $previews,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('videogallery:preview')
			->setDescription('Make the cover pictures and hover clips ahead of time')
			->addArgument('user', InputArgument::OPTIONAL, 'Only this account')
			->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'How many to make', '500');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$limit = max(1, (int)$input->getOption('limit'));
		$user = $input->getArgument('user');
		$total = ['poster' => 0, 'loop' => 0, 'sprite' => 0, 'failed' => 0];

		$output->writeln('Making previews, ' . $limit . ' at most...');
		while (array_sum($total) < $limit) {
			$made = $this->previews->prewarm(min(25, $limit - array_sum($total)), $user);
			if (array_sum($made) === 0) {
				break;
			}
			foreach ($made as $kind => $count) {
				$total[$kind] += $count;
			}
			$output->write('.');
		}
		$output->writeln('');
		$output->writeln(sprintf(
			'<info>%d covers, %d hover clips, %d thumbnail strips.</info>%s',
			$total['poster'],
			$total['loop'],
			$total['sprite'],
			$total['failed'] > 0 ? ' <comment>' . $total['failed'] . ' failed.</comment>' : '',
		));
		return 0;
	}
}
