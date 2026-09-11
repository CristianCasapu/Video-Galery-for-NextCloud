<!--
  - SPDX-FileCopyrightText: 2026 Cristian Casapu
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!--
	  - Moved out to the page body. Left where it was written, the player sits
	  - inside the content pane and under Nextcloud's own header — which is not
	  - merely untidy: the header's logo then sits on top of the close button, so
	  - closing the player navigated away from the app instead.
	  -->
	<Teleport to="body">
	<div class="player" :class="{ 'player--idle': !controlsVisible }"
		tabindex="-1"
		@mousemove="wakeControls"
		@touchstart.passive="wakeControls"
		@keydown="onKey">
		<video ref="video"
			class="player__video"
			:style="{ filter: dimming < 1 ? `brightness(${dimming})` : undefined }"
			playsinline
			:poster="poster"
			@click="togglePlay"
			@dblclick="toggleFullscreen"
			@play="playing = true"
			@playing="onPlaying"
			@pause="playing = false"
			@timeupdate="onTimeUpdate"
			@progress="onProgress"
			@waiting="onWaiting"
			@ended="onEnded"
			@loadedmetadata="onLoadedMetadata"
			@volumechange="onVolumeChange" />

		<!-- Touch gestures live on their own layer so they never fight with the
		     controls: a swipe up the left of the picture dims it, up the right
		     turns it up, and a swipe across scrubs. -->
		<div class="player__touch"
			@touchstart.passive="onTouchStart"
			@touchmove.prevent="onTouchMove"
			@touchend="onTouchEnd" />

		<transition name="notice">
			<div v-if="gesture" class="player__gesture">
				<span class="player__gesture-label">{{ gesture.label }}</span>
				<div class="player__gesture-bar">
					<div class="player__gesture-fill" :style="{ width: gesture.percent + '%' }" />
				</div>
			</div>
		</transition>

		<div v-if="loading" class="player__spinner">
			<div class="player__spinner-ring" />
			<p>{{ loadingMessage }}</p>
		</div>

		<div v-if="error" class="player__error">
			<h3>{{ t('videogallery', 'This will not play') }}</h3>
			<p>{{ error }}</p>
			<div class="player__error-actions">
				<button class="player__button" @click="start()">{{ t('videogallery', 'Try again') }}</button>
				<button v-if="externalEnabled" class="player__button" @click="openExternal">
					{{ t('videogallery', 'Open in another player') }}
				</button>
				<button class="player__button" @click="$emit('close')">{{ t('videogallery', 'Close') }}</button>
			</div>
		</div>

		<transition name="notice">
			<p v-if="notice" class="player__notice">{{ notice }}</p>
		</transition>

		<div class="player__top">
			<button class="player__back" :aria-label="t('videogallery', 'Back')" @click="leave">
				<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
					<path fill="currentColor" d="M20 11H7.8l5.6-5.6L12 4l-8 8 8 8 1.4-1.4L7.8 13H20z" />
				</svg>
				<span class="player__back-label">{{ t('videogallery', 'Back') }}</span>
			</button>
			<div class="player__heading">
				<span class="player__name">{{ item.basename }}</span>
				<span class="player__mode">{{ heading }}</span>
			</div>
			<a v-if="!shared"
				class="player__icon player__icon--right"
				:href="filesUrl"
				:title="t('videogallery', 'Show where this file is')"
				:aria-label="t('videogallery', 'Show where this file is')">
				<svg viewBox="0 0 24 24" width="21" height="21" aria-hidden="true">
					<path fill="currentColor" d="M10 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-8z" />
				</svg>
			</a>
		</div>

		<div class="player__controls">
			<div class="player__scrub"
				ref="scrub"
				@mousemove="onScrubHover"
				@mouseleave="scrubPreview = null"
				@click="onScrubClick">
				<div class="player__scrub-track">
					<div class="player__scrub-buffer" :style="{ width: bufferedPercent + '%' }" />
					<div class="player__scrub-played" :style="{ width: playedPercent + '%' }" />
					<!-- Where the film's own chapters begin. -->
					<span v-for="mark in chapterMarks"
						:key="mark.start"
						class="player__chapter-mark"
						:style="{ left: mark.percent + '%' }"
						:title="mark.title" />
					<div class="player__scrub-knob" :style="{ left: playedPercent + '%' }" />
				</div>

				<!-- The thumbnail strip was made once, so moving along the bar costs
				     nothing: it is one image with the frame shifted into view. -->
				<div v-if="scrubPreview" class="player__thumb" :style="scrubPreview.style">
					<span class="player__thumb-time">{{ clock(scrubPreview.time) }}</span>
				</div>
			</div>

			<div class="player__row">
				<button class="player__icon" :aria-label="playing ? t('videogallery', 'Pause') : t('videogallery', 'Play')" @click="togglePlay">
					<svg v-if="playing" viewBox="0 0 24 24" width="26" height="26" aria-hidden="true">
						<path fill="currentColor" d="M6 5h4v14H6zm8 0h4v14h-4z" />
					</svg>
					<svg v-else viewBox="0 0 24 24" width="26" height="26" aria-hidden="true">
						<path fill="currentColor" d="M8 5v14l11-7z" />
					</svg>
				</button>

				<button class="player__icon" :aria-label="t('videogallery', 'Back ten seconds')" @click="nudge(-10)">
					<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
						<path fill="currentColor" d="M12 5V1L7 6l5 5V7a6 6 0 1 1-6 6H4a8 8 0 1 0 8-8z" />
					</svg>
				</button>
				<button v-if="series?.next"
					class="player__icon"
					:aria-label="t('videogallery', 'Next')"
					@click="playNext">
					<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
						<path fill="currentColor" d="M6 18l8.5-6L6 6zM16 6h2v12h-2z" />
					</svg>
				</button>

				<button class="player__icon" :aria-label="t('videogallery', 'Forward thirty seconds')" @click="nudge(30)">
					<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
						<path fill="currentColor" d="M12 5V1l5 5-5 5V7a6 6 0 1 0 6 6h2a8 8 0 1 1-8-8z" />
					</svg>
				</button>

				<div class="player__volume">
					<button class="player__icon" :aria-label="t('videogallery', 'Mute')" @click="toggleMute">
						<svg v-if="muted || volume === 0" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
							<path fill="currentColor" d="M3 9v6h4l5 5V4L7 9zm13.6 3 2.4-2.4-1.2-1.2-2.4 2.4-2.4-2.4-1.2 1.2 2.4 2.4-2.4 2.4 1.2 1.2 2.4-2.4 2.4 2.4 1.2-1.2z" />
						</svg>
						<svg v-else viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
							<path fill="currentColor" d="M3 9v6h4l5 5V4L7 9zm13.5 3a4.5 4.5 0 0 0-2.5-4v8a4.5 4.5 0 0 0 2.5-4zM14 3.2v2.1a6.8 6.8 0 0 1 0 13.4v2.1a8.9 8.9 0 0 0 0-17.6z" />
						</svg>
					</button>
					<input class="player__volume-slider"
						type="range"
						min="0"
						max="1"
						step="0.02"
						:value="muted ? 0 : volume"
						:aria-label="t('videogallery', 'Volume')"
						@input="setVolume(($event.target as HTMLInputElement).valueAsNumber)">
				</div>

				<span class="player__time">{{ clock(currentTime) }} / {{ clock(duration) }}</span>

				<div class="player__spacer" />

				<div class="player__menus">
					<PlayerMenu v-if="chapterOptions.length"
						:label="t('videogallery', 'Chapters')"
						:options="chapterOptions"
						:selected="currentChapterId"
						@select="jumpToChapter">
						<svg viewBox="0 0 24 24" width="21" height="21" aria-hidden="true">
							<path fill="currentColor" d="M4 6h16v2H4zm0 5h16v2H4zm0 5h10v2H4z" />
						</svg>
					</PlayerMenu>

					<PlayerMenu v-if="audioOptions.length > 1"
						:label="t('videogallery', 'Audio')"
						:options="audioOptions"
						:selected="String(audioIndex)"
						@select="selectAudio">
						<svg viewBox="0 0 24 24" width="21" height="21" aria-hidden="true">
							<path fill="currentColor" d="M12 3v10.6A4 4 0 1 0 14 17V7h4V3z" />
						</svg>
					</PlayerMenu>

					<PlayerMenu v-if="subtitleOptions.length"
						:label="t('videogallery', 'Subtitles')"
						:options="subtitleOptions"
						:selected="String(subtitleIndex)"
						@select="selectSubtitle">
						<svg viewBox="0 0 24 24" width="21" height="21" aria-hidden="true">
							<path fill="currentColor" d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zM6 14v-2h5v2zm7 0v-2h5v2zM6 11V9h8v2zm10 0V9h2v2z" />
						</svg>
					</PlayerMenu>

					<PlayerMenu :label="t('videogallery', 'Quality')"
						:options="qualityOptions"
						:selected="selectedQuality"
						:footnote="qualityFootnote"
						@select="selectQuality">
						<svg viewBox="0 0 24 24" width="21" height="21" aria-hidden="true">
							<path fill="currentColor" d="M3 17h4v4H3zm7-6h4v10h-4zm7-6h4v16h-4z" />
						</svg>
					</PlayerMenu>

					<button v-if="externalEnabled"
						class="player__icon"
						:aria-label="t('videogallery', 'Open in another player')"
						@click="openExternal">
						<svg viewBox="0 0 24 24" width="21" height="21" aria-hidden="true">
							<path fill="currentColor" d="M14 3v2h3.6l-9.8 9.8 1.4 1.4L19 6.4V10h2V3zM5 5h5V3H3v18h18v-7h-2v5H5z" />
						</svg>
					</button>

					<button v-if="pipSupported" class="player__icon" :aria-label="t('videogallery', 'Picture in picture')" @click="togglePip">
						<svg viewBox="0 0 24 24" width="21" height="21" aria-hidden="true">
							<path fill="currentColor" d="M19 11h-8v6h8zm2-8H3a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h18a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2zm0 16H3V5h18z" />
						</svg>
					</button>

					<button class="player__icon" :aria-label="t('videogallery', 'Full screen')" @click="toggleFullscreen">
						<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
							<path fill="currentColor" d="M7 14H5v5h5v-2H7zm-2-4h2V7h3V5H5zm12 7h-3v2h5v-5h-2zM14 5v2h3v3h2V5z" />
						</svg>
					</button>
				</div>
			</div>
		</div>

		<!-- What follows, when this is one part of something. -->
		<transition name="notice">
			<div v-if="upNext" class="player__next">
				<img v-if="upNext.item.hasPoster" class="player__next-art" :src="posterFor(upNext.item.fileId)" alt="">
				<div class="player__next-body">
					<p class="player__next-label">
						{{ countdown > 0
							? t('videogallery', 'Next in {seconds}s', { seconds: countdown })
							: t('videogallery', 'Next') }}
					</p>
					<p class="player__next-title">{{ upNext.item.basename }}</p>
					<p class="player__next-meta">{{ t('videogallery', 'Part {n} of {total}', { n: upNext.position, total: upNext.total }) }}</p>
					<div class="player__next-actions">
						<button class="player__next-play" @click="playNext">{{ t('videogallery', 'Play now') }}</button>
						<button class="player__next-cancel" @click="cancelNext">{{ t('videogallery', 'Not now') }}</button>
					</div>
				</div>
			</div>
		</transition>

		<ExternalPlayerDialog v-if="externalOpen"
			:item="item"
			@close="externalOpen = false" />
	</div>
	</Teleport>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import type HlsType from 'hls.js'
