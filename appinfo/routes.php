<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'page#watch', 'url' => '/watch/{fileId}', 'verb' => 'GET'],

		// Media delivery. Plain routes, not OCS: these are fed straight to <video>,
		// <img> and hls.js, which send no OCS headers and follow no OCS envelope.
		['name' => 'stream#direct', 'url' => '/direct/{fileId}', 'verb' => 'GET'],
		['name' => 'stream#master', 'url' => '/hls/{sessionId}/master.m3u8', 'verb' => 'GET'],
		['name' => 'stream#playlist', 'url' => '/hls/{sessionId}/index.m3u8', 'verb' => 'GET'],
		['name' => 'stream#init', 'url' => '/hls/{sessionId}/init.mp4', 'verb' => 'GET'],
		['name' => 'stream#segment', 'url' => '/hls/{sessionId}/seg{index}.{ext}', 'verb' => 'GET',
			'requirements' => ['index' => '\\d+', 'ext' => 'ts|m4s']],
		// Ending a session as the page is being closed. A beacon is the only kind
		// of request that survives a tab closing, and it can set no headers, so
		// this cannot be one of the OCS routes above.
		['name' => 'stream#abandon', 'url' => '/close/{sessionId}', 'verb' => 'POST'],
		// Serves incompressible bytes so the client can time a real download and
		// find out what the link between it and this server can actually carry.
		['name' => 'stream#bandwidth', 'url' => '/bandwidth', 'verb' => 'GET'],
		['name' => 'stream#subtitle', 'url' => '/subtitle/{fileId}/{index}.vtt', 'verb' => 'GET'],

		['name' => 'preview#poster', 'url' => '/preview/{fileId}/poster', 'verb' => 'GET'],
		['name' => 'preview#loop', 'url' => '/preview/{fileId}/loop', 'verb' => 'GET'],
		['name' => 'preview#sprite', 'url' => '/preview/{fileId}/sprite', 'verb' => 'GET'],

		// Watching through a link. Everything here answers without an account,
		// and goes no further than the token allows.
		['name' => 'public#index', 'url' => '/s/{token}', 'verb' => 'GET'],
		['name' => 'public#authenticate', 'url' => '/s/{token}', 'verb' => 'POST'],
		['name' => 'public#master', 'url' => '/s/{token}/hls/{sessionId}/master.m3u8', 'verb' => 'GET'],
		['name' => 'public#playlist', 'url' => '/s/{token}/hls/{sessionId}/index.m3u8', 'verb' => 'GET'],
		['name' => 'public#init', 'url' => '/s/{token}/hls/{sessionId}/init.mp4', 'verb' => 'GET'],
		['name' => 'public#segment', 'url' => '/s/{token}/hls/{sessionId}/seg{index}.{ext}', 'verb' => 'GET',
			'requirements' => ['index' => '\\d+', 'ext' => 'ts|m4s']],
		['name' => 'public#direct', 'url' => '/s/{token}/direct/{fileId}', 'verb' => 'GET'],
		['name' => 'public#poster', 'url' => '/s/{token}/preview/{fileId}/poster', 'verb' => 'GET'],
		['name' => 'public#loop', 'url' => '/s/{token}/preview/{fileId}/loop', 'verb' => 'GET'],
		['name' => 'public#sprite', 'url' => '/s/{token}/preview/{fileId}/sprite', 'verb' => 'GET'],
		['name' => 'public#subtitle', 'url' => '/s/{token}/subtitle/{fileId}/{index}.vtt', 'verb' => 'GET'],

		// Handed to VLC and friends: the token carries the authorisation, since an
		// external player has no Nextcloud session.
		['name' => 'external#playlist', 'url' => '/external/{token}/playlist.m3u', 'verb' => 'GET'],
		['name' => 'external#file', 'url' => '/external/{token}/original', 'verb' => 'GET'],
	],
	'ocs' => [
		['name' => 'api#config', 'url' => '/api/v1/config', 'verb' => 'GET'],
		['name' => 'api#timeline', 'url' => '/api/v1/timeline', 'verb' => 'GET'],
		['name' => 'api#items', 'url' => '/api/v1/items', 'verb' => 'GET'],
		['name' => 'api#rails', 'url' => '/api/v1/rails', 'verb' => 'GET'],
		['name' => 'api#item', 'url' => '/api/v1/items/{fileId}', 'verb' => 'GET'],
		['name' => 'api#folders', 'url' => '/api/v1/folders', 'verb' => 'GET'],
		['name' => 'api#setProgress', 'url' => '/api/v1/progress/{fileId}', 'verb' => 'PUT'],
		['name' => 'api#deleteProgress', 'url' => '/api/v1/progress/{fileId}', 'verb' => 'DELETE'],
		['name' => 'api#externalToken', 'url' => '/api/v1/external/{fileId}', 'verb' => 'POST'],
		['name' => 'api#rescan', 'url' => '/api/v1/rescan', 'verb' => 'POST'],

		['name' => 'playback#open', 'url' => '/api/v1/play/{fileId}', 'verb' => 'POST'],
		['name' => 'playback#ping', 'url' => '/api/v1/play/{sessionId}/ping', 'verb' => 'POST'],
		['name' => 'playback#close', 'url' => '/api/v1/play/{sessionId}', 'verb' => 'DELETE'],
		['name' => 'playback#report', 'url' => '/api/v1/play/{sessionId}/report', 'verb' => 'POST'],
		['name' => 'playback#seek', 'url' => '/api/v1/play/{sessionId}/seek', 'verb' => 'POST'],

		['name' => 'publicApi#contents', 'url' => '/api/v1/public/{token}', 'verb' => 'GET'],
		['name' => 'publicApi#item', 'url' => '/api/v1/public/{token}/items/{fileId}', 'verb' => 'GET'],
		['name' => 'publicApi#play', 'url' => '/api/v1/public/{token}/play/{fileId}', 'verb' => 'POST'],
		['name' => 'publicApi#ping', 'url' => '/api/v1/public/{token}/play/{sessionId}/ping', 'verb' => 'POST'],
		['name' => 'publicApi#report', 'url' => '/api/v1/public/{token}/play/{sessionId}/report', 'verb' => 'POST'],
		['name' => 'publicApi#close', 'url' => '/api/v1/public/{token}/play/{sessionId}', 'verb' => 'DELETE'],

		['name' => 'share#forFile', 'url' => '/api/v1/shares/{fileId}', 'verb' => 'GET'],
		['name' => 'share#shortLink', 'url' => '/api/v1/shares/{shareId}/short-link', 'verb' => 'POST'],
		['name' => 'share#folders', 'url' => '/api/v1/shareable-folders', 'verb' => 'GET'],

		['name' => 'admin#getSettings', 'url' => '/api/v1/admin/settings', 'verb' => 'GET'],
		['name' => 'admin#setSettings', 'url' => '/api/v1/admin/settings', 'verb' => 'PUT'],
		['name' => 'admin#probe', 'url' => '/api/v1/admin/probe', 'verb' => 'POST'],
		['name' => 'admin#stats', 'url' => '/api/v1/admin/stats', 'verb' => 'GET'],
		['name' => 'admin#cleanup', 'url' => '/api/v1/admin/cleanup', 'verb' => 'POST'],
		['name' => 'admin#reindex', 'url' => '/api/v1/admin/reindex', 'verb' => 'POST'],
		['name' => 'admin#autoTune', 'url' => '/api/v1/admin/tune', 'verb' => 'POST'],
		['name' => 'admin#forgetMemory', 'url' => '/api/v1/admin/memory', 'verb' => 'DELETE'],
		['name' => 'admin#checkPath', 'url' => '/api/v1/admin/check-path', 'verb' => 'POST'],
	],
];
