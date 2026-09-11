<!--
  - SPDX-FileCopyrightText: 2026 Cristian Casapu
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="meta" role="dialog" aria-modal="true" @click.self="$emit('close')">
		<form class="meta__panel" @submit.prevent="save">
			<header class="meta__head">
				<h2 class="meta__title">{{ t('videogallery', 'Edit details') }}</h2>
				<button type="button" class="meta__close" :aria-label="t('videogallery', 'Close')" @click="$emit('close')">
					<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
						<path fill="currentColor" d="M19 6.4 17.6 5 12 10.6 6.4 5 5 6.4 10.6 12 5 17.6 6.4 19 12 13.4 17.6 19 19 17.6 13.4 12z" />
					</svg>
				</button>
			</header>

			<p class="meta__lead">
				{{ t('videogallery', 'What you write here is kept beside the file rather than inside it, so reading the file again never undoes it. The file itself is only touched if you ask for it to be renamed.') }}
			</p>

			<label class="meta__field">
				<span>{{ t('videogallery', 'Title') }}</span>
				<input v-model="title" type="text" maxlength="250" :placeholder="withoutExtension(item.fileName)">
			</label>

			<label class="meta__check">
				<input v-model="rename" type="checkbox">
				<span>{{ t('videogallery', 'Rename the file as well, keeping its extension') }}</span>
			</label>
			<p v-if="rename" class="meta__hint">{{ t('videogallery', 'It will become {name}', { name: renamedTo }) }}</p>

			<label class="meta__field">
				<span>{{ t('videogallery', 'Description') }}</span>
				<textarea v-model="description" rows="3" maxlength="4000" :placeholder="t('videogallery', 'Anything worth remembering about this')" />
			</label>

			<label class="meta__field">
				<span>
					{{ t('videogallery', 'Date it was recorded') }}
					<em v-if="item.dateLocked">{{ t('videogallery', 'set by hand') }}</em>
					<em v-else>{{ dateSourceLabel }}</em>
				</span>
				<input v-model="takenAt" type="datetime-local">
			</label>
			<p class="meta__hint">
				{{ t('videogallery', 'This is what the timeline sorts by. A camera with a dead clock, or a copy that lost its dates, will have got this wrong.') }}
			</p>
			<label v-if="item.dateLocked" class="meta__check">
				<input v-model="resetDate" type="checkbox">
				<span>{{ t('videogallery', 'Go back to the date in the file') }}</span>
			</label>

			<p v-if="problem" class="meta__problem">{{ problem }}</p>

			<footer class="meta__actions">
				<button type="button" class="meta__button" @click="$emit('close')">{{ t('videogallery', 'Cancel') }}</button>
				<button type="submit" class="meta__button meta__button--primary" :disabled="busy">
					{{ busy ? t('videogallery', 'Saving…') : t('videogallery', 'Save') }}
				</button>
			</footer>
		</form>
	</div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import { saveMetadata } from '../api'
import type { VideoItem } from '../types'

const props = defineProps<{ item: VideoItem }>()
const emit = defineEmits<{ close: [], saved: [item: VideoItem] }>()

const title = ref(props.item.customTitle ?? '')
const description = ref(props.item.description ?? '')
const rename = ref(false)
const resetDate = ref(false)
const busy = ref(false)
const problem = ref('')

/** A datetime-local field wants local time without a zone on the end. */
const takenAt = ref(toLocalInput(props.item.takenAt))

