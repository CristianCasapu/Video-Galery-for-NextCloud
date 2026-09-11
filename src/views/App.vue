<!--
  - SPDX-FileCopyrightText: 2026 Cristian Casapu
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="gallery">
		<nav class="gallery__bar">
			<span class="gallery__brand">{{ t('videogallery', 'Video Gallery') }}</span>

			<div class="gallery__tabs" role="tablist">
				<button v-for="tab in tabs"
					:key="tab.id"
					class="gallery__tab"
					:class="{ 'gallery__tab--on': view === tab.id }"
					role="tab"
					:aria-selected="view === tab.id"
					@click="switchTo(tab.id)">
					{{ tab.label }}
				</button>
			</div>

			<div class="gallery__search">
				<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
					<path fill="currentColor" d="M15.5 14h-.8l-.3-.3a6.5 6.5 0 1 0-.7.7l.3.3v.8l5 5 1.5-1.5zm-6 0a4.5 4.5 0 1 1 0-9 4.5 4.5 0 0 1 0 9z" />
				</svg>
				<input v-model="query"
					type="search"
					:placeholder="t('videogallery', 'Search the library')"
					:aria-label="t('videogallery', 'Search the library')"
					@input="onSearchInput">
			</div>

			<button class="gallery__action" :disabled="scanning" @click="onRescan">
				<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" :class="{ 'gallery__spin': scanning }">
					<path fill="currentColor" d="M17.65 6.35A8 8 0 1 0 19.73 14h-2.08A6 6 0 1 1 12 6a5.9 5.9 0 0 1 4.22 1.78L13 11h7V4z" />
				</svg>
				<span>{{ scanning ? t('videogallery', 'Looking…') : t('videogallery', 'Look for new videos') }}</span>
			</button>
		</nav>

		<div v-if="!config.playbackPossible" class="gallery__warning">
			{{ t('videogallery', 'Playback of most formats is unavailable until an administrator finishes setting this up. The Video Gallery settings page says what is missing.') }}
		</div>

		<main class="gallery__body">
			<div v-if="loading" class="gallery__loading">
				<div class="gallery__ring" />
			</div>

			<p v-else-if="fault" class="gallery__fault">{{ fault }}</p>

			<template v-else-if="view === 'browse'">
				<div v-if="!rails.length" class="gallery__empty">
					<h2>{{ t('videogallery', 'Nothing here yet') }}</h2>
					<p>{{ emptyMessage }}</p>
					<button class="gallery__action gallery__action--solid" @click="onRescan">
						{{ t('videogallery', 'Look for videos now') }}
					</button>
				</div>

				<template v-else>
					<HeroBanner v-if="hero" :item="hero" @play="play" @info="showInfo" />
					<VideoRail v-for="rail in rails"
						:key="rail.id"
						:title="rail.title"
						:items="rail.items"
						:previews-enabled="config.previews.enabled"
						@play="play"
						@share="share" />
				</template>
			</template>

			<template v-else-if="view === 'timeline'">
				<!--
				  - One continuous grid rather than a grid per day. A day with
				  - three videos in it left most of a row empty and the page
				  - looked half used; here the videos flow on and the dates sit
				  - across the full width as dividers between them.
				  -->
				<div class="gallery__timeline">
					<template v-for="day in days" :key="day.day">
						<h2 class="gallery__day-title">{{ day.label }}</h2>
						<VideoCard v-for="item in day.items"
							:key="item.fileId"
							:item="item"
							:previews-enabled="config.previews.enabled"
							@play="play"
							@share="share" />
					</template>
				</div>
				<div ref="sentinel" class="gallery__sentinel" aria-hidden="true" />
				<button v-if="days.length && hasMore" class="gallery__more" :disabled="loadingMore" @click="loadMore">
					{{ loadingMore ? t('videogallery', 'Loading…') : t('videogallery', 'Show more') }}
				</button>
			</template>

			<template v-else>
				<div class="gallery__filters">
					<span class="gallery__result-count">
						{{ n('videogallery', '%n video', '%n videos', total) }}
					</span>
					<select v-model="sort" :aria-label="t('videogallery', 'Order')" @change="loadItems(); rememberView()">
						<option value="taken_desc">{{ t('videogallery', 'Newest first') }}</option>
						<option value="taken_asc">{{ t('videogallery', 'Oldest first') }}</option>
						<option value="added_desc">{{ t('videogallery', 'Recently added') }}</option>
						<option value="name_asc">{{ t('videogallery', 'By name') }}</option>
						<option value="size_desc">{{ t('videogallery', 'Largest first') }}</option>
						<option value="duration_desc">{{ t('videogallery', 'Longest first') }}</option>
					</select>
					<select v-model="folder" :aria-label="t('videogallery', 'Folder')" @change="loadItems(); rememberView()">
						<option value="">{{ t('videogallery', 'Every folder') }}</option>
						<option v-for="entry in folders" :key="entry.path" :value="entry.path">
							{{ entry.path || '/' }} ({{ entry.count }})
						</option>
					</select>
					<button v-if="folder" class="gallery__action" @click="shareFolder">
						<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
							<path fill="currentColor" d="M18 16a3 3 0 0 0-2 .8L9 12.7V12l-.1-.7 7-4.1A3 3 0 1 0 15 5l.1.7-7 4.1a3 3 0 1 0 0 4.4l7 4.1-.1.7a3 3 0 1 0 3-3z" />
						</svg>
						<span>{{ t('videogallery', 'Share this folder') }}</span>
					</button>
				</div>

				<div class="gallery__grid gallery__grid--all">
					<VideoCard v-for="item in items"
						:key="item.fileId"
						:item="item"
						:previews-enabled="config.previews.enabled"
						@play="play"
						@share="share" />
				</div>
				<p v-if="!items.length" class="gallery__empty-line">{{ t('videogallery', 'Nothing matched.') }}</p>
				<div ref="sentinel" class="gallery__sentinel" aria-hidden="true" />
				<button v-if="hasMore" class="gallery__more" :disabled="loadingMore" @click="loadMore">
					{{ loadingMore ? t('videogallery', 'Loading…') : t('videogallery', 'Show more') }}
				</button>
			</template>
		</main>

		<VideoPlayer v-if="nowPlaying"
			:key="nowPlaying.fileId"
			:item="nowPlaying"
			:config="config"
			@close="stop"
			@progress="onProgress"
			@play="play" />

		<InfoDialog v-if="details"
			:item="details"
			@close="details = null"
			@play="play"
			@share="share"
			@edit="edit" />

		<MetadataDialog v-if="editing"
			:item="editing"
			@close="editing = null"
			@saved="onEdited" />

		<ShareDialog v-if="sharing"
			:file-id="sharing.fileId"
			:name="sharing.name"
			:is-folder="sharing.isFolder"
			@close="sharing = null" />
	</div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import { n, t } from '@nextcloud/l10n'
