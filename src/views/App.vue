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
						@play="play" />
				</template>
			</template>

			<template v-else-if="view === 'timeline'">
				<section v-for="day in days" :key="day.day" class="gallery__day">
					<h2 class="gallery__day-title">{{ day.label }}</h2>
					<div class="gallery__grid">
						<VideoCard v-for="item in day.items"
							:key="item.fileId"
							:item="item"
							:previews-enabled="config.previews.enabled"
							@play="play" />
					</div>
				</section>
				<button v-if="days.length && hasMore" class="gallery__more" @click="loadTimeline(true)">
					{{ t('videogallery', 'Show more') }}
				</button>
			</template>

			<template v-else>
				<div class="gallery__filters">
					<span class="gallery__result-count">
						{{ n('videogallery', '%n video', '%n videos', total) }}
					</span>
					<select v-model="sort" :aria-label="t('videogallery', 'Order')" @change="loadItems()">
						<option value="taken_desc">{{ t('videogallery', 'Newest first') }}</option>
						<option value="taken_asc">{{ t('videogallery', 'Oldest first') }}</option>
						<option value="added_desc">{{ t('videogallery', 'Recently added') }}</option>
						<option value="name_asc">{{ t('videogallery', 'By name') }}</option>
						<option value="size_desc">{{ t('videogallery', 'Largest first') }}</option>
						<option value="duration_desc">{{ t('videogallery', 'Longest first') }}</option>
					</select>
					<select v-model="folder" :aria-label="t('videogallery', 'Folder')" @change="loadItems()">
						<option value="">{{ t('videogallery', 'Every folder') }}</option>
						<option v-for="entry in folders" :key="entry.path" :value="entry.path">
							{{ entry.path || '/' }} ({{ entry.count }})
						</option>
					</select>
				</div>

				<div class="gallery__grid gallery__grid--all">
					<VideoCard v-for="item in items"
						:key="item.fileId"
						:item="item"
						:previews-enabled="config.previews.enabled"
						@play="play" />
				</div>
				<p v-if="!items.length" class="gallery__empty-line">{{ t('videogallery', 'Nothing matched.') }}</p>
				<button v-if="hasMore" class="gallery__more" @click="loadItems(true)">
					{{ t('videogallery', 'Show more') }}
				</button>
			</template>
		</main>

		<VideoPlayer v-if="nowPlaying"
			:key="nowPlaying.fileId"
			:item="nowPlaying"
			:config="config"
			@close="stop"
			@progress="onProgress" />

		<InfoDialog v-if="details" :item="details" @close="details = null" @play="play" />
	</div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import { n, t } from '@nextcloud/l10n'
import HeroBanner from '../components/HeroBanner.vue'
import InfoDialog from '../components/InfoDialog.vue'
import VideoCard from '../components/VideoCard.vue'
import VideoPlayer from '../components/VideoPlayer.vue'
import VideoRail from '../components/VideoRail.vue'
import { fetchFolders, fetchItems, fetchRails, fetchTimeline, rescan } from '../api'
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

async function switchTo(next: 'browse' | 'timeline' | 'all'): Promise<void> {
	view.value = next
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

function onProgress(fileId: number, position: number): void {
	for (const collection of [items.value, ...rails.value.map((rail) => rail.items), ...days.value.map((day) => day.items)]) {
		const found = collection.find((candidate) => candidate.fileId === fileId)
		if (found?.progress) {
			found.progress.position = position
			found.progress.percent = found.duration > 0 ? (position / found.duration) * 100 : 0
		}
	}
}

onMounted(async () => {
	await loadRails()
	// Arriving at a direct address for one video opens it straight away.
	if (config.openFileId) {
		const found = await fetchItems({ limit: 1, query: '' })
		const match = found.items.find((candidate) => candidate.fileId === config.openFileId)
		if (match) {
			play(match)
		}
	}
})
</script>

<style scoped>
.gallery {
	min-height: 100%;
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

.gallery__day {
	padding: 22px 44px 0;
}

.gallery__day-title {
	margin: 0 0 12px;
	font-size: 15px;
	font-weight: 700;
	color: #cfd3d8;
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

.gallery__more {
	display: block;
	margin: 28px auto 0;
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

@media (max-width: 700px) {
	.gallery__bar {
		flex-wrap: wrap;
		padding: 10px 16px;
		gap: 10px;
	}

	.gallery__search {
		max-width: none;
		order: 3;
		width: 100%;
	}

	.gallery__action span {
		display: none;
	}

	.gallery__warning,
	.gallery__day,
	.gallery__grid--all,
	.gallery__filters {
		padding-inline: 16px;
		margin-inline: 0;
	}

	.gallery__grid {
		grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
	}
}
</style>
