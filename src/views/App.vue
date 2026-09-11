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
						@share="share"
						@folder="openFolder" />
				</template>
			</template>

			<template v-else-if="view === 'timeline'">
				<section v-for="year in years" :key="year.year" class="gallery__year">
					<h2 class="gallery__year-title">
						{{ year.year }}
						<span class="gallery__year-count">{{ n('videogallery', '%n video', '%n videos', year.count) }}</span>
					</h2>

					<div v-for="day in year.days" :key="day.day" class="gallery__dayblock">
						<h3 class="gallery__day-title">{{ day.label }}</h3>

						<!-- Within a day, the folder each came from: a morning's
						     filming and an evening's lecture are not one heap. -->
						<div v-for="group in day.groups" :key="group.path" class="gallery__group">
							<button v-if="group.name" class="gallery__group-title" @click="openFolder(group.path)">
								<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true">
									<path fill="currentColor" d="M10 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-8z" />
								</svg>
								<span>{{ group.name }}</span>
								<em>{{ group.count }}</em>
							</button>
							<div class="gallery__grid">
								<VideoCard v-for="item in group.items"
									:key="item.fileId"
									:item="item"
									:previews-enabled="config.previews.enabled"
									@play="play"
									@share="share"
									@folder="openFolder" />
							</div>
						</div>
					</div>
				</section>

				<div ref="sentinel" class="gallery__sentinel" aria-hidden="true" />
				<button v-if="years.length && hasMore" class="gallery__more" :disabled="loadingMore" @click="loadMore">
					{{ loadingMore ? t('videogallery', 'Loading…') : t('videogallery', 'Show more') }}
				</button>
			</template>

			<!-- One folder, in the order it should be watched. -->
			<template v-else-if="view === 'folder'">
				<nav class="gallery__crumbs" aria-label="Folders">
					<button class="gallery__crumb" @click="switchTo('all')">{{ t('videogallery', 'All folders') }}</button>
					<template v-for="(crumb, index) in crumbs" :key="crumb.path">
						<span class="gallery__crumb-sep" aria-hidden="true">/</span>
						<button class="gallery__crumb"
							:class="{ 'gallery__crumb--last': index === crumbs.length - 1 }"
							@click="openFolder(crumb.path)">
							{{ crumb.name }}
						</button>
					</template>
				</nav>

				<div v-if="folderView" class="gallery__folder">
					<div class="gallery__folder-head">
						<h2 class="gallery__folder-title">
							{{ folderView.name }}
							<span class="gallery__folder-count">{{ n('videogallery', '%n video', '%n videos', folderView.total) }}</span>
						</h2>
						<button class="gallery__action" @click="shareFolder">
							<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
								<path fill="currentColor" d="M18 16a3 3 0 0 0-2 .8L9 12.7V12l-.1-.7 7-4.1A3 3 0 1 0 15 5l.1.7-7 4.1a3 3 0 1 0 0 4.4l7 4.1-.1.7a3 3 0 1 0 3-3z" />
							</svg>
							<span>{{ t('videogallery', 'Share this folder') }}</span>
						</button>
					</div>

					<div v-if="folderView.children.length" class="gallery__subfolders">
						<button v-for="child in folderView.children"
							:key="child.path"
							class="gallery__subfolder"
							@click="openFolder(child.path)">
							<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
								<path fill="currentColor" d="M10 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-8z" />
							</svg>
							<span class="gallery__subfolder-name">{{ child.name }}</span>
							<span class="gallery__subfolder-count">{{ child.count }}</span>
						</button>
					</div>

					<div class="gallery__grid gallery__grid--all">
						<VideoCard v-for="item in folderView.items"
							:key="item.fileId"
							:item="item"
							:previews-enabled="config.previews.enabled"
							@play="play"
							@share="share"
							@folder="openFolder" />
					</div>
					<p v-if="!folderView.items.length && !folderView.children.length" class="gallery__empty-line">
						{{ t('videogallery', 'There is nothing here.') }}
					</p>
				</div>
			</template>

			<template v-else>
				<div class="gallery__filters">
					<span class="gallery__result-count">
						{{ n('videogallery', '%n folder', '%n folders', total) }}
					</span>
				</div>

				<!-- Folders rather than a wall of files, each shown from the
				     part worth opening. -->
				<section v-for="section in sections" :key="section.path" class="gallery__section">
					<button class="gallery__section-title" @click="openFolder(section.path)">
						<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
							<path fill="currentColor" d="M10 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-8z" />
						</svg>
						<span class="gallery__section-name">{{ section.name }}</span>
						<span class="gallery__section-kind">{{ kindLabel(section.kind) }}</span>
						<span class="gallery__section-count">{{ n('videogallery', '%n video', '%n videos', section.count) }}</span>
					</button>
					<div class="gallery__grid">
						<VideoCard v-for="item in section.items"
							:key="item.fileId"
							:item="item"
							:previews-enabled="config.previews.enabled"
							@play="play"
							@share="share"
							@folder="openFolder" />
					</div>
				</section>

				<p v-if="!sections.length" class="gallery__empty-line">{{ t('videogallery', 'Nothing matched.') }}</p>
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
import {
	fetchEverything,
	fetchFolder,
	fetchItems,
	fetchRails,
	fetchTimeline,
	rescan,
	shareableFolders,
} from '../api'
import type {
	AppConfig,
	FolderSection,
	FolderView as FolderViewData,
	Rail,
	TimelineYear,
	VideoItem,
} from '../types'