import ExternalPlayerDialog from './ExternalPlayerDialog.vue'
import PlayerMenu from './PlayerMenu.vue'
import { detectCapabilities } from '../capabilities'
import { currentBandwidth, formatBandwidth } from '../bandwidth'
import {
	closePlayback,
	fetchItem,
	openPlayback,
	pingPlayback,
	posterUrl,
	publicClose,
	publicItem,
	publicPing,
	publicPlay,
	publicPosterUrl,
	publicSpriteUrl,
	publicSubtitleUrl,
	publicSwitchQuality,
	saveProgress,
	spriteUrl,
	subtitleUrl,
	switchQuality,
} from '../api'
import type {
	AppConfig,
	PlaybackPlan,
	SeriesContext,
	SeriesEntry,
	SpriteLayout,
	SubtitleOption,
	VideoItem,
} from '../types'

const props = defineProps<{
	item: VideoItem
	config: AppConfig
	/** Set when the viewer arrived through a share link rather than an account. */
	token?: string
}>()

/**
 * The same player serves both, and the only difference is which door the
 * requests go through. Keeping that difference in one place here means every
 * feature below — quality, subtitles, chapters, gestures — works the same for
 * a visitor with a link as for the person who owns the file.
 */
const shared = computed(() => (props.token ?? '') !== '')
const filesUrl = computed(() => generateUrl('/f/{fileId}', { fileId: props.item.fileId }))
const emit = defineEmits<{
	close: []
	progress: [fileId: number, position: number]
	play: [item: VideoItem]
}>()

const video = ref<HTMLVideoElement | null>(null)
const scrub = ref<HTMLElement | null>(null)
const hls = shallowRef<HlsType | null>(null)

const loading = ref(true)
const loadingMessage = ref(t('videogallery', 'Checking what this browser can play…'))
const error = ref('')
const notice = ref('')
const playing = ref(false)
const currentTime = ref(0)
const duration = ref(props.item.duration || 0)
const bufferedEnd = ref(0)
const volume = ref(1)
const muted = ref(false)
const controlsVisible = ref(true)
const externalOpen = ref(false)

