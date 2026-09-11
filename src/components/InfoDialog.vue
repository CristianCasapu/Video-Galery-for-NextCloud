<!--
  - SPDX-FileCopyrightText: 2026 Cristian Casapu
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="info" role="dialog" aria-modal="true" @click.self="$emit('close')">
		<div class="info__panel">
			<img v-if="item.hasPoster" class="info__art" :src="poster" alt="">
			<div class="info__wash" />

			<div class="info__body">
				<h2 class="info__title">{{ item.basename }}</h2>
				<p class="info__path">{{ item.path }}</p>
				<p v-if="item.description" class="info__description">{{ item.description }}</p>

				<div class="info__actions">
					<button class="info__play" @click="$emit('play', item)">
						<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
							<path fill="currentColor" d="M8 5v14l11-7z" />
						</svg>
						{{ t('videogallery', 'Play') }}
					</button>
					<button class="info__link" @click="$emit('share', item)">{{ t('videogallery', 'Share') }}</button>
					<button class="info__link" @click="$emit('edit', item)">{{ t('videogallery', 'Edit details') }}</button>
					<a class="info__link" :href="filesUrl">{{ t('videogallery', 'Show in Files') }}</a>
				</div>

				<dl class="info__facts">
					<div v-for="fact in facts" :key="fact.label">
						<dt>{{ fact.label }}</dt>
						<dd>{{ fact.value }}</dd>
					</div>
				</dl>
			</div>

			<button class="info__close" :aria-label="t('videogallery', 'Close')" @click="$emit('close')">
				<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
					<path fill="currentColor" d="M19 6.4 17.6 5 12 10.6 6.4 5 5 6.4 10.6 12 5 17.6 6.4 19 12 13.4 17.6 19 19 17.6 13.4 12z" />
				</svg>
			</button>
		</div>
	</div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { posterUrl } from '../api'
import type { VideoItem } from '../types'

const props = defineProps<{ item: VideoItem }>()
defineEmits<{
	close: []
	play: [item: VideoItem]
	share: [item: VideoItem]
	edit: [item: VideoItem]
}>()

const poster = computed(() => posterUrl(props.item.fileId))
const filesUrl = computed(() => generateUrl('/f/{fileId}', { fileId: props.item.fileId }))

function bytes(size: number): string {
	const units = ['B', 'kB', 'MB', 'GB', 'TB']
	let index = 0
	let value = size
	while (value >= 1024 && index < units.length - 1) {
		value /= 1024
		index++
	}
	return `${value.toFixed(1)} ${units[index]}`
}

/**
 * What the file actually runs at.
 *
 * Matroska and a few other containers carry no overall figure, and older
 * entries in the library were indexed before one was worked out for them. The
 * size and the length always give the answer, so they are used when the file
 * itself is silent.
 */
function effectiveBitrate(item: VideoItem): number {
	if (item.bitrate > 0) {
		return item.bitrate
	}
	return item.duration > 0 && item.size > 0 ? (item.size * 8) / item.duration : 0
}

/** A screencast runs at a few hundred kbit/s; "0.0 Mbit/s" tells nobody that. */
function formatBitrate(bitsPerSecond: number): string {
	return bitsPerSecond >= 1000000
		? `${(bitsPerSecond / 1000000).toFixed(1)} Mbit/s`
		: `${Math.round(bitsPerSecond / 1000)} kbit/s`
}

function clock(seconds: number): string {
	const hours = Math.floor(seconds / 3600)
	const minutes = Math.floor((seconds % 3600) / 60)
	const rest = Math.floor(seconds % 60)
	return hours > 0
		? `${hours} h ${minutes} min`
		: minutes > 0 ? `${minutes} min ${rest} s` : `${rest} s`
}

