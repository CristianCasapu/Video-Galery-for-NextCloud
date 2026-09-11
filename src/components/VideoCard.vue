<!--
  - SPDX-FileCopyrightText: 2026 Cristian Casapu
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="card"
		:class="{ 'card--active': hovering, 'card--wide': wide }"
		:style="cardStyle"
		tabindex="0"
		role="button"
		:aria-label="item.basename"
		@mouseenter="onEnter"
		@mouseleave="onLeave"
		@focus="onEnter"
		@blur="onLeave"
		@click="$emit('play', item)"
		@keydown.enter.prevent="$emit('play', item)"
		@keydown.space.prevent="$emit('play', item)">
		<div class="card__frame">
			<img v-if="!posterFailed"
				class="card__poster"
				:src="poster"
				:alt="''"
				loading="lazy"
				decoding="async"
				@error="onPosterError">
			<div v-else class="card__poster card__poster--missing">
				<span>{{ item.container.toUpperCase() }}</span>
			</div>

			<!-- The clip only exists in the page while the pointer is on the card,
			     so a row of twenty cards is a row of twenty pictures, not twenty
			     videos all decoding at once. -->
			<video v-if="showLoop"
				ref="loop"
				class="card__loop"
				:src="loopSrc"
				muted
				loop
				playsinline
				preload="none"
				@playing="loopReady = true"
				@error="loopFailed = true" />

			<div class="card__shade" />

			<span v-if="item.duration" class="card__duration">{{ clock(item.duration) }}</span>
			<span v-if="badge" class="card__badge">{{ badge }}</span>

			<div v-if="progressPercent > 0" class="card__progress">
				<div class="card__progress-bar" :style="{ width: progressPercent + '%' }" />
			</div>

			<button class="card__play" :aria-label="t('videogallery', 'Play')" tabindex="-1">
				<svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true">
					<path fill="currentColor" d="M8 5v14l11-7z" />
				</svg>
			</button>

			<button v-if="sharable"
				class="card__share"
				:aria-label="t('videogallery', 'Share')"
				:title="t('videogallery', 'Share')"
				@click.stop="$emit('share', item)">
				<svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true">
					<path fill="currentColor" d="M18 16a3 3 0 0 0-2 .8L9 12.7V12l-.1-.7 7-4.1A3 3 0 1 0 15 5l.1.7-7 4.1a3 3 0 1 0 0 4.4l7 4.1-.1.7a3 3 0 1 0 3-3z" />
				</svg>
			</button>
		</div>

		<div class="card__meta">
			<span class="card__title" :title="item.path">{{ item.basename }}</span>
			<span class="card__sub">{{ subtitle }}</span>
		</div>
	</div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import { loopUrl, posterUrl, publicLoopUrl, publicPosterUrl } from '../api'
import type { VideoItem } from '../types'

const props = withDefaults(defineProps<{
	item: VideoItem
	wide?: boolean
	previewsEnabled?: boolean
	sharable?: boolean
	/** Set when this card is on a page reached through a share link. */
	token?: string
}>(), {
	wide: false,
	previewsEnabled: true,
	sharable: true,
	token: '',
})

defineEmits<{ play: [item: VideoItem], share: [item: VideoItem] }>()

const hovering = ref(false)
const showLoop = ref(false)
const loopReady = ref(false)
const loopFailed = ref(false)
const posterFailed = ref(false)
const loop = ref<HTMLVideoElement | null>(null)
let timer: number | undefined

// A visitor with a link has no account, so the pictures have to come through
// the link as well.
const posterAttempt = ref(0)
const poster = computed(() => {
	const base = props.token
		? publicPosterUrl(props.token, props.item.fileId)
		: posterUrl(props.item.fileId)
	// A retry has to look like a different address, or the browser simply hands
	// back the failure it cached.
	return posterAttempt.value === 0 ? base : `${base}?try=${posterAttempt.value}`
})

/**
 * A picture that is not there yet is not a picture that will never be there.
 *
 * Only so many previews are made at once, so opening a full page of them means
 * most requests are turned away at first. Each card waits a little and asks
 * again, spreading the load rather than insisting on being served now.
 */
function onPosterError(): void {
	if (posterAttempt.value >= 4) {
		posterFailed.value = true
		return
	}
	const attempt = posterAttempt.value + 1
	// Staggered, so a screenful of cards does not all come back at once.
	const delay = 3000 * attempt + Math.random() * 2000
	window.setTimeout(() => {
		posterAttempt.value = attempt
	}, delay)
}
const loopSrc = computed(() => (props.token
	? publicLoopUrl(props.token, props.item.fileId)
	: loopUrl(props.item.fileId)))

const cardStyle = computed(() => ({
	// Portrait clips get a taller frame rather than being cropped to a letterbox.
	'--card-ratio': props.item.width > 0 && props.item.height > props.item.width ? '0.75' : '1.7778',
}))

const progressPercent = computed(() => {
	const progress = props.item.progress
	if (!progress || progress.finished) {
		return 0
	}
	return Math.min(100, Math.max(1, progress.percent))
})

const badge = computed(() => {
	if (props.item.hdr) {
		return 'HDR'
	}
	const height = Math.max(props.item.width, props.item.height)
	if (height >= 3800) {
		return '4K'
	}
	if (height >= 1900) {
		return '1080'
	}
	return ''
})

const subtitle = computed(() => {
	const parts: string[] = []
	if (props.item.takenAt) {
		parts.push(new Date(props.item.takenAt * 1000).toLocaleDateString(undefined, {
			year: 'numeric',
			month: 'short',
			day: 'numeric',
		}))
	}
	if (props.item.vcodec) {
		parts.push(props.item.vcodec.toUpperCase())
	}
	return parts.join(' · ')
})

