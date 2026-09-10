<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Listener;

use OCA\VideoGallery\Service\Janitor;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * When an account goes, so does everything this app kept about it.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private Janitor $janitor,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof UserDeletedEvent) {
			return;
		}
		try {
			$this->janitor->purgeUser($event->getUser()->getUID());
		} catch (\Throwable $e) {
			$this->logger->error('Video Gallery could not clear up after a deleted account: ' . $e->getMessage(), ['exception' => $e]);
		}
	}
}
