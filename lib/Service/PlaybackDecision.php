<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Service;

use OCA\VideoGallery\Db\Item;

/**
 * Decides how a given file should reach a given browser over a given link.
 *
 * Two questions, in order. Can this browser open this file at all? And even if
 * it can, will the connection carry it without stalling? A file that plays
 * perfectly on the sofa over Wi-Fi is the same file that stutters on a phone on
 * mobile data, so the answer depends on the measurement the client just took,
 * not on the file alone.
 */
class PlaybackDecision {
	public const DIRECT = 'direct';
	public const REMUX = 'remux';
	public const TRANSCODE_AUDIO = 'transcode_audio';
	public const TRANSCODE = 'transcode';

	/** Containers a browser will open. Anything else has to be repackaged. */
	private const BROWSER_CONTAINERS = ['mp4', 'mov', 'm4v', 'webm', 'ogg'];

	/** Audio a browser will decode, when the container allows it. */
	private const SAFE_AUDIO = ['aac', 'mp3', 'opus', 'vorbis'];

	/** Audio that only some browsers decode; the client tells us which. */
	private const OPTIONAL_AUDIO = ['ac3', 'eac3', 'flac', 'alac', 'pcm_s16le'];

	public function __construct(
		private Config $config,
	) {
	}

	/**
	 * @param array<string, mixed> $client what the browser reported it can play
	 * @param float $bandwidthKbps measured link speed, 0 when unknown
	 * @param string $requested a ladder id, 'auto', or '' for automatic
	 * @param array{mode: string, segmentType: string, confidence: float, evidence: string}|null $remembered
	 *        what worked for this same situation before, if anything did
	 * @param list<string> $knownBad modes that have failed for this situation
	 * @return array<string, mixed>
	 */
	public function decide(Item $item, array $client, float $bandwidthKbps = 0.0, string $requested = 'auto', ?array $remembered = null, array $knownBad = []): array {
		$reasons = [];
		$compatible = $this->checkCompatibility($item, $client, $reasons);

		// Experience overrides the rules, because the rules are reasoning about
		// what a browser says it can do and experience is a record of what it
		// actually did.
		if ($remembered !== null && $requested === 'auto' && $remembered['mode'] !== $compatible['mode']) {
			$compatible['mode'] = $remembered['mode'];
			$compatible['playable'] = $remembered['mode'] === self::DIRECT;
			$compatible['remembered'] = true;
			$reasons[] = ucfirst($remembered['evidence']) . '.';
		}
		// A way that has failed here before is not tried again while another remains.
		if ($knownBad !== [] && in_array($compatible['mode'], $knownBad, true) && $compatible['mode'] !== self::TRANSCODE) {
			$compatible['mode'] = self::TRANSCODE;
			$compatible['playable'] = false;
			$reasons[] = 'A lighter way of sending this failed here before, so it is being converted in full.';
		}

		$sourceKbps = $this->sourceBitrateKbps($item);
		$safety = $this->config->getFloat('bandwidth_safety_factor');
		$probeEnabled = $this->config->getBool('bandwidth_probe_enabled');
		$bandwidthKnown = $probeEnabled && $bandwidthKbps > 0;
		$fitsLink = !$bandwidthKnown || $sourceKbps <= 0 || ($sourceKbps * $safety) <= $bandwidthKbps;

		if (!$this->config->getBool('transcode_enabled')) {
			return $this->result(self::DIRECT, 'original', $sourceKbps, ['Transcoding is switched off; sending the file as it is.'], $compatible, $bandwidthKbps, $sourceKbps);
		}

		// An explicit choice from the viewer wins over both checks: they asked for it.
		if ($requested !== '' && $requested !== 'auto') {
			if ($requested === 'original') {
				$mode = $compatible['playable'] ? self::DIRECT : $compatible['mode'];
				return $this->result($mode, 'original', $sourceKbps, ['Original quality was chosen in the player.'], $compatible, $bandwidthKbps, $sourceKbps);
			}
			$rung = $this->rung($requested);
			if ($rung !== null) {
				return $this->result(self::TRANSCODE, $rung['id'], $this->rungKbps($rung), [$rung['label'] . ' was chosen in the player.'], $compatible, $bandwidthKbps, $sourceKbps);
			}
		}

		if ($compatible['playable'] && $fitsLink && $this->config->getBool('direct_play_enabled')) {
			$reasons[] = $bandwidthKnown
				? sprintf('The link measured %s and the file needs about %s, so it is sent untouched.', $this->fmt($bandwidthKbps), $this->fmt($sourceKbps))
				: 'The browser can open this file, so it is sent untouched.';
			return $this->result(self::DIRECT, 'original', $sourceKbps, $reasons, $compatible, $bandwidthKbps, $sourceKbps);
		}

		if ($compatible['playable'] && !$fitsLink) {
			$rung = $this->rungForBandwidth($bandwidthKbps, $item);
			$reasons[] = sprintf(
				'The file needs about %s but the link measured %s, so it is re-encoded to %s to play without stalling.',
				$this->fmt($sourceKbps),
				$this->fmt($bandwidthKbps),
				$rung['label'],
			);
			return $this->result(self::TRANSCODE, $rung['id'], $this->rungKbps($rung), $reasons, $compatible, $bandwidthKbps, $sourceKbps);
		}

		// Not playable as it stands. Take the cheapest repair that works, unless
		// the link forces a full re-encode anyway.
		$mode = $compatible['mode'];
		if (!$fitsLink && in_array($mode, [self::REMUX, self::TRANSCODE_AUDIO], true)) {
			$rung = $this->rungForBandwidth($bandwidthKbps, $item);
			$reasons[] = sprintf('The link measured %s, so the video is re-encoded to %s as well.', $this->fmt($bandwidthKbps), $rung['label']);
			return $this->result(self::TRANSCODE, $rung['id'], $this->rungKbps($rung), $reasons, $compatible, $bandwidthKbps, $sourceKbps);
		}
		if ($mode === self::TRANSCODE) {
			$rung = $bandwidthKnown ? $this->rungForBandwidth($bandwidthKbps, $item) : $this->rungForSource($item);
			return $this->result(self::TRANSCODE, $rung['id'], $this->rungKbps($rung), $reasons, $compatible, $bandwidthKbps, $sourceKbps);
		}
		return $this->result($mode, 'original', $sourceKbps, $reasons, $compatible, $bandwidthKbps, $sourceKbps);
	}

