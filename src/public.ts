// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

import { createApp } from 'vue'
import Public from './views/Public.vue'

const element = document.getElementById('videogallery-public')
if (element) {
	createApp(Public).mount(element)
}
