<!--
  - SPDX-FileCopyrightText: 2026 Cristian Casapu
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<header class="hero">
		<img v-if="item.hasPoster" class="hero__art" :src="poster" alt="" decoding="async">
		<div class="hero__wash" />

		<div class="hero__body">
			<p class="hero__eyebrow">{{ eyebrow }}</p>
			<h1 class="hero__title">{{ item.basename }}</h1>
			<p class="hero__facts">{{ facts }}</p>

			<div class="hero__actions">
				<button class="hero__button hero__button--primary" @click="$emit('play', item)">
					<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
						<path fill="currentColor" d="M8 5v14l11-7z" />
					</svg>
					{{ resumeLabel }}
				</button>
				<button class="hero__button" @click="$emit('info', item)">
					<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
						<path fill="currentColor" d="M11 7h2v2h-2zm0 4h2v6h-2zm1-9a10 10 0 1 0 0 20 10 10 0 0 0 0-20z" />
					</svg>
					{{ t('videogallery', 'Details') }}
				</button>
			</div>
		</div>
	</header>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'
import { posterUrl } from '../api'
import type { VideoItem } from '../types'

const props = defineProps<{ item: VideoItem }>()
defineEmits<{ play: [item: VideoItem], info: [item: VideoItem] }>()

const poster = computed(() => posterUrl(props.item.fileId))

const eyebrow = computed(() => {
	if (props.item.progress && !props.item.progress.finished) {
		return t('videogallery', 'Where you left off')
	}
	return props.item.folder || t('videogallery', 'From your library')
})

const resumeLabel = computed(() => {
	const progress = props.item.progress
	return progress && !progress.finished && progress.position > 30
		? t('videogallery', 'Carry on')
		: t('videogallery', 'Play')
})

const facts = computed(() => {
	const parts: string[] = []
	if (props.item.takenAt) {
		parts.push(new Date(props.item.takenAt * 1000).toLocaleDateString(undefined, {
			year: 'numeric', month: 'long', day: 'numeric',
		}))
	}
	if (props.item.duration) {
		const minutes = Math.round(props.item.duration / 60)
		parts.push(minutes >= 1
			? t('videogallery', '{minutes} min', { minutes })
			: t('videogallery', '{seconds} s', { seconds: props.item.duration }))
	}
	const longest = Math.max(props.item.width, props.item.height)
	if (longest >= 3800) {
		parts.push('4K')
	} else if (longest > 0) {
		parts.push(`${Math.min(props.item.width, props.item.height)}p`)
	}
	if (props.item.vcodec) {
		parts.push(props.item.vcodec.toUpperCase())
	}
	if (props.item.hdr) {
		parts.push('HDR')
	}
	return parts.join('  ·  ')
})
</script>

<style scoped>
.hero {
	position: relative;
	min-height: min(62vh, 520px);
	display: flex;
	align-items: flex-end;
	overflow: hidden;
	border-radius: 0 0 14px 14px;
	background: #0c0d10;
}

.hero__art {
	position: absolute;
	inset: 0;
	width: 100%;
	height: 100%;
	object-fit: cover;
	/* Off centre, so the text sits over the quieter part of the picture. */
	object-position: center 35%;
}

.hero__wash {
	position: absolute;
	inset: 0;
	background:
		linear-gradient(to top, rgb(12 13 16 / 98%) 0%, rgb(12 13 16 / 55%) 45%, rgb(12 13 16 / 10%) 100%),
		linear-gradient(to right, rgb(12 13 16 / 88%) 0%, rgb(12 13 16 / 0%) 65%);
}

.hero__body {
	position: relative;
	max-width: 660px;
	padding: 0 44px 44px;
}

.hero__eyebrow {
	margin: 0 0 6px;
	font-size: 12px;
	font-weight: 700;
	letter-spacing: 0.14em;
	text-transform: uppercase;
	color: #e50914;
}

.hero__title {
	margin: 0 0 10px;
	font-size: clamp(28px, 4.4vw, 52px);
	font-weight: 800;
	line-height: 1.05;
	color: #fff;
	text-shadow: 0 2px 18px rgb(0 0 0 / 60%);
}

.hero__facts {
	margin: 0 0 22px;
	font-size: 14px;
	color: #cfd3d8;
}

.hero__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 10px;
}

.hero__button {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	padding: 11px 22px;
	border: none;
	border-radius: 6px;
	font-size: 15px;
	font-weight: 700;
	cursor: pointer;
	background: rgb(109 109 110 / 70%);
	color: #fff;
	transition: background 140ms ease;
}

.hero__button:hover {
	background: rgb(109 109 110 / 90%);
}

.hero__button--primary {
	background: #fff;
	color: #111;
}

.hero__button--primary:hover {
	background: #e6e6e6;
}

@media (max-width: 700px) {
	.hero {
		min-height: 46vh;
	}

	.hero__body {
		padding: 0 16px 26px;
	}
}
</style>
