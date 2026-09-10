// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import type {
	ExternalLinks,
	OpenResult,
	Rail,
	SpriteLayout,
	SubtitleOption,
	VideoItem,
	WatchProgress,
} from './types'

const ocs = (path: string, params: Record<string, unknown> = {}) =>
	generateOcsUrl('apps/videogallery/api/v1/' + path, params)

/** The front page: the featured video and every row under it. */
export async function fetchRails(): Promise<{ hero: VideoItem | null, rails: Rail[], stats: Record<string, number> }> {
	const { data } = await axios.get(ocs('rails'))
	return data.ocs.data
}

export async function fetchItems(params: Record<string, unknown> = {}): Promise<{ items: VideoItem[], total: number }> {
	const { data } = await axios.get(ocs('items'), { params })
	return data.ocs.data
}

export async function fetchTimeline(params: Record<string, unknown> = {}): Promise<{
	days: Array<{ day: string, label: string, items: VideoItem[] }>
	total: number
}> {
	const { data } = await axios.get(ocs('timeline'), { params })
	return data.ocs.data
}

export async function fetchItem(fileId: number): Promise<{
	item: VideoItem
	progress: WatchProgress | null
	subtitles: SubtitleOption[]
	sprite: SpriteLayout | null
	sourceKbps: number
}> {
	const { data } = await axios.get(ocs('items/{fileId}', { fileId }))
	return data.ocs.data
}

export async function fetchFolders(): Promise<{ folders: Array<{ path: string, count: number }> }> {
	const { data } = await axios.get(ocs('folders'))
	return data.ocs.data
}

export async function saveProgress(fileId: number, payload: {
	position: number
	duration?: number
	finished?: boolean
	audioIndex?: number
	subIndex?: number
}): Promise<void> {
	await axios.put(ocs('progress/{fileId}', { fileId }), payload)
}

export async function clearProgress(fileId: number): Promise<void> {
	await axios.delete(ocs('progress/{fileId}', { fileId }))
}

export async function externalLinks(fileId: number): Promise<ExternalLinks> {
	const { data } = await axios.post(ocs('external/{fileId}', { fileId }))
	return data.ocs.data
}

export async function rescan(): Promise<Record<string, unknown>> {
	const { data } = await axios.post(ocs('rescan'))
	return data.ocs.data
}

/** Ask the server how this file should be played, and set it up. */
export async function openPlayback(fileId: number, payload: {
	client: string
	bandwidth: number
	profile?: string
	start?: number
	audioIndex?: number
}): Promise<OpenResult> {
	const { data } = await axios.post(ocs('play/{fileId}', { fileId }), payload)
	return data.ocs.data
}

export async function pingPlayback(token: string, payload: {
	position: number
	buffer: number
	stalls: number
	bandwidth: number
	droppedFrames?: number
	startupMs?: number
}): Promise<{
	state: string
	error: string | null
	action: string
	profile: string
	ahead: number
	reason?: string
	reload?: string
}> {
	const { data } = await axios.post(ocs('play/{token}/ping', { token }), payload)
	return data.ocs.data
}

export async function switchQuality(token: string, profile: string, position: number): Promise<{ profile: string, reload: string }> {
	const { data } = await axios.post(ocs('play/{token}/report', { token }), { profile, position })
	return data.ocs.data
}

export async function closePlayback(token: string, beacon = false): Promise<void> {
	if (beacon) {
		// The page is going. A beacon is the only request that outlives it, and
		// it is what stops an encoder being left running by a closed tab. It can
		// carry no headers, so it goes to a plain route rather than the OCS one.
		const url = generateUrl('/apps/videogallery/close/{token}', { token })
		if (navigator.sendBeacon?.(url, new Blob([], { type: 'text/plain' }))) {
			return
		}
	}
	await axios.delete(ocs('play/{token}', { token }))
}

export const posterUrl = (fileId: number): string =>
	generateUrl('/apps/videogallery/preview/{fileId}/poster', { fileId })

export const loopUrl = (fileId: number): string =>
	generateUrl('/apps/videogallery/preview/{fileId}/loop', { fileId })

export const spriteUrl = (fileId: number): string =>
	generateUrl('/apps/videogallery/preview/{fileId}/sprite', { fileId })

export const subtitleUrl = (fileId: number, index: number): string =>
	generateUrl('/apps/videogallery/subtitle/{fileId}/{index}.vtt', { fileId, index })