const facts = computed(() => {
	const item = props.item
	const rows: Array<{ label: string, value: string }> = []

	if (item.takenAt) {
		rows.push({
			label: item.dateSource === 'mtime'
				? t('videogallery', 'Added')
				: t('videogallery', 'Recorded'),
			value: new Date(item.takenAt * 1000).toLocaleString(),
		})
	}
	rows.push({ label: t('videogallery', 'Length'), value: clock(item.duration) })
	rows.push({ label: t('videogallery', 'Size'), value: bytes(item.size) })
	if (item.width && item.height) {
		rows.push({ label: t('videogallery', 'Picture'), value: `${item.width} × ${item.height}${item.fps ? `, ${item.fps.toFixed(0)} fps` : ''}` })
	}
	rows.push({
		label: t('videogallery', 'Video'),
		value: [item.vcodec.toUpperCase(), item.vprofile, item.bitDepth > 8 ? `${item.bitDepth}-bit` : '', item.hdr ? 'HDR' : '']
			.filter(Boolean).join(' · '),
	})
	if (item.acodec) {
		rows.push({
			label: t('videogallery', 'Sound'),
			value: [item.acodec.toUpperCase(), item.achannels ? `${item.achannels} ch` : ''].filter(Boolean).join(' · '),
		})
	}
	rows.push({ label: t('videogallery', 'Container'), value: item.container.toUpperCase() })
	const bitrate = effectiveBitrate(item)
	if (bitrate > 0) {
		rows.push({ label: t('videogallery', 'Bitrate'), value: formatBitrate(bitrate) })
	}
	if (item.audioTracks.length > 1) {
		rows.push({ label: t('videogallery', 'Sound tracks'), value: String(item.audioTracks.length) })
	}
	if (item.subTracks.length) {
		rows.push({ label: t('videogallery', 'Subtitles'), value: String(item.subTracks.length) })
	}
	return rows
})
</script>

<style scoped>
.info {
	position: fixed;
	inset: 0;
	z-index: 9000;
	display: grid;
	place-items: center;
	padding: 20px;
	background: rgb(0 0 0 / 72%);
}

.info__panel {
	position: relative;
	width: min(720px, 100%);
	max-height: 88vh;
	overflow: hidden auto;
	border-radius: 14px;
	background: #16181d;
	box-shadow: 0 26px 80px rgb(0 0 0 / 75%);
}

.info__art {
	width: 100%;
	height: 250px;
	object-fit: cover;
	display: block;
}

.info__wash {
	position: absolute;
	inset: 0 0 auto;
	height: 250px;
	background: linear-gradient(to top, #16181d 2%, rgb(22 24 29 / 0%) 70%);
	pointer-events: none;
}

.info__body {
	position: relative;
	padding: 18px 26px 26px;
}

.info__title {
	margin: 0 0 3px;
	font-size: 22px;
	color: #fff;
}

.info__path {
	margin: 0 0 18px;
	font-size: 12px;
	color: #868d95;
	word-break: break-all;
}

.info__description {
	margin: -10px 0 16px;
	font-size: 14px;
	line-height: 1.55;
	color: #cfd3d8;
	white-space: pre-wrap;
}

.info__actions {
	display: flex;
	align-items: center;
	gap: 9px;
	margin-bottom: 22px;
}

.info__play {
	display: inline-flex;
	align-items: center;
	gap: 7px;
	padding: 10px 20px;
	border: none;
	border-radius: 6px;
	background: #fff;
	color: #111;
	font-size: 14px;
	font-weight: 700;
	cursor: pointer;
}

.info__link {
	padding: 10px 16px;
	border: 1px solid rgb(255 255 255 / 18%);
	border-radius: 6px;
	color: #d5d9de;
	font-size: 13px;
	text-decoration: none;
}

.info__link {
	border: 1px solid rgb(255 255 255 / 18%);
	background: transparent;
	cursor: pointer;
	font-family: inherit;
}

.info__link:hover {
	background: rgb(255 255 255 / 8%);
}

.info__facts {
	display: grid;
	gap: 10px 24px;
	grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
	margin: 0;
}

.info__facts div {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.info__facts dt {
	font-size: 11px;
	letter-spacing: 0.08em;
	text-transform: uppercase;
	color: #7f868e;
}

.info__facts dd {
	margin: 0;
	font-size: 14px;
	color: #e0e4e8;
}

.info__close {
	position: absolute;
	top: 12px;
	right: 12px;
	display: grid;
	place-items: center;
	width: 34px;
	height: 34px;
	border: none;
	border-radius: 50%;
	background: rgb(0 0 0 / 62%);
	color: #fff;
	cursor: pointer;
}

@media (max-width: 600px) {
	.info {
		padding: 0;
		place-items: end stretch;
	}

	.info__panel {
		max-height: 94vh;
		border-radius: 16px 16px 0 0;
	}

	.info__art,
	.info__wash {
		height: 180px;
	}

	.info__body {
		padding: 16px 16px calc(20px + env(safe-area-inset-bottom));
	}

	.info__title {
		font-size: 19px;
	}

	.info__actions {
		flex-wrap: wrap;
	}

	.info__play,
	.info__link {
		flex: 1 1 auto;
		justify-content: center;
		min-height: 44px;
		text-align: center;
	}

	.info__facts {
		grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
	}

	.info__close {
		width: 40px;
		height: 40px;
	}
}
</style>