<!--
  - SPDX-FileCopyrightText: 2026 Cristian Casapu
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div ref="root" class="menu">
		<button class="menu__trigger"
			:aria-label="label"
			:aria-expanded="open"
			aria-haspopup="menu"
			@click="open = !open">
			<slot />
		</button>

		<div v-if="open" class="menu__panel" role="menu">
			<p class="menu__label">{{ label }}</p>
			<button v-for="option in options"
				:key="option.id"
				class="menu__item"
				:class="{ 'menu__item--on': option.id === selected }"
				role="menuitemradio"
				:aria-checked="option.id === selected"
				@click="choose(option.id)">
				<span class="menu__tick" aria-hidden="true">
					<svg v-if="option.id === selected" viewBox="0 0 24 24" width="16" height="16">
						<path fill="currentColor" d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z" />
					</svg>
				</span>
				<span class="menu__text">
					<span class="menu__name">{{ option.label }}</span>
					<span v-if="option.detail" class="menu__detail">{{ option.detail }}</span>
				</span>
			</button>
			<p v-if="footnote" class="menu__footnote">{{ footnote }}</p>
		</div>
	</div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'

defineProps<{
	label: string
	options: Array<{ id: string, label: string, detail?: string }>
	selected: string
	footnote?: string
}>()

const emit = defineEmits<{ select: [id: string] }>()

const open = ref(false)
const root = ref<HTMLElement | null>(null)

function choose(id: string): void {
	emit('select', id)
	open.value = false
}

function onOutside(event: MouseEvent): void {
	if (open.value && root.value && !root.value.contains(event.target as Node)) {
		open.value = false
	}
}

onMounted(() => document.addEventListener('click', onOutside, true))
onBeforeUnmount(() => document.removeEventListener('click', onOutside, true))
</script>

<style scoped>
.menu {
	position: relative;
}

.menu__trigger {
	display: grid;
	place-items: center;
	width: 38px;
	height: 38px;
	border: none;
	border-radius: 8px;
	background: transparent;
	color: #fff;
	cursor: pointer;
}

.menu__trigger:hover,
.menu__trigger[aria-expanded='true'] {
	background: rgb(255 255 255 / 14%);
}

.menu__panel {
	position: absolute;
	right: 0;
	bottom: calc(100% + 8px);
	min-width: 232px;
	max-height: 58vh;
	overflow-y: auto;
	padding: 8px;
	border-radius: 10px;
	background: rgb(22 24 28 / 97%);
	box-shadow: 0 12px 40px rgb(0 0 0 / 70%);
	z-index: 5;
}

.menu__label {
	margin: 4px 8px 8px;
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 0.1em;
	text-transform: uppercase;
	color: #8d949d;
}

.menu__item {
	display: flex;
	align-items: center;
	gap: 8px;
	width: 100%;
	padding: 8px;
	border: none;
	border-radius: 6px;
	background: transparent;
	color: #e9ecef;
	text-align: start;
	cursor: pointer;
}

.menu__item:hover {
	background: rgb(255 255 255 / 10%);
}

.menu__tick {
	flex: 0 0 16px;
	color: #e50914;
}

.menu__text {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.menu__name {
	font-size: 14px;
}

.menu__item--on .menu__name {
	font-weight: 700;
}

.menu__detail {
	font-size: 11px;
	color: #8d949d;
}

.menu__footnote {
	margin: 8px 8px 4px;
	font-size: 11px;
	color: #8d949d;
}
</style>