import HeroBanner from '../components/HeroBanner.vue'
import InfoDialog from '../components/InfoDialog.vue'
import MetadataDialog from '../components/MetadataDialog.vue'
import ShareDialog from '../components/ShareDialog.vue'
import VideoCard from '../components/VideoCard.vue'
import VideoPlayer from '../components/VideoPlayer.vue'
import VideoRail from '../components/VideoRail.vue'
import { fetchFolders, fetchItems, fetchRails, fetchTimeline, rescan, shareableFolders } from '../api'
import type { AppConfig, Rail, VideoItem } from '../types'

const config = loadState<AppConfig>('videogallery', 'config')

const view = ref<'browse' | 'timeline' | 'all'>('browse')
const loading = ref(true)
const fault = ref('')
const scanning = ref(false)

const hero = ref<VideoItem | null>(null)
const rails = ref<Rail[]>([])
const items = ref<VideoItem[]>([])
const days = ref<Array<{ day: string, label: string, items: VideoItem[] }>>([])
const folders = ref<Array<{ path: string, count: number }>>([])
const total = ref(0)
const query = ref('')
const sort = ref('taken_desc')
const folder = ref('')

const nowPlaying = ref<VideoItem | null>(null)
const details = ref<VideoItem | null>(null)
const sharing = ref<{ fileId: number, name: string, isFolder: boolean } | null>(null)
const editing = ref<VideoItem | null>(null)
const sentinel = ref<HTMLElement | null>(null)
const loadingMore = ref(false)
let observer: IntersectionObserver | undefined

