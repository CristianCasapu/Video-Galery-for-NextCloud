<!--
  - SPDX-FileCopyrightText: 2026 Cristian Casapu
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="shared">
		<header class="shared__bar">
			<span class="shared__brand">{{ t('videogallery', 'Video Gallery') }}</span>
			<nav v-if="share.isFolder" class="shared__crumbs" aria-label="Folders">
				<button class="shared__crumb" @click="openFolder('')">{{ share.name }}</button>
				<template v-for="(part, index) in crumbs" :key="index">
					<span class="shared__sep" aria-hidden="true">/</span>
					<button class="shared__crumb" @click="openFolder(crumbs.slice(0, index + 1).join('/'))">{{ part }}</button>
				</template>
			</nav>
			<span v-else class="shared__crumbs">{{ share.name }}</span>

			<span v-if="!share.canDownload" class="shared__badge" :title="t('videogallery', 'This link allows watching, not downloading.')">
				{{ t('videogallery', 'Watch only') }}
			</span>
		</header>

		<main class="shared__body">
			<div v-if="loading" class="shared__loading"><div class="shared__ring" /></div>
			<p v-else-if="problem" class="shared__empty">{{ problem }}</p>

			<template v-else>
				<p v-if="share.note" class="shared__note">{{ share.note }}</p>

				<section v-if="folders.length" class="shared__folders">
					<button v-for="entry in folders" :key="entry.name" class="shared__folder" @click="openFolder(joinPath(folder, entry.name))">
						<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
							<path fill="currentColor" d="M10 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-8z" />
						</svg>
						<span class="shared__folder-name">{{ entry.name }}</span>
						<span class="shared__folder-count">{{ n('videogallery', '%n video', '%n videos', entry.count) }}</span>
					</button>
				</section>

				<section v-if="items.length" class="shared__grid">
					<VideoCard v-for="item in items"
						:key="item.fileId"
						:item="item"
						:sharable="false"
						:token="share.token"
						:previews-enabled="previewsEnabled"
						@play="play" />
				</section>

				<p v-else-if="!folders.length" class="shared__empty">{{ t('videogallery', 'There is nothing here.') }}</p>
			</template>
		</main>

		<VideoPlayer v-if="nowPlaying"
			:key="nowPlaying.fileId"
			:item="nowPlaying"
			:config="playerConfig"
			:token="share.token"
			@close="nowPlaying = null"
			@play="play" />
	</div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import { n, t } from '@nextcloud/l10n'
import VideoCard from '../components/VideoCard.vue'
import VideoPlayer from '../components/VideoPlayer.vue'
import { publicContents, type PublicShare } from '../api'
import type { AppConfig, QualityRung, VideoItem } from '../types'

interface PublicState {
	share: PublicShare
	ladder: QualityRung[]
	segmentDuration: number
	bandwidthProbe: { enabled: boolean, bytes: number, ttl: number }
	governor: { enabled: boolean, interval: number }
	previews: { enabled: boolean, sprites: boolean }
	externalPlayer: boolean
	transcoding: boolean
	autoplay: boolean
}

const state = loadState<PublicState>('videogallery', 'public')
const share = ref(state.share)
const previewsEnabled = computed(() => state.previews.enabled)

const items = ref<VideoItem[]>([])
const folders = ref<Array<{ name: string, count: number }>>([])
const folder = ref('')
const loading = ref(true)
const problem = ref('')
const nowPlaying = ref<VideoItem | null>(null)

const crumbs = computed(() => (folder.value === '' ? [] : folder.value.split('/')))

/** The player wants the same shape of settings whichever door it came through. */
const playerConfig = computed<AppConfig>(() => ({
	ladder: state.ladder,
	segmentDuration: state.segmentDuration,
	bandwidthProbe: state.bandwidthProbe,
	governor: state.governor,
	previews: state.previews,
	externalPlayer: state.externalPlayer,
	transcoding: state.transcoding,
	playbackPossible: true,
	hardware: true,
	stats: {},
	openFileId: null,
}))