const plan = ref<PlaybackPlan | null>(null)
const sessionToken = ref('')
const selectedQuality = ref('auto')
const audioIndex = ref(-1)
const subtitleIndex = ref(-1)
const subtitles = ref<SubtitleOption[]>([])
const sprite = ref<SpriteLayout | null>(null)
const measuredKbps = ref(0)
const scrubPreview = ref<{ time: number, style: Record<string, string> } | null>(null)
const series = ref<SeriesContext | null>(null)
const upNext = ref<SeriesEntry | null>(null)
const countdown = ref(0)
let countdownTimer: number | undefined
// Browsers cannot touch a device's backlight, so "brightness" dims the picture
// itself. On a phone in a dark room that is the part that matters anyway.
const dimming = ref(1)
const gesture = ref<{ label: string, percent: number } | null>(null)

let stallsSincePing = 0
let controlsTimer: number | undefined
let pingTimer: number | undefined
let progressTimer: number | undefined
let noticeTimer: number | undefined
let restoreTo = 0
let pushedHistory = false
let startedAt = 0
let startupMs = 0
let touch: { x: number, y: number, mode: 'none' | 'seek' | 'volume' | 'dim', startValue: number, startTime: number } | null = null
let gestureTimer: number | undefined

const poster = computed(() => posterFor(props.item.fileId))
const posterFor = (fileId: number) => (shared.value ? publicPosterUrl(props.token!, fileId) : posterUrl(fileId))
const spriteFor = (fileId: number) => (shared.value ? publicSpriteUrl(props.token!, fileId) : spriteUrl(fileId))
const subtitleFor = (fileId: number, index: number) => (shared.value ? publicSubtitleUrl(props.token!, fileId, index) : subtitleUrl(fileId, index))

/** The line under the title: where we are, and how it is being sent. */
const heading = computed(() => {
	const parts: string[] = []
	if (series.value?.isSeries && series.value.position) {
		parts.push(t('videogallery', 'Part {n} of {total}', { n: series.value.position, total: series.value.total }))
	}
	if (currentChapter.value) {
		parts.push(currentChapter.value)
	}
	parts.push(modeLabel.value)
	return parts.join(' · ')
})

const externalEnabled = computed(() => props.config.externalPlayer)
const pipSupported = computed(() => typeof document !== 'undefined' && 'pictureInPictureEnabled' in document && document.pictureInPictureEnabled)

const playedPercent = computed(() => duration.value > 0 ? Math.min(100, (currentTime.value / duration.value) * 100) : 0)
const bufferedPercent = computed(() => duration.value > 0 ? Math.min(100, (bufferedEnd.value / duration.value) * 100) : 0)

const modeLabel = computed(() => {
	if (!plan.value) {
		return ''
	}
	const speed = measuredKbps.value ? ` · ${formatBandwidth(measuredKbps.value)}` : ''
	switch (plan.value.mode) {
	case 'direct':
		return t('videogallery', 'Original file, sent as it is') + speed
	case 'remux':
		return t('videogallery', 'Repackaged, picture untouched') + speed
	case 'transcode_audio':
		return t('videogallery', 'Sound converted, picture untouched') + speed
	default:
		return t('videogallery', 'Converted to {quality}', { quality: currentQualityLabel() }) + speed
	}
})

const chapters = computed(() => (props.item.chapters ?? []).filter((chapter) => chapter.end > chapter.start))

const chapterMarks = computed(() => {
	if (duration.value <= 0) {
		return []
	}
	// The first chapter starts at the beginning, and a mark there says nothing.
	return chapters.value
		.filter((chapter) => chapter.start > 1)
		.map((chapter) => ({
			start: chapter.start,
			percent: Math.min(100, (chapter.start / duration.value) * 100),
			title: chapter.title || clock(chapter.start),
		}))
})

const chapterOptions = computed(() => chapters.value.map((chapter, position) => ({
	id: String(chapter.start),
	label: chapter.title || t('videogallery', 'Chapter {n}', { n: position + 1 }),
	detail: clock(chapter.start),
})))

const currentChapterId = computed(() => {
	let found = ''
	for (const chapter of chapters.value) {
		if (currentTime.value >= chapter.start - 0.01) {
			found = String(chapter.start)
		}
	}
	return found
})

const currentChapter = computed(() => {
	const match = chapters.value.find((chapter) => String(chapter.start) === currentChapterId.value)
	return match?.title ?? ''
})

function jumpToChapter(id: string): void {
	const element = video.value
	if (element) {
		element.currentTime = Number(id)
	}
}

const audioOptions = computed(() => props.item.audioTracks.map((track, position) => ({
	id: String(track.index),
	label: track.title || track.language?.toUpperCase() || t('videogallery', 'Track {n}', { n: position + 1 }),
	detail: [track.codec?.toUpperCase(), track.channels ? `${track.channels}ch` : ''].filter(Boolean).join(' · '),
})))

const subtitleOptions = computed(() => {
	const options = [{ id: '-1', label: t('videogallery', 'Off'), detail: '' }]
	for (const track of subtitles.value) {
		options.push({
			id: String(track.index),
			label: track.label,
			detail: track.available ? '' : t('videogallery', 'Picture based, cannot be shown'),
		})
	}
	return options
})

const qualityOptions = computed(() => {
	const options = [{ id: 'auto', label: t('videogallery', 'Automatic'), detail: t('videogallery', 'Follows the connection') }]
	for (const rung of props.config.ladder) {
		if (rung.height > 0 && props.item.height > 0 && rung.height > Math.min(props.item.width, props.item.height)) {
			continue
		}
		options.push({
			id: rung.id,
			label: rung.label,
			detail: rung.bitrate ? `${(rung.bitrate / 1000).toFixed(1)} Mbit/s` : t('videogallery', 'As recorded'),
		})
	}
	return options
})

const qualityFootnote = computed(() => {
	if (!plan.value) {
		return ''
	}
	if (selectedQuality.value === 'auto') {
		return t('videogallery', 'Now at {quality}', { quality: currentQualityLabel() })
	}
	return ''
})

function currentQualityLabel(): string {
	const id = plan.value?.profile ?? 'original'
	return props.config.ladder.find((rung) => rung.id === id)?.label ?? id
}

function clock(seconds: number): string {
	if (!Number.isFinite(seconds) || seconds < 0) {
		return '0:00'
	}
	const hours = Math.floor(seconds / 3600)
	const minutes = Math.floor((seconds % 3600) / 60)
	const rest = Math.floor(seconds % 60)
	return hours > 0
		? `${hours}:${String(minutes).padStart(2, '0')}:${String(rest).padStart(2, '0')}`
		: `${minutes}:${String(rest).padStart(2, '0')}`
}

function say(message: string): void {
	notice.value = message
	window.clearTimeout(noticeTimer)
	noticeTimer = window.setTimeout(() => { notice.value = '' }, 4200)
}

// -- starting up -----------------------------------------------------------

/**
 * Everything that has to happen before the first frame: find out what this
 * browser can decode, find out what the link will carry, and let the server
 * decide between those two facts what to send.
 */
