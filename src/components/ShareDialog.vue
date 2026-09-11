<!--
  - SPDX-FileCopyrightText: 2026 Cristian Casapu
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="share" role="dialog" aria-modal="true" @click.self="$emit('close')">
		<div class="share__panel">
			<header class="share__head">
				<div>
					<h2 class="share__title">{{ t('videogallery', 'Share') }}</h2>
					<p class="share__subject">{{ subject }}</p>
				</div>
				<button class="share__close" :aria-label="t('videogallery', 'Close')" @click="$emit('close')">
					<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
						<path fill="currentColor" d="M19 6.4 17.6 5 12 10.6 6.4 5 5 6.4 10.6 12 5 17.6 6.4 19 12 13.4 17.6 19 19 17.6 13.4 12z" />
					</svg>
				</button>
			</header>

			<p v-if="loading" class="share__note">{{ t('videogallery', 'Loading…') }}</p>
			<p v-else-if="!state?.enabled" class="share__note">{{ t('videogallery', 'Sharing is switched off for this app.') }}</p>

			<template v-else>
				<!-- People and groups -->
				<section class="share__section">
					<h3>{{ t('videogallery', 'People and groups') }}</h3>
					<div class="share__search">
						<input v-model="search"
							type="search"
							:placeholder="t('videogallery', 'Name of a person or a group')"
							@input="onSearch">
						<ul v-if="matches.length" class="share__matches">
							<li v-for="match in matches" :key="match.type + ':' + match.id">
								<button @click="shareWith(match)">
									<span class="share__match-label">{{ match.label }}</span>
									<span class="share__match-type">{{ match.type === 1 ? t('videogallery', 'group') : t('videogallery', 'person') }}</span>
								</button>
							</li>
						</ul>
					</div>

					<ul v-if="peopleShares.length" class="share__list">
						<li v-for="share in peopleShares" :key="share.id" class="share__row">
							<span class="share__who">
								{{ share.withDisplayName || share.with }}
								<em v-if="share.type === 1">{{ t('videogallery', 'group') }}</em>
							</span>
							<label class="share__toggle">
								<input type="checkbox" :checked="share.canDownload" @change="setDownload(share, ($event.target as HTMLInputElement).checked)">
								<span>{{ t('videogallery', 'May download') }}</span>
							</label>
							<button class="share__remove" :aria-label="t('videogallery', 'Stop sharing')" @click="remove(share)">
								<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
									<path fill="currentColor" d="M6 19a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7H6zM19 4h-3.5l-1-1h-5l-1 1H5v2h14z" />
								</svg>
							</button>
						</li>
					</ul>
				</section>

				<!-- Links -->
				<section v-if="state.linksAllowed" class="share__section">
					<h3>{{ t('videogallery', 'Links') }}</h3>
					<p class="share__hint">
						{{ isFolder
							? t('videogallery', 'Anyone with the link can browse this folder and watch anything in it.')
							: t('videogallery', 'Anyone with the link can watch this video.') }}
					</p>

					<button class="share__add" :disabled="busy" @click="createLink">
						<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
							<path fill="currentColor" d="M11 13H7a1 1 0 0 1 0-2h4V7a1 1 0 0 1 2 0v4h4a1 1 0 0 1 0 2h-4v4a1 1 0 0 1-2 0z" />
						</svg>
						{{ t('videogallery', 'Create a link') }}
					</button>

					<div v-for="share in linkShares" :key="share.id" class="share__link">
						<div class="share__link-row">
							<input type="text" readonly :value="share.shortUrl || share.url || ''" @focus="($event.target as HTMLInputElement).select()">
							<button @click="copy(share.shortUrl || share.url || '')">
								{{ copied === (share.shortUrl || share.url) ? t('videogallery', 'Copied') : t('videogallery', 'Copy') }}
							</button>
							<button class="share__remove" :aria-label="t('videogallery', 'Remove this link')" @click="remove(share)">
								<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
									<path fill="currentColor" d="M6 19a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7H6zM19 4h-3.5l-1-1h-5l-1 1H5v2h14z" />
								</svg>
							</button>
						</div>

						<div class="share__options">
							<label class="share__toggle">
								<input type="checkbox" :checked="share.canDownload" @change="setDownload(share, ($event.target as HTMLInputElement).checked)">
								<span>{{ t('videogallery', 'May download the file') }}</span>
							</label>
							<label class="share__toggle">
								<input type="checkbox" :checked="share.hasPassword" @change="togglePassword(share, ($event.target as HTMLInputElement).checked)">
								<span>{{ t('videogallery', 'Ask for a password') }}</span>
							</label>
							<label class="share__toggle share__toggle--date">
								<span>{{ t('videogallery', 'Stops working on') }}</span>
								<input type="date"
									:value="share.expires ? new Date(share.expires * 1000).toISOString().slice(0, 10) : ''"
									@change="setExpiry(share, ($event.target as HTMLInputElement).value)">
							</label>
						</div>

						<p v-if="!share.canDownload" class="share__hint share__hint--small">
							{{ t('videogallery', 'The file itself will not be sent, and no link to an outside player is offered. Watching still works, because a stream is not a copy — though anything that can be watched can be recorded.') }}
						</p>

						<div v-if="share.shortUrl" class="share__long">
							<span class="share__long-label">{{ t('videogallery', 'Long address') }}</span>
							<input type="text" readonly :value="share.url || ''" @focus="($event.target as HTMLInputElement).select()">
							<button @click="copy(share.url || '')">
								{{ copied === share.url ? t('videogallery', 'Copied') : t('videogallery', 'Copy') }}
							</button>
						</div>
						<button v-else-if="state.shortLinks" class="share__short" :disabled="shortening === share.id" @click="makeShort(share)">
							{{ shortening === share.id ? t('videogallery', 'Shortening…') : t('videogallery', 'Make a short link') }}
						</button>
					</div>
				</section>

				<p v-if="problem" class="share__problem">{{ problem }}</p>
			</template>
		</div>
	</div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import {
	createShare,
	deleteShare,
	fetchShares,
	searchSharees,
	shortLink,
	updateShare,
	type GalleryShare,
	type ShareState,
} from '../api'