const PAGE = 120
let searchTimer: number | undefined

const tabs = computed(() => [
	{ id: 'browse' as const, label: t('videogallery', 'Browse') },
	{ id: 'timeline' as const, label: t('videogallery', 'Timeline') },
	{ id: 'all' as const, label: t('videogallery', 'Everything') },
])

const hasMore = computed(() => {
	const shown = view.value === 'timeline'
		? days.value.reduce((count, day) => count + day.items.length, 0)
		: items.value.length
	return shown < total.value
})

const emptyMessage = computed(() => {
	const stats = config.stats ?? {}
	if ((stats.total ?? 0) > 0 && (stats.ok ?? 0) === 0) {
		return t('videogallery', 'Your videos have been found and are being read. They will appear here as that finishes.')
	}
	return t('videogallery', 'No videos have been found in your account yet.')
})

/**
 * Keep the address in step with what is on screen.
 *
 * Without this, reloading the page while looking at the timeline puts you back
 * on the front page — and so does sending somebody the address of what you are
 * looking at, which is worse.
 */
function rememberView(): void {
	const parts = new URLSearchParams()
	if (query.value) {
		parts.set('q', query.value)
	}
	if (folder.value) {
		parts.set('folder', folder.value)
	}
	if (sort.value !== 'taken_desc') {
		parts.set('sort', sort.value)
	}
	const tail = parts.toString()
	const hash = `#${view.value}${tail ? '?' + tail : ''}`
	if (window.location.hash !== hash) {
		// Replaced rather than pushed: the back button belongs to the player,
		// which uses it to close itself.
		window.history.replaceState(window.history.state, '', hash)
	}
}