async function start(resumeAt?: number): Promise<void> {
	loading.value = true
	error.value = ''
	startedAt = performance.now()
	startupMs = 0
	try {
		// Both at once. Neither needs the other, and doing them in turn puts the
		// slower of the two between pressing play and the picture appearing.
		loadingMessage.value = t('videogallery', 'Getting ready…')
		const [details, measured] = await Promise.all([
			shared.value ? publicItem(props.token!, props.item.fileId) : fetchItem(props.item.fileId),
			props.config.bandwidthProbe.enabled
				? currentBandwidth(props.config.bandwidthProbe.bytes, props.config.bandwidthProbe.ttl)
				: Promise.resolve(0),
		])
		measuredKbps.value = measured
		subtitles.value = details.subtitles
		// A link to a folder still knows what comes next inside that folder.
		series.value = 'series' in details
			? (details.series ?? null)
			: (details.next ? { isSeries: true, title: '', position: null, total: details.next.total, autoplay: details.next.autoplay, delay: details.next.delay, previous: null, next: details.next } : null)
		sprite.value = details.sprite
		duration.value = details.item.duration || duration.value
		const progress = 'progress' in details ? details.progress : null
		const resume = resumeAt ?? (progress && !progress.finished ? progress.position : 0)
		if (progress && progress.audioIndex >= 0 && audioIndex.value < 0) {
			audioIndex.value = progress.audioIndex
		}

		loadingMessage.value = t('videogallery', 'Preparing the stream…')
		const request = {
			client: JSON.stringify(detectCapabilities()),
			bandwidth: measuredKbps.value,
			profile: selectedQuality.value,
			start: resume,
			audioIndex: audioIndex.value,
		}
		const result = shared.value
			? await publicPlay(props.token!, props.item.fileId, request)
			: await openPlayback(props.item.fileId, request)
		plan.value = result.plan
		sessionToken.value = result.session?.token ?? ''
		restoreTo = resume

		await attach(result.url, result.mode === 'direct')
		const firstReason = plan.value?.reasons?.[0]
		if (plan.value?.transcoding && firstReason) {
			say(firstReason)
		}
		startTimers()
	} catch (e: unknown) {
		const response = (e as { response?: { data?: { ocs?: { data?: { message?: string } } } } }).response
		error.value = response?.data?.ocs?.data?.message
			?? t('videogallery', 'The server could not prepare this file for playback.')
		loading.value = false
	}
}

/** Point the video element at whatever the server decided to send. */
async function attach(url: string, direct: boolean): Promise<void> {
	const element = video.value
	if (!element) {
		return
	}
	teardownHls()

	if (direct) {
		element.src = url
		await afterAttach(element)
		return
	}

	// Safari and the iPhone play these lists themselves, and do it better than
	// anything running in the page can — and doing it that way avoids loading
	// the library at all.
	if (element.canPlayType('application/vnd.apple.mpegurl') === 'probably') {
		element.src = url
		await afterAttach(element)
		return
	}

	// Fetched only when a converted stream is actually played, which keeps it
	// out of the way of simply browsing the library.
	const { default: Hls } = await import('hls.js')
	if (!Hls.isSupported()) {
		if (element.canPlayType('application/vnd.apple.mpegurl')) {
			element.src = url
			await afterAttach(element)
			return
		}
		error.value = t('videogallery', 'This browser cannot play converted streams.')
		loading.value = false
		return
	}

	const instance = new Hls({
		// The server produces segments as they are asked for, so a request may
		// wait on an encoder rather than a disk.
		manifestLoadingTimeOut: 30000,
		fragLoadingTimeOut: 60000,
		fragLoadingMaxRetry: 3,
		startPosition: restoreTo > 0 ? restoreTo : -1,
		// Buffering far ahead would make the encoder run far ahead too, which is
		// wasted work the moment somebody seeks.
		maxBufferLength: 40,
		maxMaxBufferLength: 90,
		backBufferLength: 30,
		enableWorker: true,
		lowLatencyMode: false,
	})
	hls.value = instance
	instance.on(Hls.Events.ERROR, (_event, data) => {
		if (!data.fatal) {
			return
		}
		if (data.type === Hls.ErrorTypes.NETWORK_ERROR) {
			instance.startLoad()
			return
		}
		if (data.type === Hls.ErrorTypes.MEDIA_ERROR) {
			instance.recoverMediaError()
			return
		}
		error.value = data.reason || t('videogallery', 'The stream stopped unexpectedly.')
		loading.value = false
	})
	instance.loadSource(url)
	instance.attachMedia(element)
	await afterAttach(element)
}

async function afterAttach(element: HTMLVideoElement): Promise<void> {
	loading.value = false
	applySubtitleTracks()
	if (restoreTo > 0 && element.src) {
		// A directly played file seeks in the file itself; a converted one has
		// already been started at the right point.
		element.currentTime = restoreTo
	}
	try {
		await element.play()
	} catch {
		// Autoplay was refused. The controls are right there.
	}
}

function teardownHls(): void {
	if (hls.value) {
		hls.value.destroy()
		hls.value = null
	}
}

// -- keeping it playing well ----------------------------------------------

function startTimers(): void {
	stopTimers()
	if (sessionToken.value && props.config.governor.enabled) {
		pingTimer = window.setInterval(report, Math.max(3, props.config.governor.interval) * 1000)
	}
	progressTimer = window.setInterval(pushProgress, 10000)
}

function stopTimers(): void {
	window.clearInterval(pingTimer)
	window.clearInterval(progressTimer)
}

/**
 * Tell the server how it is going, and do what it says.
 *
 * This is the loop that keeps quality honest while the film runs: how much is
 * buffered, how often it ran dry, and how fast the segments are actually
 * arriving. The server decides whether to hold, drop a rung, or take one back.
 */
async function report(): Promise<void> {
	const element = video.value
	if (!element || !sessionToken.value) {
		return
	}
	const stalls = stallsSincePing
	stallsSincePing = 0
	const buffered = Math.max(0, bufferedEnd.value - element.currentTime)
	// hls.js keeps a running estimate from the segments it has fetched, which is
	// a truer figure than a one-off measurement taken before playback started.
	const estimate = hls.value?.bandwidthEstimate ? hls.value.bandwidthEstimate / 1000 : measuredKbps.value

	try {
		const report = {
			position: element.currentTime,
			buffer: buffered,
			stalls,
			bandwidth: estimate,
		}
		const verdict = shared.value
			? await publicPing(props.token!, sessionToken.value, report)
			: await pingPlayback(sessionToken.value, {
				...report,
				droppedFrames: element.getVideoPlaybackQuality?.().droppedVideoFrames ?? 0,
				// Sent once: the server keeps it against this kind of file and
				// browser, so it knows how quick this combination really is.
				startupMs,
			})
		startupMs = 0
		if (verdict.error) {
			error.value = verdict.error
			return
		}
		if (verdict.reload) {
			if (verdict.reason) {
				say(verdict.reason)
			}
			await reload(verdict.reload)
		}
	} catch {
		// A missed check-in is not worth interrupting playback over.
	}
}

