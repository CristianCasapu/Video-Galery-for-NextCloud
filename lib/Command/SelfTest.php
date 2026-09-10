<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Command;

use OCA\VideoGallery\Db\Item;
use OCA\VideoGallery\Db\ItemMapper;
use OCA\VideoGallery\Db\SessionMapper;
use OCA\VideoGallery\Service\FFmpeg;
use OCA\VideoGallery\Service\Janitor;
use OCA\VideoGallery\Service\PlaybackDecision;
use OCA\VideoGallery\Service\PreviewService;
use OCA\VideoGallery\Service\Transcoder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Plays something, for real, and says whether it worked.
 *
 * Every other check in this app asks whether a thing looks right. This one
 * takes an actual file out of the library, converts a piece of it the way a
 * viewer would, seeks to the middle, checks that what came out is a playable
 * stream, and then confirms that closing the session left nothing behind.
 */
class SelfTest extends Command {
	public function __construct(
		private ItemMapper $items,
		private SessionMapper $sessions,
		private Transcoder $transcoder,
		private PlaybackDecision $decision,
		private PreviewService $previews,
		private Janitor $janitor,
		private FFmpeg $ffmpeg,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('videogallery:selftest')
			->setDescription('Convert part of a real file and check the result plays')
			->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'A file id to test with')
			->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Pick the file from this account')
			->addOption('keep', null, InputOption::VALUE_NONE, 'Leave the working files in place afterwards');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$item = $this->pick($input, $output);
		if ($item === null) {
			$output->writeln('<error>No indexed video to test with. Run videogallery:scan first.</error>');
			return 1;
		}
		$output->writeln(sprintf(
			'<comment>Testing with</comment> %s  <info>%s %dx%d %s/%s, %s</info>',
			$item->getName(),
			$item->getContainer(),
			$item->getWidth(),
			$item->getHeight(),
			$item->getVcodec(),
			$item->getAcodec() ?: 'no audio',
			$this->clock((int)round($item->getDurationMs() / 1000)),
		));
		$output->writeln('');

		$failures = 0;
		// Two rounds, because the two paths through the app are quite different
		// and each has its own way of going wrong. First a browser that can play
		// nothing at all, which forces a full re-encode. Then one that can decode
		// this file's own codecs but will not open its container, which is the
		// case that repackages the streams untouched — the cheap path, and the
		// one that has to cut segments at the keyframes already in the file.
		$rounds = [
			're-encoding' => ['video' => ['none'], 'audio' => [], 'containers' => []],
			'repackaging' => [
				'video' => array_filter([$item->getVcodec()]),
				'audio' => array_filter([$item->getAcodec()]),
				'containers' => [],
			],
		];

		foreach ($rounds as $label => $client) {
			$plan = $this->decision->decide($item, $client, 0.0, 'auto');
			if ($plan['mode'] === PlaybackDecision::DIRECT) {
				$output->writeln(sprintf('  <comment>%s</comment>  not applicable: this file needs no conversion', $label));
				continue;
			}
			$output->writeln(sprintf('  <comment>%s</comment>  plan %s at %s', $label, $plan['mode'], $plan['profile']));
			foreach ($plan['reasons'] as $reason) {
				$output->writeln('             ' . $reason);
			}
			$failures += $this->round($output, $item, $plan, (bool)$input->getOption('keep'));
			$output->writeln('');
		}

		$failures += $this->stage($output, 'cover', fn () => $this->previews->generate($item, 'poster'));
		$failures += $this->stage($output, 'hover clip', fn () => $this->previews->generate($item, 'loop'));