	/**
	 * @param list<string> $reasons
	 * @return array{playable: bool, mode: string, video: bool, audio: bool, container: bool}
	 */
	private function checkCompatibility(Item $item, array $client, array &$reasons): array {
		$videoOk = $this->videoPlayable($item, $client, $reasons);
		$audioOk = $this->audioPlayable($item, $client, $reasons);
		$containerOk = $this->containerPlayable($item, $client, $reasons);

		$mode = match (true) {
			$videoOk && $audioOk && $containerOk => self::DIRECT,
			$videoOk && $audioOk => self::REMUX,
			$videoOk => self::TRANSCODE_AUDIO,
			default => self::TRANSCODE,
		};
		return [
			'playable' => $mode === self::DIRECT,
			'mode' => $mode,
			'video' => $videoOk,
			'audio' => $audioOk,
			'container' => $containerOk,
		];
	}

	private function videoPlayable(Item $item, array $client, array &$reasons): bool {
		$codec = strtolower($item->getVcodec());
		$supported = array_map('strtolower', (array)($client['video'] ?? []));

		if ($item->getBitDepth() > 8 && $codec === 'h264') {
			$reasons[] = '10-bit H.264 is not decodable in browsers.';
			return false;
		}
		if ($item->getHdr() === 1 && !in_array('hdr', $supported, true)) {
			$reasons[] = 'HDR needs tone mapping for this display.';
			return false;
		}
		if ($supported !== []) {
			// The browser answered for itself; believe it.
			if (in_array($codec, $supported, true)) {
				return true;
			}
			$reasons[] = 'This browser reported it cannot decode ' . strtoupper($codec) . '.';
			return false;
		}
		// No answer from the client: assume only the universally safe codec.
		if ($codec === 'h264') {
			return true;
		}
		$reasons[] = strtoupper($codec) . ' is not playable everywhere.';
		return false;
	}

	private function audioPlayable(Item $item, array $client, array &$reasons): bool {
		$codec = strtolower($item->getAcodec());
		if ($codec === '') {
			return true;
		}
		$supported = array_map('strtolower', (array)($client['audio'] ?? []));
		if (in_array($codec, self::SAFE_AUDIO, true)) {
			return true;
		}
		if (in_array($codec, self::OPTIONAL_AUDIO, true) && in_array($codec, $supported, true)) {
			return true;
		}
		$reasons[] = strtoupper($codec) . ' audio is not decodable here.';
		return false;
	}

	private function containerPlayable(Item $item, array $client, array &$reasons): bool {
		$container = strtolower($item->getContainer());
		$claimed = array_map('strtolower', (array)($client['containers'] ?? []));

		// Matroska is the awkward one. Some browsers open it and some refuse,
		// and the difference is not something to guess at, so the browser is
		// asked directly and only a plain yes counts.
		if (str_starts_with($container, 'matroska')) {
			if (in_array('matroska', $claimed, true)) {
				return true;
			}
			$reasons[] = 'MKV needs repackaging for this browser.';
			return false;
		}
		foreach (self::BROWSER_CONTAINERS as $known) {
			if ($container === $known || str_starts_with($container, $known)) {
				return true;
			}
		}
		$reasons[] = strtoupper($container === '' ? 'this container' : $container) . ' is not a container browsers open.';
		return false;
	}

