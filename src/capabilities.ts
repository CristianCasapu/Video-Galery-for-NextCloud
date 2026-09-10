// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ClientCapabilities } from './types'

/**
 * Asks the browser what it can actually decode, rather than guessing from its
 * name and version.
 *
 * Codec support is not a property of a browser so much as of the machine it is
 * running on: the same Chrome plays HEVC on one laptop and refuses it on the
 * next, because the answer comes from the graphics hardware underneath. The
 * only reliable source is the browser itself, and it will answer if asked in
 * the right way — so we ask, once, and send the list to the server with every
 * request to play something.
 */

/** Candidate codecs, each with the MIME string that identifies it. */
const VIDEO_PROBES: Array<[string, string[]]> = [
	// H.264 High profile, the one thing everything has played for fifteen years.
	['h264', ['video/mp4; codecs="avc1.640028"', 'video/mp4; codecs="avc1.42E01E"']],
	// HEVC, Main and Main 10. Safari and Edge on suitable hardware.
	['hevc', ['video/mp4; codecs="hvc1.1.6.L93.B0"', 'video/mp4; codecs="hev1.1.6.L93.B0"']],
	['vp8', ['video/webm; codecs="vp8"']],
	['vp9', ['video/webm; codecs="vp9"', 'video/mp4; codecs="vp09.00.10.08"']],
	['av1', ['video/mp4; codecs="av01.0.05M.08"', 'video/webm; codecs="av01.0.05M.08"']],
	['mpeg4', ['video/mp4; codecs="mp4v.20.8"']],
	['theora', ['video/ogg; codecs="theora"']],
]

const AUDIO_PROBES: Array<[string, string[]]> = [
	['aac', ['audio/mp4; codecs="mp4a.40.2"']],
	['mp3', ['audio/mpeg']],
	['opus', ['audio/webm; codecs="opus"', 'audio/ogg; codecs="opus"']],
	['vorbis', ['audio/webm; codecs="vorbis"']],
	['flac', ['audio/mp4; codecs="flac"', 'audio/flac']],
	['alac', ['audio/mp4; codecs="alac"']],
	// Dolby Digital: Safari and Edge, and only sometimes.
	['ac3', ['audio/mp4; codecs="ac-3"']],
	['eac3', ['audio/mp4; codecs="ec-3"']],
]

const CONTAINER_PROBES: Array<[string, string[]]> = [
	['mp4', ['video/mp4']],
	['webm', ['video/webm']],
	['ogg', ['video/ogg']],
	// Matroska: Chrome will often open it, Safari and Firefox will not. The
	// difference decides whether an MKV is sent as it is or repackaged, so it
	// is worth asking about rather than assuming either way.
	['matroska', ['video/x-matroska', 'video/x-matroska; codecs="avc1.640028, mp4a.40.2"']],
]

let cached: ClientCapabilities | null = null

/** True only when the browser says "probably", which is the only honest yes. */
function playsWell(element: HTMLVideoElement, mime: string): boolean {
	try {
		return element.canPlayType(mime) === 'probably'
	} catch {
		return false
	}
}

/** Media Source Extensions answer plainly, and are what hls.js will use anyway. */
function mediaSourceSupports(mime: string): boolean {
	try {
		// Safari on the iPhone offers this in place of MediaSource, and it is the
		// only one of the two that answers there.
		const managed = (window as unknown as {
			ManagedMediaSource?: { isTypeSupported?: (mime: string) => boolean }
		}).ManagedMediaSource
		if (managed?.isTypeSupported?.(mime)) {
			return true
		}
	} catch {
		// not present on this browser
	}
	try {
		return typeof MediaSource !== 'undefined' && MediaSource.isTypeSupported(mime)
	} catch {
		return false
	}
}

export function detectCapabilities(): ClientCapabilities {
	if (cached) {
		return cached
	}
	const element = document.createElement('video')
	const collect = (probes: Array<[string, string[]]>, strict: boolean): string[] => {
		const found: string[] = []
		for (const [name, mimes] of probes) {
			const supported = mimes.some((mime) => (strict
				? playsWell(element, mime) || mediaSourceSupports(mime)
				: element.canPlayType(mime) !== ''))
			if (supported) {
				found.push(name)
			}
		}
		return found
	}

	cached = {
		video: collect(VIDEO_PROBES, true),
		audio: collect(AUDIO_PROBES, true),
		// A container claim is only accepted on a firm "probably": a "maybe" here
		// is how a file ends up opening to a black rectangle.
		containers: CONTAINER_PROBES
			.filter(([, mimes]) => mimes.some((mime) => playsWell(element, mime)))
			.map(([name]) => name),
		hls: playsWell(element, 'application/vnd.apple.mpegurl')
			|| element.canPlayType('application/vnd.apple.mpegurl') !== '',
		mse: typeof MediaSource !== 'undefined',
	}
	return cached
}

/** A short line for the details panel, so a viewer can see why a file is being converted. */
export function describeCapabilities(): string {
	const caps = detectCapabilities()
	return `${caps.video.join(', ') || 'nothing'} · ${caps.audio.join(', ') || 'nothing'}`
}
