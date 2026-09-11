// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

import { createApp } from 'vue'
import Public from './views/Public.vue'

const element = document.getElementById('videogallery-public')
if (element) {
	// The public layout is a sign-in page underneath, wallpaper and footer and
	// all. A video library wants the whole window and a dark one.
	document.body.classList.add('videogallery-public-page')
	createApp(Public).mount(element)
}
