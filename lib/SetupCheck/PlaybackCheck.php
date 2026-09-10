<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\SetupCheck;

use OCA\VideoGallery\Service\Environment;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Puts the app's own findings on the Nextcloud administration overview, so a
 * missing piece is noticed without anyone opening the app's settings page.
 */
class PlaybackCheck implements ISetupCheck {
	public function __construct(
		private Environment $environment,
		private IL10N $l10n,
	) {
	}

	public function getCategory(): string {
		return 'system';
	}

	public function getName(): string {
		return $this->l10n->t('Video Gallery playback');
	}

	public function run(): SetupResult {
		$report = $this->environment->report();
		$lines = [];
		foreach ($report['checks'] as $check) {
			if ($check['status'] === Environment::OK) {
				continue;
			}
			$line = $check['summary'];
			if (!empty($check['hint'])) {
				$line .= ' ' . $check['hint'];
			}
			$lines[] = $line;
		}
		if ($lines === []) {
			return SetupResult::success($this->l10n->t('Every format can be played.'));
		}
		$message = implode("\n", $lines);
		return $report['status'] === Environment::ERROR
			? SetupResult::error($message)
			: SetupResult::warning($message);
	}
}
