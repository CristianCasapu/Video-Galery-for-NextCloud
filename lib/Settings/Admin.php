<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Settings;

use OCA\VideoGallery\AppInfo\Application;
use OCA\VideoGallery\Db\ItemMapper;
use OCA\VideoGallery\Service\Config;
use OCA\VideoGallery\Service\Environment;
use OCA\VideoGallery\Service\FFmpeg;
use OCA\VideoGallery\Service\Janitor;
use OCA\VideoGallery\Service\Paths;
use OCA\VideoGallery\Service\PlaybackMemory;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings {
	public function __construct(
		private IInitialState $initialState,
		private Config $config,
		private Environment $environment,
		private Paths $paths,
		private Janitor $janitor,
		private ItemMapper $items,
		private PlaybackMemory $memory,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState('admin', [
			'settings' => $this->config->all(),
			'defaults' => Config::DEFAULTS,
			'environment' => $this->environment->report(),
			'cache' => $this->janitor->report(),
			'library' => $this->items->stats(),
			'candidates' => $this->paths->candidates(),
			'encoders' => FFmpeg::ENCODERS,
			'memory' => $this->memory->summary(30),
		]);
		Util::addScript(Application::APP_ID, 'videogallery-admin');
		return new TemplateResponse(Application::APP_ID, 'admin', [], '');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 50;
	}
}
