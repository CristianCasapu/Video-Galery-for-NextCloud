<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One remembered way of playing one kind of file to one kind of browser.
 *
 * @method string getSignature()
 * @method void setSignature(string $signature)
 * @method string getMode()
 * @method void setMode(string $mode)
 * @method string getSegmentType()
 * @method void setSegmentType(string $segmentType)
 * @method string getProfile()
 * @method void setProfile(string $profile)
 * @method int getSuccesses()
 * @method void setSuccesses(int $successes)
 * @method int getFailures()
 * @method void setFailures(int $failures)
 * @method int getStalls()
 * @method void setStalls(int $stalls)
 * @method int getWatchedSeconds()
 * @method void setWatchedSeconds(int $watchedSeconds)
 * @method int getFirstSegmentMs()
 * @method void setFirstSegmentMs(int $firstSegmentMs)
 * @method string|null getSample()
 * @method void setSample(?string $sample)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Recipe extends Entity implements \JsonSerializable {
	protected string $signature = '';
	protected string $mode = '';
	protected string $segmentType = 'ts';
	protected string $profile = '';
	protected int $successes = 0;
	protected int $failures = 0;
	protected int $stalls = 0;
	protected int $watchedSeconds = 0;
	protected int $firstSegmentMs = 0;
	protected ?string $sample = null;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('successes', 'integer');
		$this->addType('failures', 'integer');
		$this->addType('stalls', 'integer');
		$this->addType('watchedSeconds', 'integer');
		$this->addType('firstSegmentMs', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	/**
	 * How much this is worth believing.
	 *
	 * A single success is a hint; a dozen with no stumbles is knowledge. A
	 * failure counts against far more heavily than a success counts for, because
	 * the cost of the two is not the same: a slightly worse choice is a mild
	 * disappointment, and a choice that does not play at all is a broken page.
	 */
	public function confidence(): float {
		$attempts = $this->successes + $this->failures;
		if ($attempts === 0) {
			return 0.0;
		}
		$rate = ($this->successes - ($this->failures * 3)) / $attempts;
		// Rises with evidence, and never quite reaches certainty.
		$weight = min(1.0, $attempts / 5);
		$smoothness = $this->watchedSeconds > 60
			? max(0.0, 1.0 - ($this->stalls / max(1, $this->watchedSeconds / 60)) / 4)
			: 1.0;
		return max(0.0, min(1.0, $rate * $weight * $smoothness));
	}

	public function jsonSerialize(): array {
		return [
			'signature' => $this->signature,
			'mode' => $this->mode,
			'segmentType' => $this->segmentType,
			'profile' => $this->profile,
			'successes' => $this->successes,
			'failures' => $this->failures,
			'stalls' => $this->stalls,
			'watchedSeconds' => $this->watchedSeconds,
			'firstSegmentMs' => $this->firstSegmentMs,
			'confidence' => round($this->confidence(), 3),
			'sample' => json_decode((string)$this->sample, true),
			'updatedAt' => $this->updatedAt,
		];
	}
}
