// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

import { generateUrl } from '@nextcloud/router'

/**
 * Finds out how fast the link to the server really is.
 *
 * Not what the connection is advertised as, and not what it managed an hour
 * ago — what it will carry now. A file is only sent untouched when the answer
 * says it will arrive in time, and the measurement is taken again during
 * playback, because a link is a moving thing.
 */

interface Measurement {
	kbps: number
	at: number
}

const STORAGE_KEY = 'videogallery-bandwidth'
let inFlight: Promise<number> | null = null

function remembered(ttlSeconds: number): number | null {
	try {
		const raw = window.sessionStorage.getItem(STORAGE_KEY)
		if (!raw) {
			return null
		}
		const stored = JSON.parse(raw) as Measurement
		if (!stored?.kbps || (Date.now() - stored.at) / 1000 > ttlSeconds) {
			return null
		}
		return stored.kbps
	} catch {
		return null
	}
}

function remember(kbps: number): void {
	try {
		window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ kbps, at: Date.now() } as Measurement))
	} catch {
		// a private window with storage switched off; measuring again is no hardship
	}
}

/**
 * Download a known quantity of incompressible bytes and time it.
 *
 * The first slice of any transfer is spent working up to speed rather than at
 * it, so the clock starts when the first byte lands, not when the request was
 * sent. Otherwise every measurement on a distant server would be an
 * underestimate of the link and a perfectly good file would be re-encoded for
 * no reason.
 */
async function measure(bytes: number): Promise<number> {
	const url = generateUrl('/apps/videogallery/bandwidth?bytes={bytes}&t={t}', {
		bytes: String(bytes),
		t: String(Date.now()),
	})
	const requestedAt = performance.now()
	const response = await fetch(url, { cache: 'no-store', credentials: 'same-origin' })
	if (!response.ok || !response.body) {
		// Without a readable stream, fall back to timing the whole thing.
		const blob = await response.blob()
		const seconds = (performance.now() - requestedAt) / 1000
		return seconds > 0 ? (blob.size * 8) / seconds / 1000 : 0
	}

	const reader = response.body.getReader()
	let received = 0
	let firstByteAt = 0
	for (;;) {
		const { done, value } = await reader.read()
		if (done) {
			break
		}
		if (!firstByteAt) {
			firstByteAt = performance.now()
		}
		received += value?.length ?? 0
	}
	const seconds = (performance.now() - (firstByteAt || requestedAt)) / 1000
	if (seconds <= 0.001 || received === 0) {
		return 0
	}
	return (received * 8) / seconds / 1000
}

/**
 * The measured speed in kbit/s, taking a fresh reading only when the last one
 * has gone stale.
 */
export async function currentBandwidth(bytes: number, ttlSeconds: number, force = false): Promise<number> {
	if (!force) {
		const stored = remembered(ttlSeconds)
		if (stored !== null) {
			return stored
		}
	}
	// Several cards asking at once should still only cost one measurement, and a
	// second download running alongside the first would make both look slower.
	if (inFlight) {
		return inFlight
	}
	inFlight = (async () => {
		try {
			const kbps = await measure(bytes)
			if (kbps > 0) {
				remember(kbps)
			}
			return kbps
		} catch {
			return 0
		} finally {
			inFlight = null
		}
	})()
	return inFlight
}

/** Written out for a person to read. */
export function formatBandwidth(kbps: number): string {
	if (!kbps) {
		return 'unknown'
	}
	return kbps >= 1000 ? `${(kbps / 1000).toFixed(1)} Mbit/s` : `${Math.round(kbps)} kbit/s`
}
