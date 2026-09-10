<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Service;

use OCA\VideoGallery\Db\Item;
use OCA\VideoGallery\Db\Session;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Watches how playback is actually going and keeps adjusting.
 *
 * A single measurement taken before the film starts is a snapshot of a moving
 * thing: someone walks out of Wi-Fi range, a neighbour starts a download, the
 * link recovers. So the player keeps reporting what it is getting — how much it
 * has buffered, how often it ran dry, how fast segments are arriving — and the
 * quality is walked down when the link cannot keep up and back up when it can.
 *
 * Coming down happens quickly, because stuttering is the worst outcome. Going
 * up is deliberately slow and needs a wider margin than coming down did, so a
 * brief calm patch does not start the whole cycle again.
 */
class QualityGovernor {
	public const KEEP = 'keep';
	public const DOWN = 'down';
	public const UP = 'up';

	private ICache $cache;

	public function __construct(
		private Config $config,
		private PlaybackDecision $decision,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed('videogallery-governor');
	}

	/**
	 * @param array<string, mixed> $report what the player says it is seeing
	 * @return array{action: string, profile: string, reason: string, bandwidth: float}
	 */
	public function evaluate(Session $session, Item $item, array $report): array {
		$bandwidthKbps = max(0.0, (float)($report['bandwidthKbps'] ?? 0));
		$bufferSeconds = max(0.0, (float)($report['bufferSeconds'] ?? 0));
		$stalls = max(0, (int)($report['stalls'] ?? 0));
		$position = max(0.0, (float)($report['position'] ?? 0));
		$now = time();

		$state = $this->state($session->getToken());
		$state['stalls'] += $stalls;
		$state['reports']++;

		if (!$this->config->getBool('governor_enabled')) {
			$this->save($session->getToken(), $state);
			return $this->verdict(self::KEEP, $session->getProfile(), 'Automatic quality control is switched off.', $bandwidthKbps);
		}

		$ladder = $this->orderedLadder($item);
		$currentIndex = $this->indexOf($ladder, $session->getProfile());
		$cooldown = $this->config->getInt('shift_cooldown');
		$sinceShift = $now - (int)$state['last_shift'];

		// Coming down. Any of three symptoms is enough, and it is allowed to
		// happen straight away if the picture is actually breaking up.
		$starved = $bufferSeconds < $this->config->getFloat('min_buffer_seconds');
		$stalling = $state['stalls'] >= $this->config->getInt('stall_threshold');
		$currentKbps = $this->profileKbps($session->getProfile(), $item);
		$tooFast = $bandwidthKbps > 0 && $currentKbps > 0
			&& $bandwidthKbps < $currentKbps * $this->config->getFloat('bandwidth_safety_factor');

		if ($this->config->getBool('adaptive_downshift') && ($stalling || ($starved && $tooFast) || $tooFast)) {
			$target = $bandwidthKbps > 0
				? $this->decision->rungForBandwidth($bandwidthKbps, $item)
				: ($ladder[$currentIndex + 1] ?? null);
			$targetId = (string)($target['id'] ?? '');
			$targetIndex = $this->indexOf($ladder, $targetId);
			if ($targetId !== '' && $targetIndex > $currentIndex) {
				$state['stalls'] = 0;
				$state['stable_since'] = $now;
				$state['last_shift'] = $now;
				$this->save($session->getToken(), $state);
				return $this->verdict(
					self::DOWN,
					$targetId,
					$stalling
						? sprintf('Playback stopped to buffer, so the quality is going down to %s.', $target['label'] ?? $targetId)
						: sprintf('The link is carrying about %s, so the quality is going down to %s.', $this->fmt($bandwidthKbps), $target['label'] ?? $targetId),
					$bandwidthKbps,
				);
			}
			// Already at the bottom rung; nothing more to give up.
			$state['stalls'] = 0;
			$this->save($session->getToken(), $state);
			return $this->verdict(self::KEEP, $session->getProfile(), 'The connection is struggling, but this is already the lowest quality.', $bandwidthKbps);
		}

		// Going up. Requires a stretch with nothing going wrong, a comfortable
		// buffer, and enough measured headroom for the next rung up plus extra.
		$healthy = $stalls === 0 && $bufferSeconds >= $this->config->getFloat('min_buffer_seconds') * 1.5;
		if (!$healthy) {
			$state['stable_since'] = $now;
			$this->save($session->getToken(), $state);
			return $this->verdict(self::KEEP, $session->getProfile(), '', $bandwidthKbps);
		}

		$stableFor = $now - (int)$state['stable_since'];
		$next = $currentIndex > 0 ? ($ladder[$currentIndex - 1] ?? null) : null;
		if ($next === null || $stableFor < $this->config->getInt('upshift_stable_seconds') || $sinceShift < $cooldown) {
			$this->save($session->getToken(), $state);
			return $this->verdict(self::KEEP, $session->getProfile(), '', $bandwidthKbps);
		}

		$needed = $this->decision->rungKbps($next)
			* $this->config->getFloat('bandwidth_safety_factor')
			* $this->config->getFloat('upshift_hysteresis');
		if ($bandwidthKbps > 0 && $bandwidthKbps < $needed) {
			$this->save($session->getToken(), $state);
			return $this->verdict(self::KEEP, $session->getProfile(), '', $bandwidthKbps);
		}

		$state['last_shift'] = $now;
		$state['stable_since'] = $now;
		$this->save($session->getToken(), $state);
		return $this->verdict(
			self::UP,
			(string)$next['id'],
			sprintf('The connection has been steady at about %s, so the quality is going up to %s.', $this->fmt($bandwidthKbps), $next['label']),
			$bandwidthKbps,
		);
	}