	/** Total bitrate of the source in kbit/s, estimated from size when unstated. */
	public function sourceBitrateKbps(Item $item): float {
		if ($item->getBitrate() > 0) {
			return $item->getBitrate() / 1000.0;
		}
		$seconds = $item->getDurationMs() / 1000.0;
		if ($seconds > 0.5 && $item->getSize() > 0) {
			return ($item->getSize() * 8.0) / $seconds / 1000.0;
		}
		return 0.0;
	}

	/**
	 * The highest rung that fits inside the measured link, with headroom.
	 *
	 * @return array<string, mixed>
	 */
	public function rungForBandwidth(float $bandwidthKbps, ?Item $item = null): array {
		$ladder = $this->ladder();
		$safety = $this->config->getFloat('bandwidth_safety_factor');
		$ceiling = $item !== null ? $item->getHeight() : 0;

		$best = null;
		foreach ($ladder as $rung) {
			if ($rung['height'] <= 0) {
				continue;
			}
			// Never upscale: a 480p source gets no better by being sent as 1080p.
			if ($ceiling > 0 && $rung['height'] > $ceiling) {
				continue;
			}
			if ($bandwidthKbps > 0 && $this->rungKbps($rung) * $safety > $bandwidthKbps) {
				continue;
			}
			if ($best === null || $rung['height'] > $best['height']) {
				$best = $rung;
			}
		}
		if ($best !== null) {
			return $best;
		}
		// Even the smallest rung does not fit; send the smallest anyway rather
		// than refusing to play.
		$smallest = null;
		foreach ($ladder as $rung) {
			if ($rung['height'] > 0 && ($smallest === null || $rung['height'] < $smallest['height'])) {
				$smallest = $rung;
			}
		}
		return $smallest ?? ['id' => '480p', 'label' => '480p', 'height' => 480, 'bitrate' => 1500];
	}

	/** @return array<string, mixed> */
	private function rungForSource(Item $item): array {
		$height = $item->getHeight();
		$best = null;
		foreach ($this->ladder() as $rung) {
			if ($rung['height'] <= 0 || $rung['height'] > max($height, 1)) {
				continue;
			}
			if ($best === null || $rung['height'] > $best['height']) {
				$best = $rung;
			}
		}
		return $best ?? ['id' => '720p', 'label' => '720p', 'height' => 720, 'bitrate' => 4000];
	}

	/** @return list<array<string, mixed>> */
	public function ladder(): array {
		$ladder = [];
		foreach ($this->config->getArray('quality_ladder') as $rung) {
			if (!is_array($rung) || !isset($rung['id'])) {
				continue;
			}
			$ladder[] = [
				'id' => (string)$rung['id'],
				'label' => (string)($rung['label'] ?? $rung['id']),
				'height' => (int)($rung['height'] ?? 0),
				'bitrate' => (int)($rung['bitrate'] ?? 0),
			];
		}
		return $ladder;
	}

	/** @return array<string, mixed>|null */
	public function rung(string $id): ?array {
		foreach ($this->ladder() as $rung) {
			if ($rung['id'] === $id) {
				return $rung;
			}
		}
		return null;
	}

	/** Video plus audio, in kbit/s, for one rung. */
	public function rungKbps(array $rung): float {
		return (float)($rung['bitrate'] ?? 0) + 160.0;
	}

	/**
	 * @param list<string> $reasons
	 * @param array<string, mixed> $compatible
	 * @return array<string, mixed>
	 */
	private function result(string $mode, string $profile, float $kbps, array $reasons, array $compatible, float $bandwidthKbps, float $sourceKbps): array {
		return [
			'mode' => $mode,
			'profile' => $profile,
			'target_kbps' => round($kbps),
			'source_kbps' => round($sourceKbps),
			'bandwidth_kbps' => round($bandwidthKbps),
			'compatible' => $compatible,
			'reasons' => array_values(array_unique($reasons)),
			'transcoding' => $mode !== self::DIRECT,
		];
	}

	private function fmt(float $kbps): string {
		if ($kbps <= 0) {
			return 'an unknown speed';
		}
		return $kbps >= 1000
			? sprintf('%.1f Mbit/s', $kbps / 1000)
			: sprintf('%d kbit/s', (int)round($kbps));
	}
}