/** Swap to a stream at a different quality, carrying on from the same moment. */
async function reload(url: string): Promise<void> {
	const element = video.value
	if (!element) {
		return
	}
	const wasPlaying = !element.paused
	restoreTo = element.currentTime
	// A fresh address, because the playlist behind the old one now describes
	// different segments entirely.
	await attach(url + (url.includes('?') ? '&' : '?') + 'v=' + Date.now(), false)
	if (restoreTo > 0) {
		element.currentTime = restoreTo
	}
	if (wasPlaying) {
		element.play().catch(() => undefined)
	}
}

async function pushProgress(): Promise<void> {
	const element = video.value
	// Nothing is remembered about a visitor with a link: there is no account to
	// remember it against, and no reason to keep a record of who watched what.
	if (!element || shared.value || element.currentTime < 5) {
		return
	}
	try {
		await saveProgress(props.item.fileId, {
			position: Math.floor(element.currentTime),
			duration: Math.floor(duration.value),
			audioIndex: audioIndex.value,
			subIndex: subtitleIndex.value,
		})
		emit('progress', props.item.fileId, Math.floor(element.currentTime))
	} catch {
		// Losing one position update is not worth telling anyone about.
	}
}

// -- viewer controls -------------------------------------------------------

function togglePlay(): void {
	const element = video.value
	if (!element) {
		return
	}
	element.paused ? element.play().catch(() => undefined) : element.pause()
}

function nudge(seconds: number): void {
	const element = video.value
	if (element) {
		element.currentTime = Math.max(0, Math.min(duration.value, element.currentTime + seconds))
	}
}

function setVolume(value: number): void {
	const element = video.value
	if (!element) {
		return
	}
	element.volume = value
	element.muted = value === 0
}

function toggleMute(): void {
	const element = video.value
	if (element) {
		element.muted = !element.muted
	}
}

function onVolumeChange(): void {
	const element = video.value
	if (!element) {
		return
	}
	volume.value = element.volume
	muted.value = element.muted
	try {
		window.localStorage.setItem('videogallery-volume', JSON.stringify({ volume: element.volume, muted: element.muted }))
	} catch {
		// storage refused; the setting simply will not be remembered
	}
}

async function selectQuality(id: string): Promise<void> {
	selectedQuality.value = id
	const element = video.value
	if (!element) {
		return
	}
	if (!sessionToken.value) {
		// Playing directly, so a quality choice means starting a converted stream.
		await start(element.currentTime)
		return
	}
	if (id === 'auto') {
		say(t('videogallery', 'Quality will follow the connection again.'))
		return
	}
	try {
		const result = shared.value
			? await publicSwitchQuality(props.token!, sessionToken.value, id, element.currentTime)
			: await switchQuality(sessionToken.value, id, element.currentTime)
		await reload(result.reload)
	} catch {
		say(t('videogallery', 'That quality could not be started.'))
	}
}

async function selectAudio(id: string): Promise<void> {
	audioIndex.value = Number(id)
	// The soundtrack is chosen when the stream is built, so this restarts it at
	// the same moment with the other track.
	await start(video.value?.currentTime ?? 0)
}

function selectSubtitle(id: string): void {
	subtitleIndex.value = Number(id)
	applySubtitleTracks()
}

/** Attach the chosen subtitle track, and only the chosen one. */
function applySubtitleTracks(): void {
	const element = video.value
	if (!element) {
		return
	}
	for (const existing of Array.from(element.querySelectorAll('track'))) {
		existing.remove()
	}
	const chosen = subtitles.value.find((track) => track.index === subtitleIndex.value && track.available)
	if (!chosen) {
		return
	}
	const track = document.createElement('track')
	track.kind = 'subtitles'
	track.label = chosen.label
	track.srclang = chosen.language || 'und'
	track.src = subtitleFor(props.item.fileId, chosen.index)
	track.default = true
	element.appendChild(track)
	window.setTimeout(() => {
		const tracks = element.textTracks
		for (let i = 0; i < tracks.length; i++) {
			const cues = tracks[i]
			if (cues) {
				cues.mode = 'showing'
			}
		}
	}, 50)
}

function openExternal(): void {
	externalOpen.value = true
}

async function togglePip(): Promise<void> {
	const element = video.value
	if (!element) {
		return
	}
	try {
		document.pictureInPictureElement
			? await document.exitPictureInPicture()
			: await element.requestPictureInPicture()
	} catch {
		say(t('videogallery', 'Picture in picture was refused.'))
	}
}

async function toggleFullscreen(): Promise<void> {
	const host = video.value?.parentElement
	if (!host) {
		return
	}
	if (document.fullscreenElement) {
		await document.exitFullscreen().catch(() => undefined)
		unlockOrientation()
		return
	}
	await host.requestFullscreen().catch(() => undefined)
	await lockOrientation()
}

/**
 * Turn a phone the right way up for the film.
 *
 * A landscape film on an upright phone is a postage stamp between two black
 * bands. Rotating is what anyone would do by hand, so it is done for them —
 * and matched to the film, since a clip shot upright should stay upright.
 */
async function lockOrientation(): Promise<void> {
	const orientation = screen.orientation as ScreenOrientation & { lock?: (kind: string) => Promise<void> }
	if (!orientation?.lock) {
		return
	}
	const element = video.value
	const wide = (element?.videoWidth ?? 0) >= (element?.videoHeight ?? 0)
	try {
		await orientation.lock(wide ? 'landscape' : 'portrait')
	} catch {
		// Refused on a desktop, or by a browser that will not allow it. No matter.
	}
}

function unlockOrientation(): void {
	try {
		screen.orientation?.unlock?.()
	} catch {
		// nothing to unlock
	}
}

// -- touch gestures --------------------------------------------------------

function showGesture(label: string, percent: number): void {
	gesture.value = { label, percent: Math.round(Math.max(0, Math.min(100, percent))) }
	window.clearTimeout(gestureTimer)
	gestureTimer = window.setTimeout(() => { gesture.value = null }, 900)
}

function onTouchStart(event: TouchEvent): void {
	const point = event.touches[0]
	if (!point) {
		return
	}
	wakeControls()
	touch = {
		x: point.clientX,
		y: point.clientY,
		mode: 'none',
		startValue: 0,
		startTime: video.value?.currentTime ?? 0,
	}
}