/** What the address says we should be looking at. */
function readView(): void {
	const hash = window.location.hash.replace(/^#/, '')
	if (hash === '') {
		return
	}
	const [name, tail] = hash.split('?')
	if (name === 'timeline' || name === 'all' || name === 'browse') {
		view.value = name
	}
	const parts = new URLSearchParams(tail ?? '')
	query.value = parts.get('q') ?? ''
	folder.value = parts.get('folder') ?? ''
	sort.value = parts.get('sort') ?? 'taken_desc'
}

async function switchTo(next: 'browse' | 'timeline' | 'all'): Promise<void> {
	view.value = next
	rememberView()
	if (next === 'timeline') {
		await loadTimeline()
	} else if (next === 'all') {
		await Promise.all([loadItems(), loadFolders()])
	} else {
		await loadRails()
	}
}

async function loadRails(): Promise<void> {
	loading.value = true
	try {
		const data = await fetchRails()
		hero.value = data.hero
		rails.value = data.rails
	} catch {
		fault.value = t('videogallery', 'The library could not be loaded.')
	} finally {
		loading.value = false
	}
}

async function loadItems(append = false): Promise<void> {
	loading.value = !append
	try {
		const data = await fetchItems({
			limit: PAGE,
			offset: append ? items.value.length : 0,
			query: query.value,
			folder: folder.value,
			sort: sort.value,
		})
		items.value = append ? [...items.value, ...data.items] : data.items
		total.value = data.total
	} catch {
		fault.value = t('videogallery', 'The library could not be loaded.')
	} finally {
		loading.value = false
	}
}

async function loadTimeline(append = false): Promise<void> {
	loading.value = !append
	try {
		const shown = days.value.reduce((count, day) => count + day.items.length, 0)
		const data = await fetchTimeline({
			limit: PAGE,
			offset: append ? shown : 0,
			query: query.value,
		})
		if (!append) {
			days.value = data.days
		} else {
			// A day already on screen keeps its heading and gains the new items.
			for (const day of data.days) {
				const existing = days.value.find((candidate) => candidate.day === day.day)
				existing ? existing.items.push(...day.items) : days.value.push(day)
			}
		}
		total.value = data.total
	} catch {
		fault.value = t('videogallery', 'The library could not be loaded.')
	} finally {
		loading.value = false
	}
}

async function loadFolders(): Promise<void> {
	try {
		folders.value = (await fetchFolders()).folders
	} catch {
		folders.value = []
	}
}

function onSearchInput(): void {
	window.clearTimeout(searchTimer)
	searchTimer = window.setTimeout(async () => {
		if (query.value.trim() === '' && view.value === 'all') {
			await loadItems()
			return
		}
		if (query.value.trim() !== '') {
			view.value = 'all'
			await loadItems()
		}
		rememberView()
	}, 280)
}

async function onRescan(): Promise<void> {
	scanning.value = true
	try {
		await rescan()
		await (view.value === 'browse' ? loadRails() : view.value === 'timeline' ? loadTimeline() : loadItems())
	} finally {
		scanning.value = false
	}
}

/**
 * Fetch the next page, whichever view is asking.
 *
 * Guarded, because the watcher below fires again the moment the new rows push
 * the marker back into view, and an unguarded call would empty the library into
 * the page in one go.
 */
async function loadMore(): Promise<void> {
	if (loadingMore.value || !hasMore.value) {
		return
	}
	loadingMore.value = true
	try {
		await (view.value === 'timeline' ? loadTimeline(true) : loadItems(true))
	} finally {
		loadingMore.value = false
	}
}

/**
 * Watch for the bottom of the list coming into view.
 *
 * Scrolling to the end of what has been loaded is as clear a request for more
 * as pressing a button, so the next page is fetched before the end is reached
 * and the button becomes something for people who prefer to ask.
 */
function watchTheBottom(): void {
	observer?.disconnect()
	if (!sentinel.value) {
		return
	}
	observer = new IntersectionObserver((entries) => {
		if (entries.some((entry) => entry.isIntersecting)) {
			loadMore()
		}
	}, {
		// The scrolling happens inside the app, not in the window.
		root: sentinel.value.closest('.gallery'),
		// Start fetching a screenful early, so the rows are there by the time
		// anybody reaches them.
		rootMargin: '600px 0px',
	})
	observer.observe(sentinel.value)
}

function play(item: VideoItem): void {
	details.value = null
	nowPlaying.value = item
}

function stop(): void {
	nowPlaying.value = null
	// The row of things half watched is out of date the moment a film is closed.
	if (view.value === 'browse') {
		loadRails()
	}
}

function showInfo(item: VideoItem): void {
	details.value = item
}

function edit(item: VideoItem): void {
	details.value = null
	editing.value = item
}

/** A corrected title or date changes where the video sits, so reload the view. */
async function onEdited(item: VideoItem): Promise<void> {
	editing.value = null
	for (const collection of [items.value, ...rails.value.map((rail) => rail.items), ...days.value.map((day) => day.items)]) {
		const index = collection.findIndex((candidate) => candidate.fileId === item.fileId)
		if (index >= 0) {
			collection[index] = { ...collection[index], ...item }
		}
	}
	if (view.value === 'timeline') {
		await loadTimeline()
	}
}

function share(item: VideoItem): void {
	details.value = null
	sharing.value = { fileId: item.fileId, name: item.basename, isFolder: false }
}

/**
 * Share the folder currently being looked at, so a whole course can be handed
 * over in one link rather than one video at a time.
 */
async function shareFolder(): Promise<void> {
	try {
		const { folders: shareable } = await shareableFolders()
		const match = shareable.find((entry) => entry.path === folder.value)
		if (match) {
			sharing.value = { fileId: match.fileId, name: match.name, isFolder: true }
		}
	} catch {
		// Nothing to share, or sharing is off. The button simply does nothing.
	}
}

function onProgress(fileId: number, position: number): void {
	for (const collection of [items.value, ...rails.value.map((rail) => rail.items), ...days.value.map((day) => day.items)]) {
		const found = collection.find((candidate) => candidate.fileId === fileId)
		if (found?.progress) {
			found.progress.position = position
			found.progress.percent = found.duration > 0 ? (position / found.duration) * 100 : 0
		}
	}
}

watch([sentinel, view], () => nextTick(watchTheBottom))
onBeforeUnmount(() => observer?.disconnect())

onMounted(async () => {
	readView()
	if (view.value === 'timeline') {
		await loadTimeline()
	} else if (view.value === 'all') {
		await Promise.all([loadItems(), loadFolders()])
	} else {
		await loadRails()
	}
	// Arriving at a direct address for one video opens it straight away.
	await nextTick()
	watchTheBottom()

	if (config.openFileId) {
		const found = await fetchItems({ limit: 1, query: '' })
		const match = found.items.find((candidate) => candidate.fileId === config.openFileId)
		if (match) {
			play(match)
		}
	}
})
</script>

<!--
  - Nextcloud lays its content pane out as a flex row with the overflow clipped,
  - and expects the app inside it to say how much room it wants and to do its own
  - scrolling. Without that an app is squeezed to the width of its widest
  - unbreakable child and its overflow simply disappears.
  -->
<style>
/* An inline SVG sits on the text baseline, which leaves every icon a pixel or
   two high and to the left of the middle of the box it is centred in. */
#videogallery svg,
#videogallery-public svg,
.player svg,
.share__panel svg,
.info__panel svg {
	display: block;
}

#videogallery {
	flex: 1 1 auto;
	min-width: 0;
	width: 100%;
	height: 100%;
	overflow: hidden;
	display: flex;
	flex-direction: column;
}
</style>

