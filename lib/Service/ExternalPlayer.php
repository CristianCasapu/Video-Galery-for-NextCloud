<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Service;

use OCP\IConfig;
use OCP\IURLGenerator;

/**
 * Hands the untouched original to a real player on the device.
 *
 * No browser can be made to embed VLC — the plugin interfaces that once allowed
 * it were removed from every browser years ago. What can be done is to give the
 * player on the device a link it can open by itself, which is how the file gets
 * played bit for bit, with no conversion and no loss, on a television or a phone.
 *
 * The link carries its own authorisation as a signed token, because an external
 * player has no Nextcloud session and sends no cookies. Nothing is stored to
 * make this work, so nothing is left behind when it expires.
 */
class ExternalPlayer {
	public function __construct(
		private Config $config,
		private IConfig $serverConfig,
		private IURLGenerator $urlGenerator,
	) {
	}

	/** A token good for one file, one account, and a limited time. */
	public function mint(string $userId, int $fileId): string {
		$expires = time() + max(60, $this->config->getInt('external_token_ttl'));
		$payload = $userId . ':' . $fileId . ':' . $expires;
		$encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
		return $encoded . '.' . $this->sign($encoded);
	}

	/**
	 * @return array{userId: string, fileId: int}|null null when forged or expired
	 */
	public function verify(string $token): ?array {
		$parts = explode('.', $token, 2);
		if (count($parts) !== 2) {
			return null;
		}
		[$encoded, $signature] = $parts;
		if (!hash_equals($this->sign($encoded), $signature)) {
			return null;
		}
		$payload = base64_decode(strtr($encoded, '-_', '+/'), true);
		if ($payload === false) {
			return null;
		}
		$fields = explode(':', $payload);
		if (count($fields) !== 3) {
			return null;
		}
		[$userId, $fileId, $expires] = $fields;
		if ((int)$expires < time()) {
			return null;
		}
		return ['userId' => $userId, 'fileId' => (int)$fileId];
	}

	private function sign(string $value): string {
		$secret = (string)$this->serverConfig->getSystemValue('secret', '');
		return hash_hmac('sha256', 'videogallery:' . $value, $secret);
	}

	public function fileUrl(string $token): string {
		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->linkToRoute('videogallery.external.file', ['token' => $token]),
		);
	}

	public function playlistUrl(string $token): string {
		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->linkToRoute('videogallery.external.playlist', ['token' => $token]),
		);
	}

	/**
	 * An M3U playlist naming the file. Handing a player a playlist rather than
	 * the file itself is what makes the device open it in a player instead of
	 * downloading it, and it carries the title along.
	 */
	public function playlist(string $title, string $url, int $durationSeconds): string {
		return "#EXTM3U\n"
			. '#EXTINF:' . max(-1, $durationSeconds) . ',' . str_replace(["\n", "\r"], ' ', $title) . "\n"
			. $url . "\n";
	}

	/**
	 * The handful of links worth offering, one per kind of device.
	 *
	 * @return array<string, string>
	 */
	public function links(string $token, string $title, int $durationSeconds): array {
		$fileUrl = $this->fileUrl($token);
		$withoutScheme = preg_replace('#^https?://#', '', $fileUrl) ?? $fileUrl;
		return [
			'direct' => $fileUrl,
			'playlist' => $this->playlistUrl($token),
			// VLC on desktop registers this scheme; on Android the intent form
			// names the package so the chooser does not appear.
			'vlc' => 'vlc://' . $withoutScheme,
			'android' => 'intent:' . $fileUrl . '#Intent;package=org.videolan.vlc;type=video/*;S.title='
				. rawurlencode($title) . ';end',
			// Infuse and VLC for iOS both take these.
			'infuse' => 'infuse://x-callback-url/play?url=' . rawurlencode($fileUrl),
			'vlcios' => 'vlc-x-callback://x-callback-url/stream?url=' . rawurlencode($fileUrl),
			'expires' => (string)(time() + $this->config->getInt('external_token_ttl')),
		];
	}
}