function toLocalInput(timestamp: number): string {
	if (!timestamp) {
		return ''
	}
	const date = new Date(timestamp * 1000)
	const pad = (n: number) => String(n).padStart(2, '0')
	return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

function withoutExtension(name: string): string {
	const dot = name.lastIndexOf('.')
	return dot > 0 ? name.slice(0, dot) : name
}

const renamedTo = computed(() => {
	const extension = props.item.fileName.includes('.') ? props.item.fileName.split('.').pop() : ''
	const stem = (title.value || withoutExtension(props.item.fileName)).replace(/[/\\]/g, '')
	return extension ? `${stem}.${extension}` : stem
})

const dateSourceLabel = computed(() => {
	switch (props.item.dateSource) {
	case 'metadata':
		return t('videogallery', 'from the file itself')
	case 'filename':
		return t('videogallery', 'from the file name')
	default:
		return t('videogallery', 'when the file arrived')
	}
})

async function save(): Promise<void> {
	busy.value = true
	problem.value = ''
	try {
		const seconds = takenAt.value ? Math.floor(new Date(takenAt.value).getTime() / 1000) : 0
		const { item } = await saveMetadata(props.item.fileId, {
			title: title.value.trim(),
			description: description.value.trim(),
			takenAt: resetDate.value ? 0 : seconds,
			rename: rename.value,
			resetDate: resetDate.value,
		})
		emit('saved', item)
		emit('close')
	} catch (e: unknown) {
		const response = (e as { response?: { data?: { ocs?: { data?: { message?: string } } } } }).response
		problem.value = response?.data?.ocs?.data?.message ?? t('videogallery', 'That could not be saved.')
	} finally {
		busy.value = false
	}
}
</script>

<style scoped>
.meta {
	position: fixed;
	inset: 0;
	z-index: 9600;
	display: grid;
	place-items: center;
	padding: 20px;
	background: rgb(0 0 0 / 70%);
}

.meta__panel {
	width: min(520px, 100%);
	max-height: 88vh;
	overflow-y: auto;
	padding: 22px 24px 20px;
	border-radius: 14px;
	background: #16181d;
	color: #e9ecef;
	box-shadow: 0 24px 70px rgb(0 0 0 / 70%);
}

.meta__head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 10px;
}

.meta__title {
	margin: 0;
	font-size: 19px;
}

.meta__close {
	display: grid;
	place-items: center;
	width: 32px;
	height: 32px;
	border: none;
	border-radius: 8px;
	background: transparent;
	color: #cfd3d8;
	cursor: pointer;
}

.meta__close:hover {
	background: rgb(255 255 255 / 12%);
}

.meta__close svg {
	display: block;
}

.meta__lead,
.meta__hint {
	margin: 0 0 14px;
	font-size: 12px;
	line-height: 1.5;
	color: #99a0a8;
}

.meta__hint {
	margin: -6px 0 14px;
	font-size: 11px;
}

.meta__field {
	display: flex;
	flex-direction: column;
	gap: 5px;
	margin-bottom: 14px;
	font-size: 13px;
}

.meta__field span em {
	margin-inline-start: 6px;
	font-style: normal;
	font-size: 11px;
	color: #8d949d;
}

.meta__field input,
.meta__field textarea {
	width: 100%;
	padding: 9px 11px;
	border: 1px solid rgb(255 255 255 / 16%);
	border-radius: 8px;
	background: rgb(0 0 0 / 35%);
	color: #e9ecef;
	font-size: 14px;
	font-family: inherit;
	resize: vertical;
}

.meta__check {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 14px;
	font-size: 13px;
	color: #cfd3d8;
	cursor: pointer;
}

.meta__problem {
	margin: 0 0 12px;
	font-size: 13px;
	color: #f0a3a3;
}

.meta__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}

.meta__button {
	padding: 9px 18px;
	border: 1px solid rgb(255 255 255 / 18%);
	border-radius: 8px;
	background: rgb(255 255 255 / 8%);
	color: #fff;
	font-size: 14px;
	cursor: pointer;
}

.meta__button--primary {
	background: #fff;
	border-color: transparent;
	color: #111;
	font-weight: 700;
}

.meta__button:disabled {
	opacity: 0.6;
	cursor: default;
}

@media (max-width: 600px) {
	.meta {
		padding: 0;
		place-items: end stretch;
	}

	.meta__panel {
		width: 100%;
		max-height: 94vh;
		border-radius: 16px 16px 0 0;
		padding: 18px 16px calc(16px + env(safe-area-inset-bottom));
	}

	.meta__field input,
	.meta__field textarea {
		/* Anything under sixteen pixels makes Safari zoom the page on focus. */
		font-size: 16px;
		min-height: 44px;
	}

	.meta__actions {
		position: sticky;
		bottom: 0;
		padding-top: 10px;
		background: #16181d;
	}

	.meta__button {
		flex: 1 1 auto;
		min-height: 46px;
	}
}
</style>