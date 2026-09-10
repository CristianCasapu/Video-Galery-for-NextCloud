<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Http;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;

/**
 * Sends a file, or the piece of one a player asked for.
 *
 * Byte ranges are what make a video seekable when it is sent as an ordinary
 * file: the player asks for the part it wants and gets exactly that, rather
 * than downloading everything up to it. Without this, dragging the progress bar
 * on a direct-played file would stall until the whole thing had arrived.
 */
class RangeResponse extends Response implements ICallbackResponse {
	private const CHUNK = 262144;

	private int $start = 0;
	private int $end = 0;
	private int $length = 0;

	/**
	 * @param resource|null $handle an already open handle, or null to open the path
	 */
	public function __construct(
		private string $path,
		string $contentType,
		?string $rangeHeader = null,
		private ?string $downloadName = null,
		private $handle = null,
		?int $size = null,
	) {
		parent::__construct();
		if ($size === null) {
			$size = $this->handle !== null ? (int)(fstat($this->handle)['size'] ?? 0) : (int)@filesize($path);
		}
		$this->end = max(0, $size - 1);
		$this->length = $size;

		$this->addHeader('Content-Type', $contentType);
		$this->addHeader('Accept-Ranges', 'bytes');
		if ($this->downloadName !== null) {
			$this->addHeader('Content-Disposition', 'attachment; filename="' . rawurlencode($this->downloadName) . '"');
		}

		if ($rangeHeader !== null && preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $m) === 1 && $size > 0) {
			$start = $m[1] === '' ? null : (int)$m[1];
			$end = $m[2] === '' ? null : (int)$m[2];
			if ($start === null && $end !== null) {
				// The last N bytes.
				$start = max(0, $size - $end);
				$end = $size - 1;
			} else {
				$start = $start ?? 0;
				$end = $end ?? $size - 1;
			}
			$end = min($end, $size - 1);
			if ($start > $end || $start >= $size) {
				$this->setStatus(Http::STATUS_REQUESTED_RANGE_NOT_SATISFIABLE);
				$this->addHeader('Content-Range', 'bytes */' . $size);
				$this->length = 0;
				return;
			}
			$this->start = $start;
			$this->end = $end;
			$this->length = $end - $start + 1;
			$this->setStatus(Http::STATUS_PARTIAL_CONTENT);
			$this->addHeader('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $size);
		}
		$this->addHeader('Content-Length', (string)$this->length);
	}

	public function callback(IOutput $output): void {
		if ($this->length <= 0) {
			return;
		}
		$handle = $this->handle ?? @fopen($this->path, 'rb');
		if (!is_resource($handle)) {
			$output->setHttpResponseCode(Http::STATUS_NOT_FOUND);
			return;
		}
		if ($this->start > 0) {
			fseek($handle, $this->start);
		}
		$remaining = $this->length;
		while ($remaining > 0 && !feof($handle)) {
			$chunk = fread($handle, (int)min(self::CHUNK, $remaining));
			if ($chunk === false || $chunk === '') {
				break;
			}
			echo $chunk;
			$remaining -= strlen($chunk);
			// Send it as it is read rather than building the whole thing in memory,
			// which matters when the file is a fifty gigabyte film.
			if (ob_get_level() > 0) {
				@ob_flush();
			}
			@flush();
			if (connection_aborted() !== 0) {
				break;
			}
		}
		fclose($handle);
	}
}