const config = loadState<AppConfig>('videogallery', 'config')

const view = ref<'browse' | 'timeline' | 'all' | 'folder'>('browse')
const loading = ref(true)
const fault = ref('')
const scanning = ref(false)

const hero = ref<VideoItem | null>(null)
const rails = ref<Rail[]>([])
const items = ref<VideoItem[]>([])
const years = ref<TimelineYear[]>([])
const sections = ref<FolderSection[]>([])
const folderView = ref<FolderViewData | null>(null)
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

/** The path back up from wherever we are, as buttons. */
const crumbs = computed(() => {
	const path = folderView.value?.path ?? ''
	if (path === '') {
		return []
	}
	const parts = path.split('/')
	return parts.map((name, index) => ({ name, path: parts.slice(0, index + 1).join('/') }))
})

function kindLabel(kind: string): string {
	return kindLabels.value[kind] ?? ''
}

const kindLabels = ref<Record<string, string>>({})

const hasMore = computed(() => {
	if (view.value === 'timeline') {
		const shown = years.value.reduce((count, year) =>
			count + year.days.reduce((inner, day) =>
				inner + day.groups.reduce((deepest, group) => deepest + group.items.length, 0), 0), 0)
		return shown < total.value
	}
	if (view.value === 'all') {
		return sections.value.length < total.value
	}
	return items.value.length < total.value
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
function rememberView(folderPath?: string): void {
	const parts = new URLSearchParams()
	if (folderPath !== undefined) {
		parts.set('path', folderPath)
	}
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
	if (name === 'folder') {
		view.value = 'folder'
	}
	const parts = new URLSearchParams(tail ?? '')
	query.value = parts.get('q') ?? ''
	folder.value = parts.get('path') ?? parts.get('folder') ?? ''
	sort.value = parts.get('sort') ?? 'taken_desc'
}

/** Load whatever the current view needs, from wherever we arrived. */
async function reload(): Promise<void> {
	switch (view.value) {
	case 'timeline':
		await loadTimeline()
		break
	case 'all':
		await loadEverything()
		break
	case 'folder':
		await openFolder(folder.value)
		break
	default:
		await loadRails()
	}
}

async function switchTo(next: 'browse' | 'timeline' | 'all'): Promise<void> {
	view.value = next
	folderView.value = null
	rememberView()
	await reload()
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

/** Everything, as folders rather than as a wall of files. */
async function loadEverything(append = false): Promise<void> {
	loading.value = !append
	try {
		const data = await fetchEverything({
			limit: 20,
			offset: append ? sections.value.length : 0,
			query: query.value,
		})
		sections.value = append ? [...sections.value, ...data.sections] : data.sections
		total.value = data.total
		for (const section of data.sections) {
			if (section.kindLabel) {
				kindLabels.value[section.kind] = section.kindLabel
			}
		}
	} catch {
		fault.value = t('videogallery', 'The library could not be loaded.')
	} finally {
		loading.value = false
	}
}

async function loadTimeline(append = false): Promise<void> {
	loading.value = !append
	try {
		const shown = years.value.reduce((count, year) =>
			count + year.days.reduce((inner, day) =>
				inner + day.groups.reduce((deepest, group) => deepest + group.items.length, 0), 0), 0)
		const data = await fetchTimeline({
			limit: PAGE,
			offset: append ? shown : 0,
			query: query.value,
		})
		years.value = append ? mergeYears(years.value, data.years) : data.years
		total.value = data.total
	} catch {
		fault.value = t('videogallery', 'The library could not be loaded.')
	} finally {
		loading.value = false
	}
}

/**
 * Fold a new page into what is already on screen.
 *
 * A page boundary falls wherever it falls, which is usually in the middle of a
 * day and often in the middle of a folder's worth of that day. Appending
 * without merging would print the same date twice with a gap between.
 */
function mergeYears(existing: TimelineYear[], incoming: TimelineYear[]): TimelineYear[] {
	const merged = existing.map((year) => ({ ...year, days: [...year.days] }))
	for (const year of incoming) {
		const sameYear = merged.find((candidate) => candidate.year === year.year)
		if (!sameYear) {
			merged.push({ ...year, days: [...year.days] })
			continue
		}
		sameYear.count += year.count
		for (const day of year.days) {
			const sameDay = sameYear.days.find((candidate) => candidate.day === day.day)
			if (!sameDay) {
				sameYear.days.push(day)
				continue
			}
			for (const group of day.groups) {
				const sameGroup = sameDay.groups.find((candidate) => candidate.path === group.path)
				if (sameGroup) {
					sameGroup.items.push(...group.items)
				} else {
					sameDay.groups.push(group)
				}
			}
		}
	}
	return merged
}

/** Open one folder, in the order it should be watched. */
async function openFolder(path: string): Promise<void> {
	view.value = 'folder'
	loading.value = true
	rememberView(path)
	try {
		folderView.value = await fetchFolder(path)
	} catch {
		fault.value = t('videogallery', 'That folder could not be opened.')
	} finally {
		loading.value = false
	}
	document.querySelector('.gallery')?.scrollTo({ top: 0, behavior: 'smooth' })
}

function onSearchInput(): void {
	window.clearTimeout(searchTimer)
	searchTimer = window.setTimeout(async () => {
		if (query.value.trim() === '' && view.value === 'all') {
			await loadEverything()
			return
		}
		if (query.value.trim() !== '') {
			view.value = 'all'
			await loadEverything()
		}
		rememberView()
	}, 280)
}

async function onRescan(): Promise<void> {
	scanning.value = true
	try {
		await rescan()
		await reload()
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
		await (view.value === 'timeline' ? loadTimeline(true) : loadEverything(true))
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
	for (const collection of everyCardList()) {
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
 * Share the folder being looked at, so a whole course goes over in one link
 * rather than one video at a time.
 */
async function shareFolder(): Promise<void> {
	const path = folderView.value?.path ?? folder.value
	if (!path) {
		return
	}
	try {
		const { folders: shareable } = await shareableFolders()
		const match = shareable.find((entry: { path: string }) => entry.path === path)
		if (match) {
			sharing.value = { fileId: match.fileId, name: match.name, isFolder: true }
		}
	} catch {
		// Nothing to share, or sharing is off. The button simply does nothing.
	}
}

/** Every list a card may be sitting in, wherever the page is. */
function everyCardList(): VideoItem[][] {
	const lists: VideoItem[][] = [items.value, ...rails.value.map((rail) => rail.items)]
	for (const section of sections.value) {
		lists.push(section.items)
	}
	for (const year of years.value) {
		for (const day of year.days) {
			for (const group of day.groups) {
				lists.push(group.items)
			}
		}
	}
	if (folderView.value) {
		lists.push(folderView.value.items)
	}
	return lists
}

function onProgress(fileId: number, position: number): void {
	for (const collection of everyCardList()) {
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
		await loadEverything()
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

.gallery__year {
	padding: 26px 44px 0;
}

.gallery__year-title {
	display: flex;
	align-items: baseline;
	gap: 10px;
	margin: 0 0 4px;
	padding-bottom: 8px;
	border-bottom: 1px solid rgb(255 255 255 / 9%);
	font-size: 22px;
	font-weight: 800;
	color: #fff;
}

.gallery__year-count {
	font-size: 12px;
	font-weight: 500;
	color: var(--color-text-maxcontrast, #8a9099);
}

.gallery__dayblock {
	margin-top: 18px;
}

.gallery__group {
	margin-top: 12px;
}

.gallery__group-title,
.gallery__section-title,
.gallery__crumb,
.gallery__subfolder {
	font-family: inherit;
	cursor: pointer;
}

.gallery__group-title {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	margin-bottom: 8px;
	padding: 4px 9px 4px 7px;
	border: none;
	border-radius: 7px;
	background: rgb(255 255 255 / 6%);
	color: #cfd3d8;
	font-size: 12px;
}

.gallery__group-title:hover {
	background: rgb(255 255 255 / 14%);
	color: #fff;
}

.gallery__group-title em {
	font-style: normal;
	color: #7f868e;
}

.gallery__section {
	padding: 0 44px;
	margin-bottom: 26px;
}

.gallery__section-title {
	display: flex;
	align-items: center;
	gap: 9px;
	width: 100%;
	margin-bottom: 10px;
	padding: 8px 10px;
	border: none;
	border-radius: 9px;
	background: rgb(255 255 255 / 5%);
	color: #e9ecef;
	text-align: start;
}

.gallery__section-title:hover {
	background: rgb(255 255 255 / 11%);
}

.gallery__section-name {
	font-size: 15px;
	font-weight: 700;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.gallery__section-kind {
	padding: 2px 8px;
	border-radius: 999px;
	background: rgb(229 9 20 / 22%);
	color: #f3b4b7;
	font-size: 10px;
	font-weight: 700;
	letter-spacing: 0.06em;
	text-transform: uppercase;
	white-space: nowrap;
}

.gallery__section-count {
	margin-inline-start: auto;
	font-size: 12px;
	color: #8a9099;
	white-space: nowrap;
}

.gallery__crumbs {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 2px;
	padding: 18px 44px 0;
	font-size: 13px;
	color: #8a9099;
}

.gallery__crumb {
	padding: 5px 8px;
	border: none;
	border-radius: 6px;
	background: transparent;
	color: inherit;
	font-size: 13px;
}

.gallery__crumb:hover {
	background: rgb(255 255 255 / 10%);
	color: #fff;
}

.gallery__crumb--last {
	color: #fff;
	font-weight: 600;
}

.gallery__crumb-sep {
	color: #565c63;
}

.gallery__folder-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
	padding: 12px 44px 0;
}

.gallery__folder-title {
	display: flex;
	align-items: baseline;
	gap: 10px;
	margin: 0;
	font-size: 21px;
	font-weight: 800;
	color: #fff;
}

.gallery__folder-count {
	font-size: 12px;
	font-weight: 500;
	color: #8a9099;
}

.gallery__subfolders {
	display: grid;
	gap: 8px;
	grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
	padding: 16px 44px 4px;
}

.gallery__subfolder {
	display: flex;
	align-items: center;
	gap: 9px;
	padding: 11px 13px;
	border: 1px solid rgb(255 255 255 / 12%);
	border-radius: 10px;
	background: rgb(255 255 255 / 4%);
	color: #e9ecef;
	text-align: start;
}

.gallery__subfolder:hover {
	background: rgb(255 255 255 / 10%);
}

.gallery__subfolder-name {
	flex: 1;
	min-width: 0;
	font-size: 14px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.gallery__subfolder-count {
	font-size: 11px;
	color: #8a9099;
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
	.gallery__empty,
	.gallery__year,
	.gallery__section,
	.gallery__crumbs,
	.gallery__folder-head,
	.gallery__subfolders {
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
	.gallery__empty,
	.gallery__year,
	.gallery__section,
	.gallery__crumbs,
	.gallery__folder-head,
	.gallery__subfolders {
		padding-inline: 14px;
		margin-inline: 0;
	}

	.gallery__year-title {
		font-size: 19px;
	}

	.gallery__subfolders {
		grid-template-columns: 1fr;
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