	/**
	 * The rungs that make sense for this file, best first. Rungs above the
	 * source resolution are left out: nothing is gained by upscaling.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function orderedLadder(Item $item): array {
		$ladder = [];
		foreach ($this->decision->ladder() as $rung) {
			if ($rung['height'] > 0 && $item->getHeight() > 0 && $rung['height'] > $item->getHeight()) {
				continue;
			}
			$ladder[] = $rung;
		}
		usort($ladder, static function (array $a, array $b): int {
			// 'original' has height 0 but belongs at the top.
			$ha = $a['height'] === 0 ? PHP_INT_MAX : $a['height'];
			$hb = $b['height'] === 0 ? PHP_INT_MAX : $b['height'];
			return $hb <=> $ha;
		});
		return $ladder;
	}

	/** @param list<array<string, mixed>> $ladder */
	private function indexOf(array $ladder, string $id): int {
		foreach ($ladder as $index => $rung) {
			if ($rung['id'] === $id) {
				return $index;
			}
		}
		return 0;
	}

	private function profileKbps(string $profile, Item $item): float {
		if ($profile === 'original') {
			return $this->decision->sourceBitrateKbps($item);
		}
		$rung = $this->decision->rung($profile);
		return $rung === null ? 0.0 : $this->decision->rungKbps($rung);
	}

	/** @return array<string, int> */
	private function state(string $token): array {
		$stored = $this->cache->get('state-' . $token);
		if (is_array($stored)) {
			return $stored + ['stalls' => 0, 'reports' => 0, 'stable_since' => time(), 'last_shift' => 0];
		}
		return ['stalls' => 0, 'reports' => 0, 'stable_since' => time(), 'last_shift' => 0];
	}

	/** @param array<string, int> $state */
	private function save(string $token, array $state): void {
		$this->cache->set('state-' . $token, $state, 7200);
	}

	public function forget(string $token): void {
		$this->cache->remove('state-' . $token);
	}

	/** @return array{action: string, profile: string, reason: string, bandwidth: float} */
	private function verdict(string $action, string $profile, string $reason, float $bandwidth): array {
		return ['action' => $action, 'profile' => $profile, 'reason' => $reason, 'bandwidth' => round($bandwidth)];
	}

	private function fmt(float $kbps): string {
		if ($kbps <= 0) {
			return 'an unknown speed';
		}
		return $kbps >= 1000 ? sprintf('%.1f Mbit/s', $kbps / 1000) : sprintf('%d kbit/s', (int)round($kbps));
	}
}