function onTouchMove(event: TouchEvent): void {
	const point = event.touches[0]
	const element = video.value
	if (!point || !touch || !element) {
		return
	}
	const dx = point.clientX - touch.x
	const dy = point.clientY - touch.y

	if (touch.mode === 'none') {
		// Wait for the movement to say what it wants to be, so a small wobble
		// does not change the volume.
		if (Math.abs(dx) < 24 && Math.abs(dy) < 24) {
			return
		}
		if (Math.abs(dx) > Math.abs(dy)) {
			touch.mode = 'seek'
		} else {
			const onLeftHalf = touch.x < window.innerWidth / 2
			touch.mode = onLeftHalf ? 'dim' : 'volume'
			touch.startValue = onLeftHalf ? dimming.value : (element.muted ? 0 : element.volume)
		}
	}

	// A full swipe up the screen covers the whole range.
	const travel = -dy / (window.innerHeight * 0.6)

	if (touch.mode === 'volume') {
		const next = Math.max(0, Math.min(1, touch.startValue + travel))
		setVolume(next)
		showGesture(t('videogallery', 'Volume'), next * 100)
	} else if (touch.mode === 'dim') {
		// Never quite to black: a picture that cannot be turned back up is a trap.
		const next = Math.max(0.15, Math.min(1, touch.startValue + travel))
		dimming.value = next
		showGesture(t('videogallery', 'Brightness'), ((next - 0.15) / 0.85) * 100)
	} else if (touch.mode === 'seek') {
		const seconds = (dx / window.innerWidth) * Math.min(600, Math.max(60, duration.value * 0.25))
		const target = Math.max(0, Math.min(duration.value, touch.startTime + seconds))
		element.currentTime = target
		showGesture(clock(target), duration.value > 0 ? (target / duration.value) * 100 : 0)
	}
}

function onTouchEnd(): void {
	if (touch?.mode === 'none') {
		// It was a tap, not a swipe.
		togglePlay()
	}
	touch = null
}

// -- the scrub bar ---------------------------------------------------------

function positionFromEvent(event: MouseEvent): number {
	const element = scrub.value
	if (!element || duration.value <= 0) {
		return 0
	}
	const bounds = element.getBoundingClientRect()
	const ratio = Math.min(1, Math.max(0, (event.clientX - bounds.left) / bounds.width))
	return ratio * duration.value
}

function onScrubClick(event: MouseEvent): void {
	const element = video.value
	if (element) {
		element.currentTime = positionFromEvent(event)
	}
}

/** Show the frame at the pointer, taken out of the thumbnail strip. */
function onScrubHover(event: MouseEvent): void {
	const layout = sprite.value
	const bar = scrub.value
	if (!layout || !bar || duration.value <= 0) {
		return
	}
	const time = positionFromEvent(event)
	const tile = Math.min(layout.tiles - 1, Math.max(0, Math.floor(time / Math.max(0.001, layout.interval))))
	const column = tile % layout.columns
	const row = Math.floor(tile / layout.columns)
	const bounds = bar.getBoundingClientRect()
	const width = layout.width
	const height = Math.round(width * 9 / 16)
	const left = Math.min(bounds.width - width / 2, Math.max(width / 2, event.clientX - bounds.left))

	scrubPreview.value = {
		time,
		style: {
			left: `${left}px`,
			width: `${width}px`,
			height: `${height}px`,
			backgroundImage: `url(${spriteFor(props.item.fileId)})`,
			backgroundSize: `${layout.columns * 100}% ${layout.rows * 100}%`,
			backgroundPosition: `${(column / Math.max(1, layout.columns - 1)) * 100}% ${(row / Math.max(1, layout.rows - 1)) * 100}%`,
		},
	}
}

// -- element events --------------------------------------------------------

function onTimeUpdate(): void {
	currentTime.value = video.value?.currentTime ?? 0
}

function onProgress(): void {
	const element = video.value
	if (!element || element.buffered.length === 0) {
		return
	}
	bufferedEnd.value = element.buffered.end(element.buffered.length - 1)
}

function onWaiting(): void {
	stallsSincePing++
}

/** The moment the picture actually appears, which is what "how fast is it" means. */
function onPlaying(): void {
	playing.value = true
	if (startupMs === 0 && startedAt > 0) {
		startupMs = Math.round(performance.now() - startedAt)
	}
}

function onLoadedMetadata(): void {
	const element = video.value
	if (element && Number.isFinite(element.duration) && element.duration > 0) {
		duration.value = element.duration
	}
}

async function onEnded(): Promise<void> {
	if (!shared.value) {
		await saveProgress(props.item.fileId, {
			position: Math.floor(duration.value),
			duration: Math.floor(duration.value),
			finished: true,
		}).catch(() => undefined)
	}

	const next = series.value?.next
	if (!next) {
		emit('close')
		return
	}
	upNext.value = next
	// Counted down rather than started at once, because the viewer may well
	// have finished for the evening — and a lecture starting by itself in a
	// quiet room is startling.
	if (next.autoplay) {
		countdown.value = Math.max(0, next.delay)
		window.clearInterval(countdownTimer)
		countdownTimer = window.setInterval(() => {
			countdown.value -= 1
			if (countdown.value <= 0) {
				playNext()
			}
		}, 1000)
	}
}

function playNext(): void {
	const next = upNext.value ?? series.value?.next
	window.clearInterval(countdownTimer)
	upNext.value = null
	countdown.value = 0
	if (next) {
		emit('play', next.item as VideoItem)
	}
}

function cancelNext(): void {
	window.clearInterval(countdownTimer)
	upNext.value = null
	countdown.value = 0
	leave()
}

/**
 * Leave the player and go back to whatever was on screen before it opened.
 *
 * Opening a video pushes a step into the browser's history, so the back button
 * and the back gesture close the player rather than leaving the app — which is
 * what anybody would expect, and what the button below does too.
 */
function leave(): void {
	if (pushedHistory) {
		pushedHistory = false
		// Going back unwinds the step we added; the listener then closes us.
		window.history.back()
		return
	}
	emit('close')
}

function onPopState(): void {
	pushedHistory = false
	emit('close')
}

function wakeControls(): void {
	controlsVisible.value = true
	window.clearTimeout(controlsTimer)
	controlsTimer = window.setTimeout(() => {
		if (playing.value) {
			controlsVisible.value = false
		}
	}, 3000)
}

function onKey(event: KeyboardEvent): void {
	switch (event.key) {
	case ' ':
	case 'k':
		event.preventDefault()
		togglePlay()
		break
	case 'ArrowLeft':
		nudge(-10)
		break
	case 'ArrowRight':
		nudge(10)
		break
	case 'ArrowUp':
		setVolume(Math.min(1, volume.value + 0.1))
		break
	case 'ArrowDown':
		setVolume(Math.max(0, volume.value - 0.1))
		break
	case 'f':
		toggleFullscreen()
		break
	case 'm':
		toggleMute()
		break
	case 'Escape':
		if (!document.fullscreenElement) {
			leave()
		}
		break
	}
	wakeControls()
}

/** Closing the tab has to stop the encoder too, and there is no time to ask nicely. */
function onUnload(): void {
	if (sessionToken.value) {
		shared.value
			? publicClose(props.token!, sessionToken.value, true)
			: closePlayback(sessionToken.value, true)
	}
}

