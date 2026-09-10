<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Listener;

use OCA\VideoGallery\Service\Indexer;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * Keeps the index level with the file tree.
 *
 * These fire inside somebody's upload, so the work here stays to a single row
 * write: the expensive probe is left to the background job.
 *
 * @template-implements IEventListener<Event>
 */
class FileEventListener implements IEventListener {
	public function __construct(
		private Indexer $indexer,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		try {
			match (true) {
				$event instanceof NodeCreatedEvent, $event instanceof NodeWrittenEvent => $this->touch($event->getNode()),
				$event instanceof NodeDeletedEvent => $this->forget($event->getNode()),
				$event instanceof NodeRenamedEvent => $this->rename($event->getSource(), $event->getTarget()),
				default => null,
			};
		} catch (\Throwable $e) {
			// An indexing hiccup must never break a file operation.
			$this->logger->debug('Video Gallery could not follow a file event: ' . $e->getMessage(), ['exception' => $e]);
		}
	}

	private function touch(Node $node): void {
		if (!$node instanceof File || !$this->indexer->isVideo($node)) {
			return;
		}
		$this->indexer->enqueue($node);
	}

	private function forget(Node $node): void {
		if ($node instanceof File) {
			$this->indexer->forget($node->getId());
		}
	}

	private function rename(Node $source, Node $target): void {
		if (!$target instanceof File) {
			return;
		}
		if ($this->indexer->isVideo($target)) {
			// A move changes the path and the folder rail it belongs to, but not
			// the content, so the probe result stays valid.
			$this->indexer->relocate($target);
		} else {
			$this->indexer->forget($target->getId());
		}
	}
}