function joinPath(base: string, name: string): string {
	return base === '' ? name : `${base}/${name}`
}

async function openFolder(next: string): Promise<void> {
	folder.value = next
	await load()
	// A folder opened from part way down a long page should start at the top.
	window.scrollTo({ top: 0, behavior: 'smooth' })
}

async function load(): Promise<void> {
	loading.value = true
	problem.value = ''
	try {
		const data = await publicContents(share.value.token, folder.value)
		share.value = data.share
		items.value = data.items
		folders.value = data.folders
	} catch {
		problem.value = t('videogallery', 'This link is no longer available.')
	} finally {
		loading.value = false
	}
}

function play(item: VideoItem): void {
	nowPlaying.value = item
}

onMounted(load)
</script>

<style scoped>
.shared {
	min-height: 100vh;
	background: #0c0d10;
	color: #e9ecef;
}

.shared__bar {
	position: sticky;
	top: 0;
	z-index: 5;
	display: flex;
	align-items: center;
	gap: 14px;
	padding: 12px 32px;
	background: linear-gradient(to bottom, rgb(12 13 16 / 98%), rgb(12 13 16 / 85%));
	border-bottom: 1px solid rgb(255 255 255 / 7%);
}

.shared__brand {
	font-size: 15px;
	font-weight: 800;
	color: #e50914;
	white-space: nowrap;
}

.shared__crumbs {
	display: flex;
	align-items: center;
	gap: 4px;
	flex-wrap: wrap;
	font-size: 14px;
	color: #cfd3d8;
	min-width: 0;
}

.shared__crumb {
	padding: 3px 6px;
	border: none;
	border-radius: 6px;
	background: transparent;
	color: inherit;
	font-size: 14px;
	cursor: pointer;
}

.shared__crumb:hover {
	background: rgb(255 255 255 / 10%);
	color: #fff;
}

.shared__sep {
	color: #666c74;
}

.shared__badge {
	margin-inline-start: auto;
	padding: 4px 10px;
	border: 1px solid rgb(255 255 255 / 18%);
	border-radius: 999px;
	font-size: 11px;
	color: #cfd3d8;
	white-space: nowrap;
}

.shared__body {
	padding: 24px 32px 60px;
}

.shared__note {
	margin: 0 0 18px;
	padding: 11px 15px;
	border-radius: 8px;
	background: rgb(255 255 255 / 6%);
	font-size: 13px;
	color: #cfd3d8;
}

.shared__folders {
	display: grid;
	gap: 10px;
	grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
	margin-bottom: 26px;
}

.shared__folder {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 12px 14px;
	border: 1px solid rgb(255 255 255 / 12%);
	border-radius: 10px;
	background: rgb(255 255 255 / 4%);
	color: #e9ecef;
	text-align: start;
	cursor: pointer;
	transition: background 130ms ease;
}

.shared__folder:hover {
	background: rgb(255 255 255 / 10%);
}

.shared__folder-name {
	flex: 1;
	min-width: 0;
	font-size: 14px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.shared__folder-count {
	font-size: 11px;
	color: #8d949d;
	white-space: nowrap;
}

.shared__grid {
	display: grid;
	gap: 14px;
	grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
}

.shared__grid :deep(.card) {
	width: 100%;
}

.shared__loading {
	display: grid;
	place-items: center;
	min-height: 40vh;
}

.shared__ring {
	width: 40px;
	height: 40px;
	border: 3px solid rgb(255 255 255 / 18%);
	border-top-color: #e50914;
	border-radius: 50%;
	animation: spin 900ms linear infinite;
}

@keyframes spin {
	to { transform: rotate(360deg); }
}

.shared__empty {
	padding: 60px 0;
	text-align: center;
	color: #8d949d;
}

@media (max-width: 700px) {
	.shared__bar,
	.shared__body {
		padding-inline: 16px;
	}

	.shared__grid {
		grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
	}
}
</style>