<style scoped>
.gallery {
	flex: 1 1 auto;
	min-height: 0;
	width: 100%;
	overflow-y: auto;
	overflow-x: hidden;
	background: #0c0d10;
	color: #e9ecef;
}

.gallery__bar {
	position: sticky;
	top: 0;
	z-index: 6;
	display: flex;
	align-items: center;
	gap: 16px;
	padding: 10px 44px;
	background: linear-gradient(to bottom, rgb(12 13 16 / 98%), rgb(12 13 16 / 82%));
	backdrop-filter: blur(8px);
	border-bottom: 1px solid rgb(255 255 255 / 7%);
}

.gallery__brand {
	font-size: 16px;
	font-weight: 800;
	letter-spacing: 0.02em;
	color: #e50914;
	white-space: nowrap;
}

.gallery__tabs {
	display: flex;
	gap: 2px;
}

.gallery__tab {
	padding: 7px 13px;
	border: none;
	border-radius: 7px;
	background: transparent;
	color: #aab0b7;
	font-size: 14px;
	cursor: pointer;
}

.gallery__tab:hover {
	color: #fff;
}

.gallery__tab--on {
	background: rgb(255 255 255 / 12%);
	color: #fff;
	font-weight: 600;
}

.gallery__search {
	display: flex;
	align-items: center;
	gap: 7px;
	flex: 1;
	max-width: 340px;
	margin-inline-start: auto;
	padding: 6px 11px;
	border: 1px solid rgb(255 255 255 / 14%);
	border-radius: 8px;
	background: rgb(0 0 0 / 40%);
	color: #8d949d;
}

.gallery__search input {
	flex: 1;
	min-width: 0;
	border: none;
	background: transparent;
	color: #e9ecef;
	font-size: 14px;
	outline: none;
}

.gallery__action {
	display: inline-flex;
	align-items: center;
	gap: 7px;
	padding: 7px 13px;
	border: 1px solid rgb(255 255 255 / 14%);
	border-radius: 8px;
	background: transparent;
	color: #d5d9de;
	font-size: 13px;
	cursor: pointer;
	white-space: nowrap;
}

.gallery__action:hover:not(:disabled) {
	background: rgb(255 255 255 / 9%);
}

.gallery__action:disabled {
	opacity: 0.6;
	cursor: default;
}

.gallery__action--solid {
	margin-top: 16px;
	background: #e50914;
	border-color: transparent;
	color: #fff;
	font-weight: 600;
}

.gallery__spin {
	animation: spin 1s linear infinite;
}

@keyframes spin {
	to { transform: rotate(360deg); }
}

.gallery__warning {
	margin: 12px 44px 0;
	padding: 11px 15px;
	border-radius: 8px;
	background: rgb(229 149 9 / 16%);
	border: 1px solid rgb(229 149 9 / 40%);
	color: #f0c674;
	font-size: 13px;
}

.gallery__body {
	padding-bottom: 60px;
}

.gallery__loading {
	display: grid;
	place-items: center;
	min-height: 50vh;
}

.gallery__ring {
	width: 42px;
	height: 42px;
	border: 3px solid rgb(255 255 255 / 18%);
	border-top-color: #e50914;
	border-radius: 50%;
	animation: spin 900ms linear infinite;
}