const props = defineProps<{ fileId: number, name: string, isFolder?: boolean }>()
defineEmits<{ close: [] }>()

const state = ref<ShareState | null>(null)
const loading = ref(true)
const busy = ref(false)
const problem = ref('')
const search = ref('')
const matches = ref<Array<{ id: string, label: string, type: number }>>([])
const copied = ref('')
const shortening = ref('')
let searchTimer: number | undefined

const isFolder = computed(() => props.isFolder ?? state.value?.isFolder ?? false)
const subject = computed(() => (isFolder.value ? t('videogallery', 'Folder: {name}', { name: props.name }) : props.name))
const peopleShares = computed(() => (state.value?.shares ?? []).filter((s) => s.type === 0 || s.type === 1))
const linkShares = computed(() => (state.value?.shares ?? []).filter((s) => s.type === 3 || s.type === 4))

async function load(): Promise<void> {
	loading.value = true
	try {
		state.value = await fetchShares(props.fileId)
		await fillInShortLinks()
	} catch {
		problem.value = t('videogallery', 'What is already shared could not be read.')
	} finally {
		loading.value = false
	}
}

/**
 * Give every link its short address without being asked.
 *
 * A short link that has to be requested is a short link nobody uses. The
 * addresses are what this dialog exists to hand over, so both are put in front
 * of the person straight away — the short one first, since that is the one that
 * can be read out loud.
 */
async function fillInShortLinks(): Promise<void> {
	if (!state.value?.shortLinks) {
		return
	}
	await Promise.all(state.value.shares
		.filter((share) => share.type === 3 || share.type === 4)
		.map(async (share) => {
			try {
				const result = await shortLink(share.id)
				if (result.short) {
					share.shortUrl = result.short
				}
			} catch {
				// The short links app may be unwilling; the long address stands.
			}
		}))
}

function onSearch(): void {
	window.clearTimeout(searchTimer)
	searchTimer = window.setTimeout(async () => {
		const term = search.value.trim()
		if (term.length < 2) {
			matches.value = []
			return
		}
		try {
			matches.value = await searchSharees(term)
		} catch {
			matches.value = []
		}
	}, 280)
}

async function shareWith(match: { id: string, label: string, type: number }): Promise<void> {
	await run(async () => {
		await createShare({ path: state.value!.path, shareType: match.type, shareWith: match.id })
		search.value = ''
		matches.value = []
	})
}

async function createLink(): Promise<void> {
	await run(() => createShare({ path: state.value!.path, shareType: 3 }))
}

async function setDownload(share: GalleryShare, allowed: boolean): Promise<void> {
	await run(() => updateShare(share.id, { canDownload: allowed }))
}

