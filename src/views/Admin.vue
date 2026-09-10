<!--
  - SPDX-FileCopyrightText: 2026 Cristian Casapu
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="vg-admin">
		<h2>{{ t('videogallery', 'Video Gallery') }}</h2>
		<p class="vg-admin__lead">
			{{ t('videogallery', 'Collects every video in each account into one library and plays all of them in the browser, converting the formats a browser cannot open on its own.') }}
		</p>

		<!-- What this machine can and cannot do, and what to do about it. -->
		<section class="vg-card">
			<header class="vg-card__head">
				<h3>{{ t('videogallery', 'This server') }}</h3>
				<button class="vg-btn" :disabled="probing" @click="runProbe">
					{{ probing ? t('videogallery', 'Testing…') : t('videogallery', 'Test the hardware again') }}
				</button>
			</header>

			<ul class="vg-checks">
				<li v-for="check in environment.checks" :key="check.id" :class="'vg-check vg-check--' + check.status">
					<span class="vg-check__dot" />
					<div class="vg-check__body">
						<p class="vg-check__summary">{{ check.summary }}</p>
						<p v-if="check.hint" class="vg-check__hint">{{ check.hint }}</p>
						<pre v-if="check.command" class="vg-check__command">{{ check.command }}</pre>
					</div>
				</li>
			</ul>

			<div v-if="encoderList.length" class="vg-encoders">
				<span class="vg-encoders__label">{{ t('videogallery', 'Encoders that work here') }}</span>
				<span v-for="encoder in encoderList" :key="encoder.key" class="vg-pill" :class="{ 'vg-pill--on': encoder.key === environment.encoder }">
					{{ encoder.label }}
				</span>
			</div>
			<ul v-if="environment.capabilities?.notes?.length" class="vg-notes">
				<li v-for="note in environment.capabilities.notes" :key="note">{{ note }}</li>
			</ul>
		</section>

		<!-- Where the working files go. -->
		<section class="vg-card">
			<h3>{{ t('videogallery', 'Working files') }}</h3>
			<p class="vg-hint">
				{{ t('videogallery', 'Converting video writes constantly and in large amounts. Point this at a fast disk that is not the one the system runs from. Everything written here is disposable and is cleaned up on its own.') }}
			</p>

			<div class="vg-field vg-field--row">
				<input v-model="settings.cache_root" type="text" spellcheck="false" :placeholder="'/srv/cache/videogallery'">
				<button class="vg-btn" :disabled="checkingPath" @click="checkPath">{{ t('videogallery', 'Check') }}</button>
			</div>
			<p v-if="pathVerdict" class="vg-verdict" :class="{ 'vg-verdict--bad': !pathOk }">{{ pathVerdict }}</p>
			<p v-if="candidates.length" class="vg-hint">
				{{ t('videogallery', 'Suggestions on this machine:') }}
				<button v-for="candidate in candidates" :key="candidate" class="vg-link" @click="settings.cache_root = candidate">{{ candidate }}</button>
			</p>

			<div class="vg-grid">
				<label class="vg-field">
					<span>{{ t('videogallery', 'Most space to use for cached previews (GB)') }}</span>
					<input v-model.number="settings.cache_max_gb" type="number" min="1" step="1">
				</label>
				<label class="vg-field">
					<span>{{ t('videogallery', 'Forget a preview nobody has opened for (days)') }}</span>
					<input v-model.number="settings.preview_ttl_days" type="number" min="0" step="1">
				</label>
			</div>

			<div class="vg-usage">
				<div class="vg-usage__bar">
					<div class="vg-usage__fill" :style="{ width: usagePercent + '%' }" />
				</div>
				<p class="vg-hint">
					{{ t('videogallery', '{used} in use of a {limit} allowance. {free} free on that disk. {sessions} playing now.', {
						used: human(cache.on_disk_bytes),
						limit: human(cache.limit_bytes),
						free: human(cache.disk_free),
						sessions: cache.sessions_live,
					}) }}
				</p>
			</div>

			<div class="vg-row">
				<button class="vg-btn" :disabled="busy" @click="cleanup(false)">{{ t('videogallery', 'Clear what has expired') }}</button>
				<button class="vg-btn vg-btn--warn" :disabled="busy" @click="cleanup(true)">{{ t('videogallery', 'Empty it completely') }}</button>
			</div>
		</section>

		<!-- Conversion. -->
		<section class="vg-card">
			<h3>{{ t('videogallery', 'Conversion') }}</h3>

			<label class="vg-toggle">
				<input v-model="settings.transcode_enabled" type="checkbox">
				<span>{{ t('videogallery', 'Convert formats the browser cannot open') }}</span>
			</label>
			<p class="vg-hint">{{ t('videogallery', 'With this off, only files a browser already understands will play, and the rest are offered as a download or to an external player.') }}</p>

			<label class="vg-toggle">
				<input v-model="settings.direct_play_enabled" type="checkbox">
				<span>{{ t('videogallery', 'Send files untouched when the browser and the connection can take them') }}</span>
			</label>

			<label class="vg-toggle">
				<input v-model="settings.hw_decode" type="checkbox">
				<span>{{ t('videogallery', 'Decode on the graphics card as well as encode') }}</span>
			</label>

			<div class="vg-grid">
				<label class="vg-field">
					<span>{{ t('videogallery', 'Encoder') }}</span>
					<select v-model="settings.encoder">
						<option value="auto">{{ t('videogallery', 'Choose the best that works') }}</option>
						<option v-for="encoder in encoderList" :key="encoder.key" :value="encoder.key">{{ encoder.label }}</option>
					</select>
				</label>
				<label class="vg-field">
					<span>{{ t('videogallery', 'Conversions at once') }}</span>
					<input v-model.number="settings.max_sessions" type="number" min="1" max="16">
				</label>
				<label class="vg-field">
					<span>{{ t('videogallery', 'Segment length (seconds)') }}</span>
					<input v-model.number="settings.segment_duration" type="number" min="2" max="10">
				</label>
				<label class="vg-field">
					<span>{{ t('videogallery', 'Stop a session unheard from for (seconds)') }}</span>
					<input v-model.number="settings.session_ttl" type="number" min="20" max="600">
				</label>
			</div>

			<label class="vg-toggle">
				<input v-model="settings.software_fallback" type="checkbox">
				<span>{{ t('videogallery', 'When every slot is busy, convert on the processor rather than refusing') }}</span>
			</label>

			<label class="vg-toggle">
				<input v-model="settings.instant_start" type="checkbox">
				<span>{{ t('videogallery', 'Start converting the moment a file is opened') }}</span>
			</label>
			<p class="vg-hint">{{ t('videogallery', 'The opening of the film is then usually ready before the player asks for it, which is most of the wait between pressing play and seeing a picture.') }}</p>
		</section>

		<!-- What the app has learned. -->
		<section class="vg-card">
			<header class="vg-card__head">
				<h3>{{ t('videogallery', 'What has been learned') }}</h3>
				<button v-if="memory.length" class="vg-btn" :disabled="busy" @click="forgetMemory">
					{{ t('videogallery', 'Forget it all') }}
				</button>
			</header>
			<p class="vg-hint">
				{{ t('videogallery', 'Each time something is played, how it was sent and how it went are noted against the kind of browser and the kind of file involved. A combination met before is then played the way that already worked, which starts sooner and avoids repeating a choice that once failed. Nothing about who watched what is kept — only the shape of the file and what the browser said it could decode.') }}
			</p>

			<label class="vg-toggle">
				<input v-model="settings.memory_enabled" type="checkbox">
				<span>{{ t('videogallery', 'Learn from what works') }}</span>
			</label>

			<table v-if="memory.length" class="vg-table">
				<thead>
					<tr>
						<th>{{ t('videogallery', 'Situation') }}</th>
						<th>{{ t('videogallery', 'Sent as') }}</th>
						<th>{{ t('videogallery', 'Worked') }}</th>
						<th>{{ t('videogallery', 'Failed') }}</th>
						<th>{{ t('videogallery', 'Start') }}</th>
						<th>{{ t('videogallery', 'Trust') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="entry in memory" :key="entry.signature + entry.mode">
						<td class="vg-mono">{{ entry.signature.slice(0, 10) }}</td>
						<td>{{ modeLabel(entry.mode) }}<span v-if="entry.segmentType !== 'none'" class="vg-dim"> · {{ entry.segmentType }}</span></td>
						<td>{{ entry.successes }}</td>
						<td :class="{ 'vg-bad': entry.failures > 0 }">{{ entry.failures }}</td>
						<td>{{ entry.firstSegmentMs ? entry.firstSegmentMs + ' ms' : '—' }}</td>
						<td>{{ Math.round(entry.confidence * 100) }}%</td>
					</tr>
				</tbody>
			</table>
			<p v-else class="vg-hint">{{ t('videogallery', 'Nothing yet. This fills in as videos are played.') }}</p>
		</section>

		<!-- The connection. -->
		<section class="vg-card">
			<h3>{{ t('videogallery', 'Quality and the connection') }}</h3>
			<p class="vg-hint">
				{{ t('videogallery', 'Before playing, the browser measures how fast its link to this server actually is. A file is only sent untouched when the link can carry it; otherwise it is converted small enough to play without stopping. The measurement is repeated while the film runs, so the quality follows the connection down and back up again.') }}
			</p>

			<label class="vg-toggle">
				<input v-model="settings.bandwidth_probe_enabled" type="checkbox">
				<span>{{ t('videogallery', 'Measure the connection before playing') }}</span>
			</label>
			<label class="vg-toggle">
				<input v-model="settings.governor_enabled" type="checkbox">
				<span>{{ t('videogallery', 'Keep adjusting the quality while playing') }}</span>
			</label>

			<div class="vg-grid">
				<label class="vg-field">
					<span>{{ t('videogallery', 'Bytes used to measure') }}</span>
					<input v-model.number="settings.bandwidth_probe_bytes" type="number" min="262144" step="262144">
				</label>
				<label class="vg-field">
					<span>{{ t('videogallery', 'Headroom over the measured speed') }}</span>
					<input v-model.number="settings.bandwidth_safety_factor" type="number" min="1" max="4" step="0.1">
				</label>
				<label class="vg-field">
					<span>{{ t('videogallery', 'Check in every (seconds)') }}</span>
					<input v-model.number="settings.governor_interval" type="number" min="3" max="60">
				</label>
				<label class="vg-field">
					<span>{{ t('videogallery', 'Steady for this long before raising quality (seconds)') }}</span>
					<input v-model.number="settings.upshift_stable_seconds" type="number" min="10" max="600">
				</label>
			</div>
		</section>

		<!-- Previews. -->
		<section class="vg-card">
			<h3>{{ t('videogallery', 'Previews') }}</h3>
			<label class="vg-toggle">
				<input v-model="settings.preview_enabled" type="checkbox">
				<span>{{ t('videogallery', 'Make cover pictures and the clips that play under the pointer') }}</span>
			</label>
			<label class="vg-toggle">
				<input v-model="settings.prewarm_enabled" type="checkbox">
				<span>{{ t('videogallery', 'Prepare them in the background rather than on first sight') }}</span>
			</label>
			<label class="vg-toggle">
				<input v-model="settings.sprite_enabled" type="checkbox">
				<span>{{ t('videogallery', 'Make the thumbnail strips used when dragging the progress bar') }}</span>
			</label>

			<div class="vg-grid">
				<label class="vg-field">
					<span>{{ t('videogallery', 'Hover clip length (seconds)') }}</span>
					<input v-model.number="settings.preview_seconds" type="number" min="2" max="30">
				</label>
				<label class="vg-field">
					<span>{{ t('videogallery', 'Hover clip height (pixels)') }}</span>
					<input v-model.number="settings.preview_height" type="number" min="180" max="1080" step="10">
				</label>
				<label class="vg-field">
					<span>{{ t('videogallery', 'Take the clip from this far in (per cent)') }}</span>
					<input v-model.number="settings.preview_start_percent" type="number" min="0" max="90">
				</label>
				<label class="vg-field">
					<span>{{ t('videogallery', 'Prepare this many each round') }}</span>
					<input v-model.number="settings.prewarm_batch" type="number" min="0" max="500">
				</label>
			</div>
		</section>

		<!-- External players and the library. -->
		<section class="vg-card">
			<h3>{{ t('videogallery', 'Players on the device') }}</h3>
			<label class="vg-toggle">
				<input v-model="settings.external_player_enabled" type="checkbox">
				<span>{{ t('videogallery', 'Offer to hand the original file to VLC and other installed players') }}</span>
			</label>
			<p class="vg-hint">
				{{ t('videogallery', 'The link carries its own permission, because a player on a television has no way to sign in. It names one file and one account and stops working after the time set here.') }}
			</p>
			<label class="vg-field vg-field--narrow">
				<span>{{ t('videogallery', 'A link lasts (seconds)') }}</span>
				<input v-model.number="settings.external_token_ttl" type="number" min="60" step="60">
			</label>
		</section>

		<section class="vg-card">
			<header class="vg-card__head">
				<h3>{{ t('videogallery', 'The library') }}</h3>
				<div class="vg-row">
					<button class="vg-btn" :disabled="busy" @click="reindex(false)">{{ t('videogallery', 'Look for new files') }}</button>
					<button class="vg-btn" :disabled="busy" @click="reindex(true)">{{ t('videogallery', 'Read every file again') }}</button>
				</div>
			</header>
			<p class="vg-hint">
				{{ libraryLine }}
			</p>

			<label class="vg-field vg-field--narrow">
				<span>{{ t('videogallery', 'Folder made in each account for videos') }}</span>
				<input v-model="settings.default_folder" type="text" spellcheck="false" placeholder="Video">
			</label>
			<p class="vg-hint">{{ t('videogallery', 'The library gathers videos from everywhere in an account. This is simply the one obvious place to put a new one; it is created on the first visit and gets its own row on the page. Leave it empty for no such folder.') }}</p>

			<details class="vg-advanced">
				<summary>{{ t('videogallery', 'Paths, if ffmpeg is somewhere unusual') }}</summary>
				<div class="vg-grid">
					<label class="vg-field">
						<span>{{ t('videogallery', 'ffmpeg') }}</span>
						<input v-model="settings.ffmpeg_path" type="text" spellcheck="false" :placeholder="environment.capabilities?.ffmpeg || '/usr/bin/ffmpeg'">
					</label>
					<label class="vg-field">
						<span>{{ t('videogallery', 'ffprobe') }}</span>
						<input v-model="settings.ffprobe_path" type="text" spellcheck="false" :placeholder="environment.capabilities?.ffprobe || '/usr/bin/ffprobe'">
					</label>
					<label class="vg-field">
						<span>{{ t('videogallery', 'Graphics device for VA-API') }}</span>
						<input v-model="settings.vaapi_device" type="text" spellcheck="false" placeholder="/dev/dri/renderD128">
					</label>
					<label class="vg-field">
						<span>{{ t('videogallery', 'Skip files under (seconds)') }}</span>
						<input v-model.number="settings.min_duration_seconds" type="number" min="0">
					</label>
				</div>
			</details>
		</section>

		<div class="vg-save">
			<button class="vg-btn vg-btn--primary" :disabled="busy" @click="save">
				{{ busy ? t('videogallery', 'Saving…') : t('videogallery', 'Save') }}
			</button>
			<span v-if="message" class="vg-save__message">{{ message }}</span>
		</div>
	</div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'

interface Check {
	id: string
	status: string
	summary: string
	hint: string | null
	command: string | null
}

interface AdminState {
	settings: Record<string, any>
	defaults: Record<string, any>
	environment: {
		status: string
		checks: Check[]
		encoder: string
		playbackPossible: boolean
		capabilities: Record<string, any>
	}
	cache: Record<string, any>
	library: Record<string, number>
	candidates: string[]
	memory: Array<{
		signature: string
		mode: string
		segmentType: string
		successes: number
		failures: number
		firstSegmentMs: number
		confidence: number
	}>
	encoders: Record<string, { encoder: string, label: string, hw: boolean }>
}

const state = loadState<AdminState>('videogallery', 'admin')

const settings = ref({ ...state.settings })
const environment = ref(state.environment)
const cache = ref(state.cache)
const library = ref(state.library)
const candidates = ref(state.candidates ?? [])
const memory = ref(state.memory ?? [])

const busy = ref(false)
const probing = ref(false)
const checkingPath = ref(false)
const message = ref('')
const pathVerdict = ref('')
const pathOk = ref(true)

const url = (path: string) => generateOcsUrl('apps/videogallery/api/v1/admin/' + path)

/** Only the encoders that actually worked when tested on this machine. */
const encoderList = computed(() => {
	const available = environment.value.capabilities?.available ?? {}
	return Object.entries(available).map(([key, spec]) => ({ key, label: (spec as { label: string }).label }))
})

const usagePercent = computed(() => {
	const limit = Number(cache.value.limit_bytes ?? 0)
	return limit > 0 ? Math.min(100, (Number(cache.value.on_disk_bytes ?? 0) / limit) * 100) : 0
})

const libraryLine = computed(() => t('videogallery', '{total} videos, {ok} read, {pending} waiting, {failed} could not be read.', {
	total: String(library.value.total ?? 0),
	ok: String(library.value.ok ?? 0),
	pending: String(library.value.pending ?? 0),
	failed: String(library.value.failed ?? 0),
}))

function modeLabel(mode: string): string {
	switch (mode) {
	case 'direct':
		return t('videogallery', 'Untouched')
	case 'remux':
		return t('videogallery', 'Repackaged')
	case 'transcode_audio':
		return t('videogallery', 'Sound converted')
	default:
		return t('videogallery', 'Re-encoded')
	}
}

async function forgetMemory(): Promise<void> {
	if (!window.confirm(t('videogallery', 'Throw away everything learned about how files play? It will be worked out again from scratch.'))) {
		return
	}
	busy.value = true
	try {
		await axios.delete(url('memory'))
		memory.value = []
		flash(t('videogallery', 'Forgotten.'))
	} finally {
		busy.value = false
	}
}

function human(bytes: number): string {
	const units = ['B', 'kB', 'MB', 'GB', 'TB']
	let index = 0
	let value = Number(bytes) || 0
	while (value >= 1024 && index < units.length - 1) {
		value /= 1024
		index++
	}
	return `${value.toFixed(1)} ${units[index]}`
}

function flash(text: string): void {
	message.value = text
	window.setTimeout(() => { message.value = '' }, 4000)
}

async function save(): Promise<void> {
	busy.value = true
	try {
		const { data } = await axios.put(url('settings'), { settings: settings.value })
		settings.value = data.ocs.data.settings
		environment.value = data.ocs.data.environment
		cache.value = data.ocs.data.cache
		flash(t('videogallery', 'Saved.'))
	} catch {
		flash(t('videogallery', 'That could not be saved.'))
	} finally {
		busy.value = false
	}
}

async function runProbe(): Promise<void> {
	probing.value = true
	try {
		const { data } = await axios.post(url('probe'))
		environment.value = data.ocs.data.environment
		flash(t('videogallery', 'Tested.'))
	} catch {
		flash(t('videogallery', 'The test could not be run.'))
	} finally {
		probing.value = false
	}
}

async function checkPath(): Promise<void> {
	checkingPath.value = true
	try {
		const { data } = await axios.post(url('check-path'), { path: settings.value.cache_root })
		pathOk.value = data.ocs.data.ok
		pathVerdict.value = data.ocs.data.message
	} catch {
		pathOk.value = false
		pathVerdict.value = t('videogallery', 'That directory could not be checked.')
	} finally {
		checkingPath.value = false
	}
}

async function cleanup(everything: boolean): Promise<void> {
	if (everything && !window.confirm(t('videogallery', 'Stop everything playing and delete every cached preview? They will be made again as they are needed.'))) {
		return
	}
	busy.value = true
	try {
		const { data } = await axios.post(url('cleanup'), { everything })
		cache.value = data.ocs.data.cache
		flash(t('videogallery', 'Freed {amount}.', { amount: human(data.ocs.data.result.bytes_freed ?? 0) }))
	} finally {
		busy.value = false
	}
}

async function reindex(reprobe: boolean): Promise<void> {
	busy.value = true
	try {
		const { data } = await axios.post(url('reindex'), { reprobe })
		library.value = data.ocs.data.library
		const sync = data.ocs.data.sync
		flash(t('videogallery', '{added} new, {removed} gone. Reading them happens in the background.', {
			added: sync.added, removed: sync.removed,
		}))
	} finally {
		busy.value = false
	}
}
</script>

<style scoped>
.vg-admin {
	max-width: 780px;
}

.vg-admin__lead {
	margin: 0 0 20px;
	color: var(--color-text-maxcontrast);
	line-height: 1.5;
}

.vg-card {
	margin-bottom: 18px;
	padding: 18px 20px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-main-background);
}

.vg-card h3 {
	margin: 0 0 10px;
	font-size: 16px;
	font-weight: 700;
}

.vg-card__head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
	margin-bottom: 10px;
}