.gallery__fault,
.gallery__empty-line {
	padding: 60px 44px;
	text-align: center;
	color: #8d949d;
}

.gallery__empty {
	padding: 90px 44px;
	text-align: center;
}

.gallery__empty h2 {
	margin: 0 0 8px;
	font-size: 22px;
}

.gallery__empty p {
	margin: 0;
	color: #8d949d;
}

.gallery__timeline {
	display: grid;
	gap: 14px;
	grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
	padding: 22px 44px 0;
}

.gallery__timeline :deep(.card) {
	width: 100%;
}

.gallery__day-title {
	/* Across every column, so it reads as a divider rather than a first item. */
	grid-column: 1 / -1;
	margin: 14px 0 0;
	font-size: 15px;
	font-weight: 700;
	color: #cfd3d8;
}

.gallery__day-title:first-child {
	margin-top: 0;
}

.gallery__grid {
	display: grid;
	gap: 14px;
	grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
}

.gallery__grid--all {
	padding: 8px 44px 0;
}

.gallery__grid :deep(.card) {
	width: 100%;
}

.gallery__filters {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 18px 44px 4px;
	font-size: 13px;
	color: #8d949d;
}

.gallery__filters select {
	padding: 6px 9px;
	border: 1px solid rgb(255 255 255 / 14%);
	border-radius: 7px;
	background: #16181d;
	color: #e9ecef;
	font-size: 13px;
}

.gallery__result-count {
	margin-inline-end: auto;
}

.gallery__sentinel {
	height: 1px;
}

.gallery__more {
	display: block;
	/* Sitting in the middle of the space below the last row, rather than
	   crowding it. */
	margin: 40px auto 48px;
	padding: 10px 26px;
	border: 1px solid rgb(255 255 255 / 18%);
	border-radius: 8px;
	background: transparent;
	color: #d5d9de;
	font-size: 14px;
	cursor: pointer;
}

.gallery__more:hover {
	background: rgb(255 255 255 / 9%);
}

/* Tablets: the bar still fits on one line, but with less room to spare. */
@media (max-width: 1024px) {
	.gallery__bar {
		padding-inline: 20px;
		gap: 12px;
	}

	.gallery__search {
		max-width: 260px;
	}

	.gallery__warning,
	.gallery__timeline,
	.gallery__grid--all,
	.gallery__filters,
	.gallery__empty {
		padding-inline: 20px;
	}

	.gallery__timeline,
	.gallery__grid {
		grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
	}
}

/* Phones: the bar wraps, the search takes its own line, and everything that
   can be tapped is big enough to tap. */
@media (max-width: 700px) {
	.gallery__bar {
		flex-wrap: wrap;
		padding: 10px 14px;
		gap: 8px;
	}

	.gallery__brand {
		font-size: 15px;
	}

	.gallery__tabs {
		order: 2;
		flex: 1 1 auto;
	}

	.gallery__tab {
		padding: 8px 11px;
		min-height: 40px;
	}

	.gallery__search {
		max-width: none;
		order: 4;
		width: 100%;
		min-height: 42px;
	}

	.gallery__search input {
		/* Under sixteen pixels and Safari zooms the page when it is focused. */
		font-size: 16px;
	}

	.gallery__action {
		order: 3;
		min-height: 40px;
	}

	.gallery__action span {
		display: none;
	}

	.gallery__warning,
	.gallery__timeline,
	.gallery__grid--all,
	.gallery__filters,
	.gallery__empty {
		padding-inline: 14px;
		margin-inline: 0;
	}

	.gallery__timeline,
	.gallery__grid {
		grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
		gap: 10px;
	}

	.gallery__filters {
		flex-wrap: wrap;
		gap: 8px;
	}

	.gallery__filters select {
		flex: 1 1 auto;
		min-height: 40px;
	}

	.gallery__result-count {
		flex: 1 0 100%;
	}

	.gallery__more {
		width: calc(100% - 28px);
		min-height: 46px;
		margin-block: 28px 36px;
	}
}

/* Narrow phones: two columns rather than one and a half. */
@media (max-width: 420px) {
	.gallery__timeline,
	.gallery__grid {
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}
}
</style>