async function togglePassword(share: GalleryShare, wanted: boolean): Promise<void> {
	if (!wanted) {
		await run(() => updateShare(share.id, { password: null }))
		return
	}
	const password = window.prompt(t('videogallery', 'Password for this link'))
	if (!password) {
		await load()
		return
	}
	await run(() => updateShare(share.id, { password }))
}

async function setExpiry(share: GalleryShare, date: string): Promise<void> {
	await run(() => updateShare(share.id, { expireDate: date || null }))
}

async function remove(share: GalleryShare): Promise<void> {
	if (!window.confirm(t('videogallery', 'Stop sharing this?'))) {
		return
	}
	await run(() => deleteShare(share.id))
}

async function makeShort(share: GalleryShare): Promise<void> {
	shortening.value = share.id
	try {
		const result = await shortLink(share.id)
		if (result.short) {
			share.shortUrl = result.short
		}
	} catch {
		problem.value = t('videogallery', 'A short link could not be made.')
	} finally {
		shortening.value = ''
	}
}

async function copy(value: string): Promise<void> {
	try {
		await navigator.clipboard.writeText(value)
		copied.value = value
		window.setTimeout(() => { copied.value = '' }, 2000)
	} catch {
		// The field is selectable by hand.
	}
}

/** Do something, then reload, and say plainly when the server refuses. */
async function run(work: () => Promise<unknown>): Promise<void> {
	busy.value = true
	problem.value = ''
	try {
		await work()
		await load()
	} catch (e: unknown) {
		const response = (e as { response?: { data?: { ocs?: { meta?: { message?: string } } } } }).response
		problem.value = response?.data?.ocs?.meta?.message ?? t('videogallery', 'That did not work.')
	} finally {
		busy.value = false
	}
}

onMounted(load)
</script>

<style scoped>
.share {
	position: fixed;
	inset: 0;
	z-index: 9500;
	display: grid;
	place-items: center;
	padding: 20px;
	background: rgb(0 0 0 / 70%);
}

.share__panel {
	width: min(560px, 100%);
	max-height: 88vh;
	overflow-y: auto;
	padding: 22px 24px 26px;
	border-radius: 14px;
	background: #16181d;
	color: #e9ecef;
	box-shadow: 0 24px 70px rgb(0 0 0 / 70%);
}

.share__head {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 16px;
}

.share__title {
	margin: 0;
	font-size: 19px;
}

.share__subject {
	margin: 3px 0 0;
	font-size: 13px;
	color: #99a0a8;
	word-break: break-word;
}

.share__panel svg {
	/* An inline SVG sits on the text baseline, which leaves it a pixel or two
	   high and left of the middle of any box it is centred in. */
	display: block;
}

.share__close,
.share__remove {
	display: grid;
	place-items: center;
	width: 32px;
	height: 32px;
	flex: 0 0 32px;
	border: none;
	border-radius: 8px;
	background: transparent;
	color: #cfd3d8;
	cursor: pointer;
}

.share__close:hover,
.share__remove:hover {
	background: rgb(255 255 255 / 12%);
}

.share__section {
	margin-bottom: 22px;
}

.share__section h3 {
	margin: 0 0 8px;
	font-size: 13px;
	font-weight: 700;
	letter-spacing: 0.08em;
	text-transform: uppercase;
	color: #8d949d;
}

.share__hint {
	margin: 0 0 10px;
	font-size: 12px;
	line-height: 1.5;
	color: #99a0a8;
}

.share__hint--small {
	margin: 6px 0 0;
	font-size: 11px;
}

.share__note,
.share__problem {
	padding: 10px 0;
	font-size: 13px;
	color: #99a0a8;
}

.share__problem {
	color: #f0a3a3;
}

.share__search {
	position: relative;
}

.share__search input,
.share__link-row input {
	width: 100%;
	padding: 9px 11px;
	border: 1px solid rgb(255 255 255 / 16%);
	border-radius: 8px;
	background: rgb(0 0 0 / 35%);
	color: #e9ecef;
	font-size: 13px;
}

.share__matches {
	position: absolute;
	z-index: 2;
	left: 0;
	right: 0;
	margin: 4px 0 0;
	padding: 4px;
	list-style: none;
	border-radius: 8px;
	background: #1e2128;
	box-shadow: 0 12px 30px rgb(0 0 0 / 60%);
	max-height: 220px;
	overflow-y: auto;
}