onMounted(async () => {
	try {
		const stored = JSON.parse(window.localStorage.getItem('videogallery-volume') ?? 'null')
		if (stored && video.value) {
			video.value.volume = stored.volume ?? 1
			video.value.muted = Boolean(stored.muted)
		}
	} catch {
		// no remembered volume
	}
	window.addEventListener('pagehide', onUnload)
	window.addEventListener('beforeunload', onUnload)
	window.addEventListener('popstate', onPopState)
	try {
		window.history.pushState({ videogallery: props.item.fileId }, '')
		pushedHistory = true
	} catch {
		// A page that will not take a history entry still plays; only the
		// browser's back button loses its meaning.
	}
	// Everything else on the page goes away for the duration. A film with a
	// search bar floating over it is not a film anybody wants to watch, and the
	// header's own links sit over the controls and steal their clicks.
	document.body.classList.add('videogallery-watching')
	wakeControls()
	await start()
})

onBeforeUnmount(async () => {
	window.removeEventListener('pagehide', onUnload)
	window.removeEventListener('beforeunload', onUnload)
	window.removeEventListener('popstate', onPopState)
	document.body.classList.remove('videogallery-watching')
	unlockOrientation()
	window.clearTimeout(gestureTimer)
	window.clearInterval(countdownTimer)
	stopTimers()
	window.clearTimeout(controlsTimer)
	window.clearTimeout(noticeTimer)
	await pushProgress()
	teardownHls()
	if (sessionToken.value) {
		const closing = shared.value
			? publicClose(props.token!, sessionToken.value)
			: closePlayback(sessionToken.value)
		closing.catch(() => undefined)
	}
})

defineExpose({ start })
</script>

<!--
  - Not scoped: these reach outside the player, which is the point of them.
  -->
<style>
/* While a film is playing there is nothing else to look at. */
body.videogallery-watching #header,
body.videogallery-watching #profiler-toolbar,
body.videogallery-watching .skip-navigation {
	display: none !important;
}

body.videogallery-watching {
	overflow: hidden;
}

/* Above everything Nextcloud puts on a page, including its own dialogs. */
.player {
	z-index: 100001 !important;
}
</style>

<style scoped>
.player {
	position: fixed;
	inset: 0;
	z-index: 100001;
	background: #000;
	display: flex;
	outline: none;
}

.player--idle {
	cursor: none;
}

.player__video {
	width: 100%;
	height: 100%;
	object-fit: contain;
	background: #000;
}

.player__spinner {
	position: absolute;
	inset: 0;
	display: grid;
	place-content: center;
	justify-items: center;
	gap: 16px;
	color: #d7dade;
	font-size: 14px;
	background: rgb(0 0 0 / 55%);
}

.player__spinner-ring {
	width: 46px;
	height: 46px;
	border: 3px solid rgb(255 255 255 / 20%);
	border-top-color: #e50914;
	border-radius: 50%;
	animation: spin 900ms linear infinite;
}

@keyframes spin {
	to { transform: rotate(360deg); }
}

.player__error {
	position: absolute;
	inset: 0;
	margin: auto;
	width: min(520px, 88vw);
	height: fit-content;
	padding: 26px;
	border-radius: 12px;
	background: #16181d;
	color: #e9ecef;
	box-shadow: 0 20px 60px rgb(0 0 0 / 70%);
	text-align: center;
}

.player__error h3 {
	margin: 0 0 8px;
	font-size: 18px;
}

.player__error p {
	margin: 0 0 18px;
	color: #aab0b7;
	font-size: 14px;
	line-height: 1.5;
}

.player__error-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	justify-content: center;
}

.player__button {
	padding: 9px 16px;
	border: 1px solid rgb(255 255 255 / 18%);
	border-radius: 6px;
	background: rgb(255 255 255 / 8%);
	color: #fff;
	font-size: 14px;
	cursor: pointer;
}

.player__button:hover {
	background: rgb(255 255 255 / 16%);
}

.player__notice {
	position: absolute;
	left: 50%;
	top: 78px;
	transform: translateX(-50%);
	margin: 0;
	padding: 9px 16px;
	border-radius: 999px;
	background: rgb(0 0 0 / 80%);
	color: #fff;
	font-size: 13px;
	max-width: 74vw;
	text-align: center;
}

.notice-enter-active,
.notice-leave-active {
	transition: opacity 240ms ease;
}

.notice-enter-from,
.notice-leave-to {
	opacity: 0;
}

.player__top {
	position: absolute;
	inset: 0 0 auto;
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 14px 18px;
	/* Solid enough at the top that nothing behind it shows through, fading to
	   nothing so it does not sit as a band across the picture. */
	background: linear-gradient(to bottom, rgb(8 9 11 / 96%) 0%, rgb(8 9 11 / 78%) 45%, rgb(8 9 11 / 0%) 100%);
	transition: opacity 220ms ease;
}

.player__back {
	display: inline-flex;
	align-items: center;
	gap: 7px;
	flex: 0 0 auto;
	padding: 7px 13px 7px 9px;
	border: none;
	border-radius: 8px;
	background: rgb(255 255 255 / 10%);
	color: #fff;
	font-size: 14px;
	font-weight: 600;
	cursor: pointer;
	transition: background 120ms ease;
}

.player__back:hover {
	background: rgb(255 255 255 / 20%);
}

.player__icon--right {
	margin-inline-start: auto;
	flex: 0 0 auto;
	text-decoration: none;
}

@media (max-width: 560px) {
	.player__back-label {
		display: none;
	}

	.player__back {
		padding: 7px 9px;
	}
}

