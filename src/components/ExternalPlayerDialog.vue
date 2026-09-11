<!--
  - SPDX-FileCopyrightText: 2026 Cristian Casapu
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="sheet" role="dialog" aria-modal="true" @click.self="$emit('close')">
		<div class="sheet__panel">
			<h3 class="sheet__title">{{ t('videogallery', 'Open in a player on this device') }}</h3>
			<p class="sheet__lead">
				{{ t('videogallery', 'This hands the original file to a player installed on the device — nothing is converted, and the picture and sound arrive exactly as they were recorded. It is the right choice for a television, or for a file this browser will not open.') }}
			</p>

			<div v-if="loading" class="sheet__loading">{{ t('videogallery', 'Preparing a link…') }}</div>
			<p v-else-if="failed" class="sheet__error">{{ failed }}</p>

			<template v-else-if="links">
				<div class="sheet__grid">
					<a class="sheet__option" :href="links.playlist">
						<strong>{{ t('videogallery', 'Playlist file') }}</strong>
						<span>{{ t('videogallery', 'Works everywhere. Downloads a small .m3u that opens in whichever player the device prefers.') }}</span>
					</a>
					<a class="sheet__option" :href="links.vlc">
						<strong>VLC {{ t('videogallery', 'on a computer') }}</strong>
						<span>{{ t('videogallery', 'Opens VLC straight away, if it is installed.') }}</span>
					</a>
					<a class="sheet__option" :href="links.android">
						<strong>VLC {{ t('videogallery', 'on Android') }}</strong>
						<span>{{ t('videogallery', 'Hands the stream to VLC for Android.') }}</span>
					</a>
					<a class="sheet__option" :href="links.infuse">
						<strong>Infuse {{ t('videogallery', 'on iPhone, iPad, Apple TV') }}</strong>
						<span>{{ t('videogallery', 'Also try the VLC link below if Infuse is not installed.') }}</span>
					</a>
					<a class="sheet__option" :href="links.vlcios">
						<strong>VLC {{ t('videogallery', 'on iPhone and iPad') }}</strong>
						<span>{{ t('videogallery', 'Opens the stream in VLC for iOS.') }}</span>
					</a>
				</div>

				<label class="sheet__copy">
					<span>{{ t('videogallery', 'Or paste this address into any player') }}</span>
					<span class="sheet__copy-row">
						<input ref="field" type="text" readonly :value="links.direct" @focus="($event.target as HTMLInputElement).select()">
						<button @click="copy">{{ copied ? t('videogallery', 'Copied') : t('videogallery', 'Copy') }}</button>
					</span>
				</label>

				<p class="sheet__note">{{ expiryNote }}</p>
			</template>

			<div class="sheet__actions">
				<button class="sheet__close" @click="$emit('close')">{{ t('videogallery', 'Close') }}</button>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import { externalLinks } from '../api'
import type { ExternalLinks, VideoItem } from '../types'

const props = defineProps<{ item: VideoItem }>()
defineEmits<{ close: [] }>()

const links = ref<ExternalLinks | null>(null)
const loading = ref(true)
const failed = ref('')
const copied = ref(false)
const field = ref<HTMLInputElement | null>(null)

const expiryNote = computed(() => {
	if (!links.value) {
		return ''
	}
	const expires = new Date(Number(links.value.expires) * 1000)
	return t('videogallery', 'The link carries its own permission and stops working at {time}.', {
		time: expires.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }),
	})
})

async function copy(): Promise<void> {
	if (!links.value) {
		return
	}
	try {
		await navigator.clipboard.writeText(links.value.direct)
		copied.value = true
		window.setTimeout(() => { copied.value = false }, 2000)
	} catch {
		// Clipboard refused; the field is selectable by hand.
		field.value?.select()
	}
}

onMounted(async () => {
	try {
		links.value = await externalLinks(props.item.fileId)
	} catch {
		failed.value = t('videogallery', 'A link could not be prepared. The administrator may have switched this off.')
	} finally {
		loading.value = false
	}
})
</script>

<style scoped>
.sheet {
	position: fixed;
	inset: 0;
	z-index: 20;
	display: grid;
	place-items: center;
	padding: 20px;
	background: rgb(0 0 0 / 68%);
}

.sheet__panel {
	width: min(620px, 100%);
	max-height: 86vh;
	overflow-y: auto;
	padding: 24px;
	border-radius: 14px;
	background: #16181d;
	color: #e9ecef;
	box-shadow: 0 24px 70px rgb(0 0 0 / 70%);
}

.sheet__title {
	margin: 0 0 8px;
	font-size: 19px;
}

.sheet__lead {
	margin: 0 0 18px;
	font-size: 13px;
	line-height: 1.55;
	color: #a8aeb6;
}

.sheet__loading,
.sheet__error {
	padding: 18px 0;
	font-size: 14px;
	color: #a8aeb6;
}

.sheet__grid {
	display: grid;
	gap: 8px;
	grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
}

.sheet__option {
	display: flex;
	flex-direction: column;
	gap: 3px;
	padding: 12px 14px;
	border: 1px solid rgb(255 255 255 / 12%);
	border-radius: 9px;
	color: inherit;
	text-decoration: none;
	transition: background 130ms ease, border-color 130ms ease;
}

.sheet__option:hover {
	background: rgb(255 255 255 / 7%);
	border-color: rgb(255 255 255 / 26%);
}

.sheet__option strong {
	font-size: 14px;
}

.sheet__option span {
	font-size: 12px;
	color: #99a0a8;
	line-height: 1.45;
}

.sheet__copy {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-top: 18px;
	font-size: 12px;
	color: #99a0a8;
}

.sheet__copy-row {
	display: flex;
	gap: 6px;
}

.sheet__copy input {
	flex: 1;
	min-width: 0;
	padding: 9px 11px;
	border: 1px solid rgb(255 255 255 / 16%);
	border-radius: 7px;
	background: rgb(0 0 0 / 35%);
	color: #e9ecef;
	font-size: 12px;
	font-family: monospace;
}

.sheet__copy button,
.sheet__close {
	padding: 9px 16px;
	border: 1px solid rgb(255 255 255 / 18%);
	border-radius: 7px;
	background: rgb(255 255 255 / 8%);
	color: #fff;
	font-size: 13px;
	cursor: pointer;
}

.sheet__note {
	margin: 14px 0 0;
	font-size: 12px;
	color: #7f868e;
}

.sheet__actions {
	display: flex;
	justify-content: flex-end;
	margin-top: 18px;
}

@media (max-width: 600px) {
	.sheet {
		padding: 0;
		place-items: end stretch;
	}

	.sheet__panel {
		max-height: 92vh;
		border-radius: 16px 16px 0 0;
		padding: 18px 16px calc(18px + env(safe-area-inset-bottom));
	}

	.sheet__grid {
		grid-template-columns: 1fr;
	}

	.sheet__option {
		min-height: 56px;
	}

	.sheet__copy-row {
		flex-wrap: wrap;
	}

	.sheet__copy input {
		flex: 1 0 100%;
		font-size: 16px;
	}

	.sheet__copy button,
	.sheet__close {
		min-height: 44px;
	}
}
</style>