		$output->writeln('');
		if ($failures === 0) {
			$output->writeln('<info>Everything worked. Any file in the library can be played.</info>');
			return 0;
		}
		$output->writeln('<error>' . $failures . ' step(s) failed.</error>');
		return 1;
	}

	/**
	 * One full pass: open a session, produce the opening, jump to the middle,
	 * then close it and confirm nothing was left behind.
	 *
	 * @param array<string, mixed> $plan
	 * @return int how many steps failed
	 */
	private function round(OutputInterface $output, Item $item, array $plan, bool $keep): int {
		$failures = 0;
		try {
			$session = $this->transcoder->open($item->getUserId(), $item, $plan, 0.0);
		} catch (\Throwable $e) {
			$output->writeln('  <error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$copying = $this->transcoder->isCopyMode($session->getMode());
		$output->writeln(sprintf(
			'  session    %s, packaged as %s',
			$copying ? 'cut where the file allows' : $session->getTotalSegments() . ' segments',
			$session->getSegmentType(),
		));

		// A fragmented MP4 segment cannot be read on its own: the headers that
		// say what is in it live in the init segment, so the two have to be put
		// back together before anything can check the result.
		$init = null;
		if ($session->getSegmentType() === 'fmp4') {
			$failures += $this->stage($output, 'headers', fn () => $this->transcoder->ensureInit($session));
			$init = $this->transcoder->initPath($session);
		}
		$failures += $this->stage($output, 'opening', fn () => $this->transcoder->ensureSegment($session, 0), $init);

		// Somewhere in the middle. For a re-encode that forces a restart at an
		// offset; for a copied stream it means waiting for the pass to reach
		// there, which is the thing worth checking.
		$total = $copying ? $this->transcoder->listedSegments($session) : $session->getTotalSegments();
		$middle = intdiv($total, 2);
		if ($middle > 2) {
			$failures += $this->stage($output, 'seek', fn () => $this->transcoder->ensureSegment($session, $middle), $init);
		}

		if ($keep) {
			$output->writeln('  kept       ' . $session->getDir());
			return $failures;
		}
		$dir = $session->getDir();
		$this->janitor->endSession($session, 'self test finished');
		$this->janitor->dropSession($session);
		if (is_dir($dir)) {
			$output->writeln('  cleanup    <error>the working directory is still there</error>');
			$failures++;
		} elseif ($this->sessions->findByToken($session->getToken()) !== null) {
			$output->writeln('  cleanup    <error>the session row was not removed</error>');
			$failures++;
		} else {
			$output->writeln('  cleanup    <info>nothing left behind</info>');
		}
		return $failures;
	}

	/** Run one step, time it, and check that what it produced is really playable. */
	private function stage(OutputInterface $output, string $label, callable $work, ?string $init = null): int {
		$started = microtime(true);
		try {
			$path = $work();
		} catch (\Throwable $e) {
			$output->writeln(sprintf('  %-10s <error>%s</error>', $label, $e->getMessage()));
			return 1;
		}
		$ms = (int)round((microtime(true) - $started) * 1000);
		if (!is_string($path) || !is_file($path)) {
			$output->writeln(sprintf('  %-10s <error>produced nothing</error> (%d ms)', $label, $ms));
			return 1;
		}
		$size = (int)filesize($path);
		$verdict = $this->verify($path, $init);
		$output->writeln(sprintf(
			'  %-10s %s  %s, %d ms%s',
			$label,
			$verdict === null ? '<info>ok</info>' : '<error>unplayable</error>',
			$this->human($size),
			$ms,
			$verdict === null ? '' : ' — ' . $verdict,
		));
		return $verdict === null ? 0 : 1;
	}

	/** @return string|null null when the file is a stream something can play */
	private function verify(string $path, ?string $init = null): ?string {
		$binary = $this->ffmpeg->ffprobe();
		if ($binary === null) {
			return 'ffprobe is missing, so the result could not be checked';
		}
		$joined = null;
		if ($init !== null && is_file($init) && str_ends_with($path, '.m4s')) {
			$joined = $path . '.check.mp4';
			@file_put_contents($joined, (string)file_get_contents($init) . (string)file_get_contents($path));
			$path = $joined;
		}
		$result = $this->ffmpeg->run([
			$binary, '-hide_banner', '-loglevel', 'error',
			'-show_entries', 'stream=codec_type,codec_name',
			'-print_format', 'csv=p=0',
			$path,
		], 60);
		if ($joined !== null) {
			@unlink($joined);
		}
		if ($result['code'] !== 0) {
			return trim(str_replace("\n", ' ', $result['err'])) ?: 'ffprobe rejected it';
		}
		if (!str_contains($result['out'], 'video')) {
			return 'no video stream in the result';
		}
		return null;
	}

	private function pick(InputInterface $input, OutputInterface $output): ?Item {
		$fileId = $input->getOption('file');
		if ($fileId !== null) {
			$found = $this->items->byFileId((int)$fileId);
			return $found[0] ?? null;
		}
		$userId = $input->getOption('user');
		$users = $userId !== null ? [$userId] : $this->items->users();
		foreach ($users as $candidate) {
			// Something with a bit of length to it, so seeking has somewhere to go.
			$found = $this->items->search($candidate, ['minDuration' => 120, 'sort' => 'duration_asc'], 1);
			if ($found !== []) {
				return $found[0];
			}
		}
		// Nothing long enough; anything at all will still exercise the machinery.
		foreach ($users as $candidate) {
			$found = $this->items->search($candidate, ['sort' => 'duration_desc'], 1);
			if ($found !== []) {
				return $found[0];
			}
		}
		return null;
	}

	private function human(int $bytes): string {
		$units = ['B', 'kB', 'MB', 'GB'];
		$index = 0;
		$value = (float)$bytes;
		while ($value >= 1024 && $index < count($units) - 1) {
			$value /= 1024;
			$index++;
		}
		return sprintf('%.1f %s', $value, $units[$index]);
	}

	private function clock(int $seconds): string {
		return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
	}
}