.vg-card__head h3 {
	margin: 0;
}

.vg-hint {
	margin: 6px 0 12px;
	font-size: 13px;
	line-height: 1.5;
	color: var(--color-text-maxcontrast);
}

.vg-checks {
	list-style: none;
	margin: 0 0 12px;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.vg-check {
	display: flex;
	gap: 10px;
	align-items: flex-start;
}

.vg-check__dot {
	flex: 0 0 10px;
	width: 10px;
	height: 10px;
	margin-top: 5px;
	border-radius: 50%;
	background: var(--color-success, #46ba61);
}

.vg-check--warning .vg-check__dot {
	background: var(--color-warning, #e9a12c);
}

.vg-check--error .vg-check__dot {
	background: var(--color-error, #d9534f);
}

.vg-check__summary {
	margin: 0;
	font-size: 14px;
}

.vg-check__hint {
	margin: 3px 0 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	line-height: 1.5;
}

.vg-check__command {
	margin: 7px 0 0;
	padding: 9px 11px;
	border-radius: 7px;
	background: var(--color-background-dark);
	font-family: monospace;
	font-size: 12px;
	white-space: pre-wrap;
	word-break: break-all;
	user-select: all;
}

.vg-encoders {
	display: flex;
	align-items: center;
	gap: 7px;
	flex-wrap: wrap;
	margin-top: 12px;
}

.vg-encoders__label {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.vg-pill {
	padding: 3px 10px;
	border: 1px solid var(--color-border);
	border-radius: 999px;
	font-size: 12px;
}

.vg-pill--on {
	border-color: transparent;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-weight: 600;
}

.vg-notes {
	margin: 10px 0 0;
	padding-inline-start: 18px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	line-height: 1.6;
}

.vg-grid {
	display: grid;
	gap: 12px;
	grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
	margin-top: 10px;
}

.vg-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 13px;
}

.vg-field--row {
	flex-direction: row;
	align-items: center;
	gap: 8px;
}

.vg-field--narrow {
	max-width: 260px;
}

.vg-field input,
.vg-field select {
	width: 100%;
	min-height: 34px;
}

.vg-toggle {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 8px 0;
	font-size: 14px;
	cursor: pointer;
}

.vg-toggle input {
	margin: 0;
}

.vg-row {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	margin-top: 12px;
}

.vg-btn {
	padding: 7px 14px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-element, 8px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 13px;
	cursor: pointer;
}

.vg-btn:hover:not(:disabled) {
	background: var(--color-background-hover);
}

.vg-btn:disabled {
	opacity: 0.55;
	cursor: default;
}

.vg-btn--primary {
	background: var(--color-primary-element);
	border-color: transparent;
	color: var(--color-primary-element-text);
	font-weight: 600;
}

.vg-btn--warn {
	color: var(--color-error, #d9534f);
}

.vg-link {
	margin-inline-end: 8px;
	padding: 0;
	border: none;
	background: none;
	color: var(--color-primary-element);
	font-size: 13px;
	font-family: monospace;
	cursor: pointer;
	text-decoration: underline;
}

.vg-verdict {
	margin: 6px 0 0;
	font-size: 13px;
	color: var(--color-success, #46ba61);
}

.vg-verdict--bad {
	color: var(--color-error, #d9534f);
}

.vg-usage {
	margin-top: 14px;
}

.vg-usage__bar {
	height: 7px;
	border-radius: 4px;
	background: var(--color-background-dark);
	overflow: hidden;
}

.vg-usage__fill {
	height: 100%;
	background: var(--color-primary-element);
}

.vg-advanced {
	margin-top: 14px;
}

.vg-advanced summary {
	cursor: pointer;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.vg-table {
	width: 100%;
	margin-top: 12px;
	border-collapse: collapse;
	font-size: 12px;
}

.vg-table th {
	padding: 6px 8px;
	text-align: start;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	border-bottom: 1px solid var(--color-border);
}

.vg-table td {
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}

.vg-mono {
	font-family: monospace;
	color: var(--color-text-maxcontrast);
}

.vg-dim {
	color: var(--color-text-maxcontrast);
}

.vg-bad {
	color: var(--color-error, #d9534f);
}

.vg-save {
	display: flex;
	align-items: center;
	gap: 12px;
	position: sticky;
	bottom: 0;
	padding: 12px 0;
	background: var(--color-main-background);
}

.vg-save__message {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}
</style>