function clock(seconds: number): string {
	const hours = Math.floor(seconds / 3600)
	const minutes = Math.floor((seconds % 3600) / 60)
	const rest = Math.floor(seconds % 60)
	return hours > 0
		? `${hours}:${String(minutes).padStart(2, '0')}:${String(rest).padStart(2, '0')}`
		: `${minutes}:${String(rest).padStart(2, '0')}`
}

function onEnter(): void {
	hovering.value = true
	if (!props.previewsEnabled || loopFailed.value) {
		return
	}
	// A short wait, so sweeping the pointer across a row does not ask the server
	// for twenty clips nobody wanted to see.
	window.clearTimeout(timer)
	timer = window.setTimeout(() => {
		showLoop.value = true
		window.requestAnimationFrame(() => {
			loop.value?.play().catch(() => {
				// Autoplay refused, or the clip is not ready. The picture stays.
			})
		})
	}, 320)
}

function onLeave(): void {
	window.clearTimeout(timer)
	hovering.value = false
	showLoop.value = false
	loopReady.value = false
}

onBeforeUnmount(() => window.clearTimeout(timer))
</script>

<style scoped>
.card {
	position: relative;
	flex: 0 0 auto;
	width: var(--card-width, 260px);
	cursor: pointer;
	border-radius: 10px;
	outline: none;
	transition: transform 180ms ease, z-index 0s;
}

.card--wide {
	width: var(--card-width-wide, 380px);
}

.card:focus-visible .card__frame {
	box-shadow: 0 0 0 3px var(--color-primary-element, #0082c9);
}

.card__frame {
	position: relative;
	aspect-ratio: var(--card-ratio, 1.7778);
	overflow: hidden;
	border-radius: 10px;
	background: #14161a;
	box-shadow: 0 2px 10px rgb(0 0 0 / 45%);
	transition: transform 200ms ease, box-shadow 200ms ease;
}

.card--active .card__frame {
	transform: scale(1.06);
	box-shadow: 0 14px 34px rgb(0 0 0 / 65%);
	z-index: 3;
}

.card__poster,
.card__loop {
	position: absolute;
	inset: 0;
	width: 100%;
	height: 100%;
	object-fit: cover;
	display: block;
}

.card__poster--missing {
	display: flex;
	align-items: center;
	justify-content: center;
	color: #6b7280;
	font-size: 13px;
	letter-spacing: 0.08em;
	background: linear-gradient(135deg, #1c1f26, #0f1115);
}

.card__loop {
	opacity: 0;
	animation: fade-in 300ms ease forwards;
}

@keyframes fade-in {
	to { opacity: 1; }
}

.card__shade {
	position: absolute;
	inset: 0;
	background: linear-gradient(to top, rgb(0 0 0 / 70%) 0%, rgb(0 0 0 / 0%) 45%);
	pointer-events: none;
}

.card__duration,
.card__badge {
	position: absolute;
	top: 8px;
	padding: 2px 6px;
	border-radius: 4px;
	background: rgb(0 0 0 / 72%);
	color: #fff;
	font-size: 11px;
	font-weight: 600;
	letter-spacing: 0.02em;
	line-height: 1.5;
}

.card__duration {
	right: 8px;
}

.card__badge {
	left: 8px;
	background: rgb(229 9 20 / 90%);
}

.card__progress {
	position: absolute;
	left: 8px;
	right: 8px;
	bottom: 8px;
	height: 3px;
	border-radius: 2px;
	background: rgb(255 255 255 / 30%);
	overflow: hidden;
}

.card__progress-bar {
	height: 100%;
	background: #e50914;
}

.card__play {
	position: absolute;
	inset: 0;
	margin: auto;
	width: 52px;
	height: 52px;
	display: grid;
	place-items: center;
	border: none;
	border-radius: 50%;
	background: rgb(0 0 0 / 55%);
	color: #fff;
	opacity: 0;
	transform: scale(0.85);
	transition: opacity 160ms ease, transform 160ms ease;
	pointer-events: none;
}

.card--active .card__play {
	opacity: 1;
	transform: scale(1);
}

.card__share {
	position: absolute;
	right: 8px;
	bottom: 8px;
	display: grid;
	place-items: center;
	width: 30px;
	height: 30px;
	border: none;
	border-radius: 50%;
	background: rgb(0 0 0 / 62%);
	color: #fff;
	opacity: 0;
	transform: translateY(4px);
	transition: opacity 150ms ease, transform 150ms ease, background 120ms ease;
	cursor: pointer;
}

.card--active .card__share {
	opacity: 1;
	transform: translateY(0);
}

.card__share:hover {
	background: #e50914;
}

.card__meta {
	display: flex;
	flex-direction: column;
	gap: 1px;
	padding: 8px 2px 0;
	min-width: 0;
}

.card__title {
	font-size: 13px;
	font-weight: 600;
	color: var(--color-main-text, #fff);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.card__sub {
	font-size: 11px;
	color: var(--color-text-maxcontrast, #9aa0a6);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

@media (max-width: 1024px) {
	.card {
		width: var(--card-width-medium, 210px);
	}
}

@media (max-width: 700px) {
	.card {
		width: var(--card-width-small, 168px);
	}

	.card--wide {
		width: var(--card-width-small, 220px);
	}

	/* There is no pointer to hover with, so the share button is always there
	   and the card does not grow under a finger. */
	.card--active .card__frame {
		transform: none;
	}

	.card__share {
		opacity: 1;
		transform: none;
		width: 36px;
		height: 36px;
	}
}
</style>
