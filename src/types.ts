// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

export interface VideoItem {
	fileId: number
	path: string
	name: string
	basename: string
	folder: string
	mimetype: string
	size: number
	mtime: number
	takenAt: number
	dateSource: string
	duration: number
	durationMs: number
	width: number
	height: number
	rotation: number
	fps: number
	bitrate: number
	container: string
	vcodec: string
	vprofile: string
	pixFmt: string
	bitDepth: number
	hdr: boolean
	acodec: string
	achannels: number
	audioTracks: AudioTrack[]
	subTracks: SubTrack[]
	chapters: Chapter[]
	status: string
	hasPoster: boolean
	hasLoop: boolean
	hasSprite: boolean
	progress?: WatchProgress | null
}

export interface AudioTrack {
	index: number
	codec: string
	channels: number
	layout: string
	language: string
	title: string
	default: number
	bitrate: number
}

export interface Chapter {
	start: number
	end: number
	title: string
}

export interface SubTrack {
	index: number
	codec: string
	language: string
	title: string
	default: number
	forced: number
	textual: boolean
}

export interface SubtitleOption {
	index: number
	language: string
	label: string
	default: boolean
	forced: boolean
	available: boolean
	codec: string
}

export interface WatchProgress {
	fileId: number
	position: number
	positionMs: number
	duration: number
	percent: number
	finished: boolean
	audioIndex: number
	subIndex: number
	updatedAt: number
}

export interface SeriesEntry {
	item: VideoItem
	position: number
	total: number
	autoplay: boolean
	delay: number
}

export interface SeriesContext {
	isSeries: boolean
	title: string
	position: number | null
	total: number
	autoplay: boolean
	delay: number
	previous: SeriesEntry | null
	next: SeriesEntry | null
}

export interface Rail {
	id: string
	title: string
	items: VideoItem[]
}

export interface QualityRung {
	id: string
	label: string
	height: number
	bitrate: number
}

export interface SpriteLayout {
	columns: number
	rows: number
	tiles: number
	width: number
	interval: number
}

/** What the browser told us it can decode, sent with every request to play. */
export interface ClientCapabilities {
	video: string[]
	audio: string[]
	containers: string[]
	hls: boolean
	mse: boolean
}

export interface PlaybackPlan {
	mode: 'direct' | 'remux' | 'transcode_audio' | 'transcode'
	profile: string
	target_kbps: number
	source_kbps: number
	bandwidth_kbps: number
	transcoding: boolean
	reasons: string[]
	compatible: {
		playable: boolean
		mode: string
		video: boolean
		audio: boolean
		container: boolean
	}
}

export interface PlaybackSession {
	token: string
	fileId: number
	mode: string
	profile: string
	encoder: string
	state: string
	segmentDuration: number
	segmentType: string
	totalSegments: number
	duration: number
	startSegment: number
	audioIndex: number
	createdAt: number
	error: string | null
}

export interface OpenResult {
	mode: string
	url: string
	plan: PlaybackPlan
	item: VideoItem
	session?: PlaybackSession
	message?: string
}

export interface ExternalLinks {
	direct: string
	playlist: string
	vlc: string
	android: string
	infuse: string
	vlcios: string
	expires: string
}

export interface AppConfig {
	ladder: QualityRung[]
	segmentDuration: number
	bandwidthProbe: { enabled: boolean, bytes: number, ttl: number }
	governor: { enabled: boolean, interval: number }
	previews: { enabled: boolean, sprites: boolean }
	externalPlayer: boolean
	transcoding: boolean
	playbackPossible: boolean
	hardware: boolean
	stats: Record<string, number>
	openFileId: number | null
}
