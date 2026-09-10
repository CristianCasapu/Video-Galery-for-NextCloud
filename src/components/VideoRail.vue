<!--
  - SPDX-FileCopyrightText: 2026 Cristian Casapu
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="rail">
		<h2 class="rail__title">
			{{ title }}
			<span class="rail__count">{{ items.length }}</span>
		</h2>

		<div class="rail__viewport">
			<button v-show="canScrollLeft"
				class="rail__arrow rail__arrow--left"
				:aria-label="t('videogallery', 'Scroll left')"
				@click="scrollBy(-1)">
				<svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true">
					<path fill="currentColor" d="M15.4 7.4 14 6l-6 6 6 6 1.4-1.4-4.6-4.6z" />
				</svg>
			</button>

			<div ref="track" class="rail__track" @scroll.passive="updateArrows">
				<VideoCard v-for="item in items"
					:key="item.fileId"
					:item="item"
					:previews-enabled="previewsEnabled"
					@play="$emit('play', $event)"
					@share="$emit('share', $event)" />
			</div>

			<button v-show="canScrollRight"
				class="rail__arrow rail__arrow--right"
				:aria-label="t('videogallery', 'Scroll right')"
				@click="scrollBy(1)">
				<svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true">
					<path fill="currentColor" d="m8.6 16.6 1.4 1.4 6-6-6-6-1.4 1.4 4.6 4.6z" />
				</svg>
			</button>
		</div>
	</section>
</template>

<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import VideoCard from './VideoCard.vue'
import type { VideoItem } from '../types'

const props = withDefaults(defineProps<{
	title: string
	items: VideoItem[]
	previewsEnabled?: boolean
}>(), { previewsEnabled: true })

defineEmits<{ play: [item: VideoItem], share: [item: VideoItem] }>()

const track = ref<HTMLElement | null>(null)
const canScrollLeft = ref(false)
const canScrollRight = ref(false)

function updateArrows(): void {
	const element = track.value
	if (!element) {
		return
	}
	canScrollLeft.value = element.scrollLeft > 8
	canScrollRight.value = element.scrollLeft + element.clientWidth < element.scrollWidth - 8
}

/** Move by very nearly a full screen, leaving one card visible as an anchor. */
function scrollBy(direction: number): void {
	const element = track.value
	if (!element) {
		return
	}
	element.scrollBy({ left: direction * (element.clientWidth * 0.86), behavior: 'smooth' })
}

let observer: ResizeObserver | undefined

onMounted(async () => {
	await nextTick()
	updateArrows()
	if (typeof ResizeObserver !== 'undefined' && track.value) {
		observer = new ResizeObserver(updateArrows)
		observer.observe(track.value)
	}
})

onBeforeUnmount(() => observer?.disconnect())
watch(() => props.items, () => nextTick(updateArrows))
</script>

<style scoped>
.rail {
	margin-block: 30px;
}

.rail__title {
	display: flex;
	align-items: baseline;
	gap: 10px;
	margin: 0 0 10px;
	padding-inline: 44px;
	font-size: 17px;
	font-weight: 700;
	color: var(--color-main-text, #fff);
}

.rail__count {
	font-size: 12px;
	font-weight: 500;
	color: var(--color-text-maxcontrast, #8a9099);
}

.rail__viewport {
	position: relative;
}

.rail__track {
	display: flex;
	gap: 12px;
	/* Room for the cards to grow into when the pointer is on one. */
	padding: 12px 44px 20px;
	overflow-x: auto;
	overflow-y: visible;
	scroll-snap-type: x proximity;
	scrollbar-width: none;
}

.rail__track::-webkit-scrollbar {
	display: none;
}

.rail__track > * {
	scroll-snap-align: start;
}

.rail__arrow {
	position: absolute;
	top: 0;
	bottom: 20px;
	z-index: 4;
	width: 40px;
	display: grid;
	place-items: center;
	border: none;
	color: #fff;
	background: linear-gradient(to right, rgb(12 13 16 / 92%), rgb(12 13 16 / 0%));
	cursor: pointer;
	opacity: 0;
	transition: opacity 150ms ease;
}

.rail__viewport:hover .rail__arrow,
.rail__arrow:focus-visible {
	opacity: 1;
}

.rail__arrow--left {
	left: 0;
	border-radius: 0 6px 6px 0;
}

.rail__arrow--right {
	right: 0;
	background: linear-gradient(to left, rgb(12 13 16 / 92%), rgb(12 13 16 / 0%));
	border-radius: 6px 0 0 6px;
}

@media (max-width: 700px) {
	.rail__title,
	.rail__track {
		padding-inline: 16px;
	}

	.rail__arrow {
		display: none;
	}
}
</style>