.share__matches button {
	display: flex;
	justify-content: space-between;
	gap: 10px;
	width: 100%;
	padding: 8px 10px;
	border: none;
	border-radius: 6px;
	background: transparent;
	color: #e9ecef;
	font-size: 13px;
	text-align: start;
	cursor: pointer;
}

.share__matches button:hover {
	background: rgb(255 255 255 / 10%);
}

.share__match-type {
	color: #8d949d;
	font-size: 11px;
}

.share__list {
	list-style: none;
	margin: 12px 0 0;
	padding: 0;
}

.share__row {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 8px 0;
	border-top: 1px solid rgb(255 255 255 / 8%);
}

.share__who {
	flex: 1;
	font-size: 14px;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.share__who em {
	margin-inline-start: 6px;
	font-size: 11px;
	color: #8d949d;
	font-style: normal;
}

.share__toggle {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	font-size: 12px;
	color: #cfd3d8;
	cursor: pointer;
	white-space: nowrap;
}

.share__toggle--date input {
	padding: 4px 6px;
	border: 1px solid rgb(255 255 255 / 16%);
	border-radius: 6px;
	background: rgb(0 0 0 / 35%);
	color: #e9ecef;
	font-size: 12px;
}

.share__add,
.share__short {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 8px 14px;
	margin-bottom: 10px;
	border: 1px solid rgb(255 255 255 / 18%);
	border-radius: 8px;
	background: rgb(255 255 255 / 6%);
	color: #fff;
	font-size: 13px;
	cursor: pointer;
}

.share__add:hover,
.share__short:hover {
	background: rgb(255 255 255 / 14%);
}

.share__short {
	margin: 8px 0 0;
	font-size: 12px;
	padding: 6px 11px;
}

.share__link {
	padding: 12px 0;
	border-top: 1px solid rgb(255 255 255 / 8%);
}

.share__link-row {
	display: flex;
	gap: 6px;
	align-items: center;
}

.share__link-row button {
	padding: 9px 13px;
	border: 1px solid rgb(255 255 255 / 18%);
	border-radius: 8px;
	background: rgb(255 255 255 / 8%);
	color: #fff;
	font-size: 13px;
	cursor: pointer;
	white-space: nowrap;
}

.share__long {
	display: flex;
	align-items: center;
	gap: 6px;
	margin-top: 8px;
}

.share__long-label {
	font-size: 11px;
	color: #8d949d;
	white-space: nowrap;
}

.share__long input {
	flex: 1;
	min-width: 0;
	padding: 6px 9px;
	border: 1px solid rgb(255 255 255 / 12%);
	border-radius: 7px;
	background: rgb(0 0 0 / 30%);
	color: #99a0a8;
	font-size: 11px;
}

.share__long button {
	padding: 6px 11px;
	border: 1px solid rgb(255 255 255 / 14%);
	border-radius: 7px;
	background: transparent;
	color: #cfd3d8;
	font-size: 11px;
	cursor: pointer;
}

.share__options {
	display: flex;
	flex-wrap: wrap;
	gap: 8px 16px;
	margin-top: 10px;
}

/*
 * On a phone a dialog in the middle of the screen is a small window into a
 * form; it works far better as a sheet that comes up from the bottom and uses
 * the whole width, with room enough to hit things with a thumb.
 */
@media (max-width: 600px) {
	.share {
		padding: 0;
		place-items: end stretch;
	}

	.share__panel {
		width: 100%;
		max-height: 92vh;
		border-radius: 16px 16px 0 0;
		padding: 18px 16px calc(18px + env(safe-area-inset-bottom));
	}

	.share__row {
		flex-wrap: wrap;
		row-gap: 6px;
	}

	.share__who {
		flex: 1 0 100%;
	}

	.share__link-row {
		flex-wrap: wrap;
	}

	.share__link-row input {
		flex: 1 0 100%;
	}

	.share__long {
		flex-wrap: wrap;
	}

	.share__long input {
		flex: 1 0 100%;
	}

	.share__options {
		flex-direction: column;
		gap: 10px;
	}

	/* Comfortably hittable without aiming. */
	.share__close,
	.share__remove {
		width: 44px;
		height: 44px;
		flex-basis: 44px;
	}

	.share__link-row button,
	.share__add,
	.share__short {
		min-height: 44px;
	}
}

@media (min-width: 601px) and (max-width: 1024px) {
	.share__panel {
		width: min(620px, 94vw);
	}
}
</style>