.player__heading {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.player__name {
	font-size: 15px;
	font-weight: 600;
	color: #fff;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.player__mode {
	font-size: 12px;
	color: #a8aeb6;
}

.player__controls {
	position: absolute;
	inset: auto 0 0;
	padding: 34px 18px 16px;
	background: linear-gradient(to top, rgb(8 9 11 / 97%) 0%, rgb(8 9 11 / 88%) 55%, rgb(8 9 11 / 0%) 100%);
	transition: opacity 220ms ease;
}

.player--idle .player__controls,
.player--idle .player__top {
	opacity: 0;
	pointer-events: none;
}

.player__scrub {
	position: relative;
	padding-block: 10px;
	cursor: pointer;
}

.player__scrub-track {
	position: relative;
	height: 4px;
	border-radius: 2px;
	background: rgb(255 255 255 / 26%);
	transition: height 120ms ease;
}

.player__scrub:hover .player__scrub-track {
	height: 6px;
}

.player__scrub-buffer,
.player__scrub-played {
	position: absolute;
	inset: 0 auto 0 0;
	border-radius: 2px;
}

.player__scrub-buffer {
	background: rgb(255 255 255 / 34%);
}

.player__scrub-played {
	background: #e50914;
}

.player__chapter-mark {
	position: absolute;
	top: -2px;
	bottom: -2px;
	width: 2px;
	margin-left: -1px;
	border-radius: 1px;
	background: rgb(255 255 255 / 78%);
	pointer-events: none;
}

.player__touch {
	position: absolute;
	inset: 56px 0 92px;
	z-index: 1;
	touch-action: none;
	/* Only where fingers go: a mouse should pass straight through to the video. */
	display: none;
}

@media (pointer: coarse) {
	.player__touch {
		display: block;
	}
}

.player__gesture {
	position: absolute;
	left: 50%;
	top: 50%;
	z-index: 3;
	transform: translate(-50%, -50%);
	min-width: 180px;
	padding: 14px 18px;
	border-radius: 12px;
	background: rgb(0 0 0 / 74%);
	color: #fff;
	text-align: center;
	pointer-events: none;
}

.player__gesture-label {
	display: block;
	margin-bottom: 8px;
	font-size: 14px;
	font-variant-numeric: tabular-nums;
}

.player__gesture-bar {
	height: 4px;
	border-radius: 2px;
	background: rgb(255 255 255 / 26%);
	overflow: hidden;
}

.player__gesture-fill {
	height: 100%;
	background: #e50914;
}

.player__scrub-knob {
	position: absolute;
	top: 50%;
	width: 13px;
	height: 13px;
	margin-left: -6px;
	border-radius: 50%;
	background: #e50914;
	transform: translateY(-50%) scale(0);
	transition: transform 120ms ease;
}

.player__scrub:hover .player__chapter-mark {
	position: absolute;
	top: -2px;
	bottom: -2px;
	width: 2px;
	margin-left: -1px;
	border-radius: 1px;
	background: rgb(255 255 255 / 78%);
	pointer-events: none;
}

.player__touch {
	position: absolute;
	inset: 56px 0 92px;
	z-index: 1;
	touch-action: none;
	/* Only where fingers go: a mouse should pass straight through to the video. */
	display: none;
}

@media (pointer: coarse) {
	.player__touch {
		display: block;
	}
}

.player__gesture {
	position: absolute;
	left: 50%;
	top: 50%;
	z-index: 3;
	transform: translate(-50%, -50%);
	min-width: 180px;
	padding: 14px 18px;
	border-radius: 12px;
	background: rgb(0 0 0 / 74%);
	color: #fff;
	text-align: center;
	pointer-events: none;
}

.player__gesture-label {
	display: block;
	margin-bottom: 8px;
	font-size: 14px;
	font-variant-numeric: tabular-nums;
}

.player__gesture-bar {
	height: 4px;
	border-radius: 2px;
	background: rgb(255 255 255 / 26%);
	overflow: hidden;
}

.player__gesture-fill {
	height: 100%;
	background: #e50914;
}

.player__scrub-knob {
	transform: translateY(-50%) scale(1);
}

.player__thumb {
	position: absolute;
	bottom: 26px;
	transform: translateX(-50%);
	border: 2px solid rgb(255 255 255 / 65%);
	border-radius: 6px;
	background-repeat: no-repeat;
	box-shadow: 0 8px 24px rgb(0 0 0 / 60%);
	pointer-events: none;
}

.player__thumb-time {
	position: absolute;
	left: 50%;
	bottom: -22px;
	transform: translateX(-50%);
	font-size: 12px;
	color: #fff;
	text-shadow: 0 1px 3px #000;
}

.player__next {
	position: absolute;
	right: 24px;
	bottom: 110px;
	z-index: 4;
	display: flex;
	gap: 12px;
	width: min(380px, 78vw);
	padding: 12px;
	border-radius: 12px;
	background: rgb(20 22 26 / 95%);
	box-shadow: 0 16px 48px rgb(0 0 0 / 70%);
}

.player__next-art {
	width: 108px;
	height: 61px;
	object-fit: cover;
	border-radius: 7px;
	flex: 0 0 auto;
}

.player__next-body {
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.player__next-label {
	margin: 0;
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 0.08em;
	text-transform: uppercase;
	color: #e50914;
}

.player__next-title {
	margin: 0;
	font-size: 14px;
	font-weight: 600;
	color: #fff;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.player__next-meta {
	margin: 0 0 6px;
	font-size: 11px;
	color: #99a0a8;
}

.player__next-actions {
	display: flex;
	gap: 6px;
}

.player__next-play,
.player__next-cancel {
	padding: 5px 12px;
	border: none;
	border-radius: 6px;
	font-size: 12px;
	font-weight: 600;
	cursor: pointer;
}

.player__next-play {
	background: #fff;
	color: #111;
}

.player__next-cancel {
	background: rgb(255 255 255 / 14%);
	color: #fff;
}

@media (max-width: 700px) {
	.player__next {
		right: 12px;
		left: 12px;
		bottom: 96px;
		width: auto;
	}
}

.player__row {
	display: flex;
	align-items: center;
	gap: 6px;
}

.player__spacer {
	flex: 1;
}

.player__menus {
	display: flex;
	align-items: center;
	gap: 2px;
}

.player__icon {
	display: grid;
	place-items: center;
	width: 38px;
	height: 38px;
	border: none;
	border-radius: 8px;
	background: transparent;
	color: #fff;
	cursor: pointer;
	transition: background 120ms ease;
}

.player__icon:hover {
	background: rgb(255 255 255 / 14%);
}

.player__volume {
	display: flex;
	align-items: center;
}

.player__volume-slider {
	width: 0;
	opacity: 0;
	accent-color: #e50914;
	transition: width 160ms ease, opacity 160ms ease;
}

.player__volume:hover .player__volume-slider,
.player__volume-slider:focus {
	width: 84px;
	opacity: 1;
	margin-inline: 6px;
}

.player__time {
	margin-inline: 8px;
	font-size: 13px;
	font-variant-numeric: tabular-nums;
	color: #d5d9de;
}

@media (max-width: 1024px) {
	.player__controls {
		padding: 30px 14px 14px;
	}

	.player__top {
		padding: 12px 14px;
	}
}

/* Where there is a finger rather than a pointer, everything grows. */
@media (pointer: coarse) {
	.player__icon,
	.player__back {
		min-width: 46px;
		min-height: 46px;
	}

	.player__scrub {
		padding-block: 16px;
	}

	.player__scrub-track {
		height: 6px;
	}

	.player__scrub-knob {
		transform: translateY(-50%) scale(1);
		width: 16px;
		height: 16px;
		margin-left: -8px;
	}
}

@media (max-width: 700px) {
	.player__time {
		font-size: 12px;
		margin-inline: 4px;
	}

	.player__volume-slider {
		display: none;
	}

	.player__controls {
		padding: 28px 8px calc(10px + env(safe-area-inset-bottom));
	}

	.player__row {
		gap: 2px;
	}

	.player__name {
		font-size: 14px;
	}

	.player__mode {
		font-size: 11px;
	}

	.player__notice {
		top: 64px;
		font-size: 12px;
		max-width: 88vw;
	}

	/* The thumbnail strip is wider than the screen is worth. */
	.player__thumb {
		display: none;
	}
}

/* Very narrow: keep play, seek, time and full screen; the rest can go. */
@media (max-width: 420px) {
	.player__icon--right {
		display: none;
	}
}

/* A phone held sideways has almost no height to spare. */
@media (orientation: landscape) and (max-height: 460px) {
	.player__top {
		padding: 8px 12px;
	}

	.player__controls {
		padding: 22px 12px 8px;
	}

	.player__scrub {
		padding-block: 8px;
	}
}
</style>
