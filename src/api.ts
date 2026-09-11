// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import type {
	ExternalLinks,
	FolderSection,
	FolderView,
	TimelineYear,
	OpenResult,
	Rail,
	SpriteLayout,
	SeriesContext,
	SeriesEntry,
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
	years: TimelineYear[]
	total: number
}> {
	const { data } = await axios.get(ocs('timeline'), { params })
	return data.ocs.data
}

/** The library as folders, each opened at the part worth opening. */
export async function fetchEverything(params: Record<string, unknown> = {}): Promise<{
	sections: FolderSection[]
	total: number
}> {
	const { data } = await axios.get(ocs('everything'), { params })
	return data.ocs.data
}

/** One folder, in the order it should be watched. */
export async function fetchFolder(path: string): Promise<FolderView> {
	const { data } = await axios.get(ocs('folder'), { params: { path } })
	return data.ocs.data
}

export async function fetchItem(fileId: number): Promise<{
	item: VideoItem
	progress: WatchProgress | null
	subtitles: SubtitleOption[]
	sprite: SpriteLayout | null
	sourceKbps: number
	series: SeriesContext | null
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

/** Correct what the library says about a video. */
export async function saveMetadata(fileId: number, payload: {
	title?: string
	description?: string
	takenAt?: number
	rename?: boolean
	resetDate?: boolean
}): Promise<{ item: VideoItem }> {
	const { data } = await axios.put(ocs('items/{fileId}/metadata', { fileId }), payload)
	return data.ocs.data
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

// -- watching through a link -----------------------------------------------
//
// The same conversation as above, held by somebody who arrived with a token
// instead of an account. Kept beside its authenticated twin so the two cannot
// drift apart.

export interface PublicShare {
	token: string
	label: string
	name: string
	isFolder: boolean
	canDownload: boolean
	owner: string
	ownerDisplayName: string
	expires: number | null
	note: string
}

export async function publicContents(token: string, folder = '', sort = 'name_asc'): Promise<{
	share: PublicShare
	items: VideoItem[]
	folders: Array<{ name: string, count: number }>
	total: number
}> {
	const { data } = await axios.get(ocs('public/{token}', { token }), { params: { folder, sort } })
	return data.ocs.data
}

export async function publicItem(token: string, fileId: number): Promise<{
	item: VideoItem
	subtitles: SubtitleOption[]
	sprite: SpriteLayout | null
	canDownload: boolean
	next: SeriesEntry | null
}> {
	const { data } = await axios.get(ocs('public/{token}/items/{fileId}', { token, fileId }))
	return data.ocs.data
}

export async function publicPlay(token: string, fileId: number, payload: {
	client: string
	bandwidth: number
	profile?: string
	start?: number
	audioIndex?: number
}): Promise<OpenResult> {
	const { data } = await axios.post(ocs('public/{token}/play/{fileId}', { token, fileId }), payload)
	return data.ocs.data
}

export async function publicPing(token: string, session: string, payload: {
	position: number
	buffer: number
	stalls: number
	bandwidth: number
}): Promise<{ state: string, error: string | null, action: string, profile: string, reason?: string, reload?: string }> {
	const { data } = await axios.post(ocs('public/{token}/play/{session}/ping', { token, session }), payload)
	return data.ocs.data
}

export async function publicSwitchQuality(token: string, session: string, profile: string, position: number): Promise<{ profile: string, reload: string }> {
	const { data } = await axios.post(ocs('public/{token}/play/{session}/report', { token, session }), { profile, position })
	return data.ocs.data
}

export async function publicClose(token: string, session: string, beacon = false): Promise<void> {
	if (beacon && navigator.sendBeacon) {
		// Nothing to end on the server that a closing tab can reach without
		// headers, so the sweep picks this one up if the request does not land.
		navigator.sendBeacon(ocs('public/{token}/play/{session}', { token, session }), new Blob([], { type: 'text/plain' }))
		return
	}
	await axios.delete(ocs('public/{token}/play/{session}', { token, session }))
}

export const publicPosterUrl = (token: string, fileId: number): string =>
	generateUrl('/apps/videogallery/s/{token}/preview/{fileId}/poster', { token, fileId })

export const publicLoopUrl = (token: string, fileId: number): string =>
	generateUrl('/apps/videogallery/s/{token}/preview/{fileId}/loop', { token, fileId })

export const publicSpriteUrl = (token: string, fileId: number): string =>
	generateUrl('/apps/videogallery/s/{token}/preview/{fileId}/sprite', { token, fileId })

export const publicSubtitleUrl = (token: string, fileId: number, index: number): string =>
	generateUrl('/apps/videogallery/s/{token}/subtitle/{fileId}/{index}.vtt', { token, fileId, index })

// -- sharing ---------------------------------------------------------------
//
// Shares are created and changed through Nextcloud's own sharing API, which
// already enforces every rule an administrator may have set. Only the parts it
// cannot know about — what is already shared from the gallery's point of view,
// and short addresses — come from this app.

const coreShares = (path = '') => generateOcsUrl('apps/files_sharing/api/v1/shares' + path)

export interface GalleryShare {
	id: string
	type: number
	with: string | null
	withDisplayName: string | null
	label: string
	token: string | null
	url: string | null
	filesUrl: string | null
	hasPassword: boolean
	canDownload: boolean
	expires: number | null
	note: string
	permissions: number
	shortUrl?: string | null
}

export interface ShareState {
	enabled: boolean
	linksAllowed: boolean
	passwordRequired: boolean
	shortLinks: boolean
	isFolder: boolean
	path: string
	name: string
	shares: GalleryShare[]
}

export async function fetchShares(fileId: number): Promise<ShareState> {
	const { data } = await axios.get(ocs('shares/{fileId}', { fileId }))
	return data.ocs.data
}

export async function shareableFolders(): Promise<{ folders: Array<{ path: string, name: string, fileId: number, count: number }> }> {
	const { data } = await axios.get(ocs('shareable-folders'))
	return data.ocs.data
}

/** Nextcloud's own share creation, so every policy it enforces still applies. */
export async function createShare(payload: {
	path: string
	shareType: number
	shareWith?: string
	password?: string
	expireDate?: string
	label?: string
	canDownload?: boolean
}): Promise<Record<string, unknown>> {
	const body: Record<string, unknown> = {
		path: payload.path,
		shareType: payload.shareType,
		permissions: 1,
	}
	if (payload.shareWith) {
		body.shareWith = payload.shareWith
	}
	if (payload.password) {
		body.password = payload.password
	}
	if (payload.expireDate) {
		body.expireDate = payload.expireDate
	}
	if (payload.label) {
		body.label = payload.label
	}
	if (payload.canDownload === false) {
		body.attributes = JSON.stringify([{ scope: 'permissions', key: 'download', value: false }])
	}
	const { data } = await axios.post(coreShares(), body)
	return data.ocs.data
}

export async function updateShare(id: string, payload: {
	password?: string | null
	expireDate?: string | null
	canDownload?: boolean
	note?: string
}): Promise<Record<string, unknown>> {
	const body: Record<string, unknown> = {}
	if (payload.password !== undefined) {
		body.password = payload.password ?? ''
	}
	if (payload.expireDate !== undefined) {
		body.expireDate = payload.expireDate ?? ''
	}
	if (payload.note !== undefined) {
		body.note = payload.note
	}
	if (payload.canDownload !== undefined) {
		body.attributes = JSON.stringify([{ scope: 'permissions', key: 'download', value: payload.canDownload }])
		body.hideDownload = payload.canDownload ? 'false' : 'true'
	}
	const { data } = await axios.put(coreShares('/' + id), body)
	return data.ocs.data
}

export async function deleteShare(id: string): Promise<void> {
	await axios.delete(coreShares('/' + id))
}

/** People and groups to share with, from Nextcloud's own search. */
export async function searchSharees(search: string): Promise<Array<{ id: string, label: string, type: number }>> {
	const { data } = await axios.get(generateOcsUrl('apps/files_sharing/api/v1/sharees'), {
		params: { search, itemType: 'file', perPage: 15, lookup: false, shareType: [0, 1] },
	})
	const payload = data.ocs.data
	const out: Array<{ id: string, label: string, type: number }> = []
	for (const group of ['exact', 'users', 'groups'] as const) {
		const section = payload[group]
		if (!section) {
			continue
		}
		const lists = group === 'exact' ? [section.users ?? [], section.groups ?? []] : [section]
		for (const list of lists) {
			for (const entry of list) {
				const type = entry.value?.shareType ?? 0
				const id = entry.value?.shareWith
				if (!id || out.some((existing) => existing.id === id && existing.type === type)) {
					continue
				}
				out.push({ id, label: entry.label ?? id, type })
			}
		}
	}
	return out
}

export async function shortLink(shareId: string): Promise<{ short: string | null }> {
	const { data } = await axios.post(ocs('shares/{shareId}/short-link', { shareId }))
	return data.ocs.data
}

export const posterUrl = (fileId: number): string =>
	generateUrl('/apps/videogallery/preview/{fileId}/poster', { fileId })

export const loopUrl = (fileId: number): string =>
	generateUrl('/apps/videogallery/preview/{fileId}/loop', { fileId })

export const spriteUrl = (fileId: number): string =>
	generateUrl('/apps/videogallery/preview/{fileId}/sprite', { fileId })

export const subtitleUrl = (fileId: number, index: number): string =>
	generateUrl('/apps/videogallery/subtitle/{fileId}/{index}.vtt', { fileId, index })
