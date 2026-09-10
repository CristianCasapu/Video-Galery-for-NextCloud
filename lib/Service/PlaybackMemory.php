<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Service;

use OCA\VideoGallery\Db\Item;
use OCA\VideoGallery\Db\Recipe;
use OCA\VideoGallery\Db\RecipeMapper;
use Psr\Log\LoggerInterface;

/**
 * Remembers what has actually worked, and uses it next time.
 *
 * The rules that choose how to play a file are careful, but they are still
 * rules, and the thing they reason about — what a browser can decode — is
 * something the browser itself is only approximately honest about. A browser
 * that claims a codec and then stumbles over it will fool a rule every time,
 * and fool it again tomorrow.
 *
 * So the outcome of every decision is written down against the situation that
 * produced it: this shape of browser, this shape of file, this is what we did,
 * and this is how it went. A situation met before is then answered from
 * experience instead of being worked out again, which starts playback sooner
 * and quietly corrects the places where the rules are wrong.
 *
 * What is remembered is deliberately only the structural half of the decision —
 * whether to re-encode, repackage, or send as is, and how to package it. The
 * quality is never remembered, because it depends on a connection that is
 * different every time and is measured fresh on every play.
 */
class PlaybackMemory {
	/** Below this, experience is recorded but not yet acted on. */
	private const TRUST_THRESHOLD = 0.35;

	public function __construct(
		private RecipeMapper $recipes,
		private Config $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * A fingerprint of the situation: what this browser can decode, and what
	 * this file is made of.
	 *
	 * Deliberately coarse. Two files that differ only in length or in how many
	 * pixels wide they are will play the same way, and treating them as separate
	 * cases would mean never accumulating enough evidence about either.
	 *
	 * @param array<string, mixed> $client
	 */
	public function signature(array $client, Item $item): string {
		$video = array_map('strtolower', (array)($client['video'] ?? []));
		$audio = array_map('strtolower', (array)($client['audio'] ?? []));
		$containers = array_map('strtolower', (array)($client['containers'] ?? []));
		sort($video);
		sort($audio);
		sort($containers);

		$parts = [
			'c:' . implode('.', $video),
			'a:' . implode('.', $audio),
			'k:' . implode('.', $containers),
			'm:' . (($client['mse'] ?? false) ? 1 : 0) . (($client['hls'] ?? false) ? 1 : 0),
			'f:' . strtolower($item->getContainer()),
			'v:' . strtolower($item->getVcodec()),
			'd:' . $item->getBitDepth(),
			'h:' . $item->getHdr(),
			's:' . strtolower($item->getAcodec()),
			'n:' . min(8, $item->getAchannels()),
			'r:' . $this->resolutionBand($item),
		];
		return substr(hash('sha256', implode('|', $parts)), 0, 32);
	}

	/** Resolutions grouped, because 1912 and 1920 wide behave identically. */
	private function resolutionBand(Item $item): string {
		$longest = max($item->getWidth(), $item->getHeight());
		return match (true) {
			$longest >= 3400 => '4k',
			$longest >= 1800 => '1080',
			$longest >= 1200 => '720',
			$longest >= 800 => '480',
			$longest > 0 => 'small',
			default => 'unknown',
		};
	}

	/**
	 * What worked last time, if anything did, and if it worked often enough to
	 * be worth trusting.
	 *
	 * @return array{mode: string, segmentType: string, confidence: float, evidence: string}|null
	 */
	public function recall(string $signature): ?array {
		if (!$this->config->getBool('memory_enabled')) {
			return null;
		}
		$best = null;
		$bestConfidence = 0.0;
		foreach ($this->recipes->forSignature($signature) as $recipe) {
			$confidence = $recipe->confidence();
			if ($confidence > $bestConfidence) {
				$best = $recipe;
				$bestConfidence = $confidence;
			}
		}
		if ($best === null || $bestConfidence < self::TRUST_THRESHOLD) {
			return null;
		}
		return [
			'mode' => $best->getMode(),
			'segmentType' => $best->getSegmentType(),
			'confidence' => round($bestConfidence, 3),
			'evidence' => sprintf(
				'played this way %d time%s before without trouble',
				$best->getSuccesses(),
				$best->getSuccesses() === 1 ? '' : 's',
			),
		];
	}

	/** Modes known to have failed for this situation, so they are not tried again. */
	public function knownBad(string $signature): array {
		$bad = [];
		foreach ($this->recipes->forSignature($signature) as $recipe) {
			// One failure and nothing to set against it is enough to stop
			// choosing something; it may still be tried if nothing else is left.
			if ($recipe->getFailures() > 0 && $recipe->getSuccesses() === 0) {
				$bad[] = $recipe->getMode();
			}
		}
		return array_values(array_unique($bad));
	}

	/**
	 * Write down how it went.
	 *
	 * @param array{success?: bool, stalls?: int, watched?: int, firstSegmentMs?: int, sample?: array<string, mixed>} $outcome
	 */
	public function remember(string $signature, string $mode, string $segmentType, string $profile, array $outcome): void {
		if (!$this->config->getBool('memory_enabled') || $signature === '') {
			return;
		}
		try {
			// The quality is not part of the key: what is being learned is the
			// structure of the decision, not the bitrate it happened to use.
			$recipe = $this->recipes->find($signature, $mode, '');
			$isNew = $recipe === null;
			if ($recipe === null) {
				$recipe = new Recipe();
				$recipe->setSignature($signature);
				$recipe->setMode($mode);
				$recipe->setProfile('');
				$recipe->setCreatedAt(time());
			}
			$recipe->setSegmentType($segmentType);
			if (($outcome['success'] ?? false) === true) {
				$recipe->setSuccesses($recipe->getSuccesses() + 1);
			}
			if (($outcome['success'] ?? null) === false) {
				$recipe->setFailures($recipe->getFailures() + 1);
			}
			if (isset($outcome['stalls'])) {
				$recipe->setStalls($recipe->getStalls() + max(0, (int)$outcome['stalls']));
			}
			if (isset($outcome['watched'])) {
				$recipe->setWatchedSeconds($recipe->getWatchedSeconds() + max(0, (int)$outcome['watched']));
			}
			if (!empty($outcome['firstSegmentMs'])) {
				$previous = $recipe->getFirstSegmentMs();
				$now = (int)$outcome['firstSegmentMs'];
				// A running average, so one slow start does not define the record.
				$recipe->setFirstSegmentMs($previous > 0 ? (int)round(($previous * 3 + $now) / 4) : $now);
			}
			if (!empty($outcome['sample'])) {
				$recipe->setSample((string)json_encode($outcome['sample']));
			}
			$recipe->setUpdatedAt(time());
			$isNew ? $this->recipes->insert($recipe) : $this->recipes->update($recipe);
		} catch (\Throwable $e) {
			// Learning is a convenience. It must never stand between a viewer and
			// their film.
			$this->logger->debug('Video Gallery could not record a playback outcome: ' . $e->getMessage());
		}
	}

	/** @return list<array<string, mixed>> what has been learned, for the settings page */
	public function summary(int $limit = 50): array {
		$out = [];
		foreach ($this->recipes->all($limit) as $recipe) {
			$out[] = $recipe->jsonSerialize();
		}
		return $out;
	}

	public function forget(): int {
		return $this->recipes->clear();
	}

	/** Drop entries about situations nobody has been in for months. */
	public function prune(): int {
		return $this->recipes->prune(180 * 86400);
	}
}
