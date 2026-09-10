<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20260910120000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('videogallery_items')) {
			$t = $schema->createTable('videogallery_items');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('path', Types::STRING, ['notnull' => true, 'length' => 4000]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('mimetype', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('size', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('mtime', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			// When the video was actually shot, as opposed to when the file landed here.
			$t->addColumn('taken_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('date_source', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'mtime']);
			$t->addColumn('duration_ms', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('width', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('height', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('rotation', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('fps', Types::FLOAT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('bitrate', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('container', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
			$t->addColumn('vcodec', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => '']);
			$t->addColumn('vprofile', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => '']);
			$t->addColumn('vlevel', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('pix_fmt', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => '']);
			$t->addColumn('bit_depth', Types::INTEGER, ['notnull' => true, 'default' => 8]);
			$t->addColumn('hdr', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('acodec', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => '']);
			$t->addColumn('achannels', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('audio_tracks', Types::TEXT, ['notnull' => false]);
			$t->addColumn('sub_tracks', Types::TEXT, ['notnull' => false]);
			$t->addColumn('chapters', Types::TEXT, ['notnull' => false]);
			// direct | remux | transcode_audio | transcode — worked out once, at index time.
			$t->addColumn('play_mode', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => '']);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'pending']);
			$t->addColumn('fail_reason', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('probe_version', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('indexed_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('assets', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['user_id', 'file_id'], 'vgal_item_user_file');
			$t->addIndex(['user_id', 'taken_at'], 'vgal_item_timeline');
			$t->addIndex(['user_id', 'status'], 'vgal_item_status');
			$t->addIndex(['file_id'], 'vgal_item_file');
		}

		if (!$schema->hasTable('videogallery_sessions')) {
			$t = $schema->createTable('videogallery_sessions');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('token', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('mode', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('profile', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => '']);
			$t->addColumn('encoder', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => '']);
			$t->addColumn('dir', Types::STRING, ['notnull' => true, 'length' => 512]);
			$t->addColumn('pid', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			// Which segment the running ffmpeg was started at, and how far it has written.
			$t->addColumn('start_segment', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('segment_dur', Types::INTEGER, ['notnull' => true, 'default' => 4]);
			$t->addColumn('total_segments', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('duration_ms', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			// A re-encode becomes H.264 in MPEG-TS, which every player takes. A file
			// copied through untouched may hold AV1 or Opus, which MPEG-TS cannot
			// carry at all, and has to go in fragmented MP4 instead.
			$t->addColumn('segment_type', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'ts']);
			$t->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'starting']);
			$t->addColumn('audio_index', Types::INTEGER, ['notnull' => true, 'default' => -1]);
			// Bumped whenever the segments already produced stop being what the
			// playlist describes, so their addresses change with them and nothing
			// in between serves yesterday's quality from a cache.
			$t->addColumn('generation', Types::INTEGER, ['notnull' => true, 'default' => 1]);
			// Which graphics card this conversion was sent to, so a machine with
			// several can spread the work and count what each is carrying.
			$t->addColumn('device', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			// Set when the viewer arrived through a share link rather than an
			// account: the session belongs to the link, and dies with it.
			$t->addColumn('share_token', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('last_seen', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('error', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['token'], 'vgal_sess_token');
			$t->addIndex(['last_seen'], 'vgal_sess_seen');
			$t->addIndex(['user_id'], 'vgal_sess_user');
			$t->addIndex(['state'], 'vgal_sess_state');
			$t->addIndex(['device'], 'vgal_sess_device');
		}

		if (!$schema->hasTable('videogallery_progress')) {
			$t = $schema->createTable('videogallery_progress');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('position_ms', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('duration_ms', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('finished', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('audio_index', Types::INTEGER, ['notnull' => true, 'default' => -1]);
			$t->addColumn('sub_index', Types::INTEGER, ['notnull' => true, 'default' => -1]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['user_id', 'file_id'], 'vgal_prog_user_file');
			$t->addIndex(['user_id', 'updated_at'], 'vgal_prog_recent');
		}

		// One row per file on the cache disk. Nothing is written there without a row
		// here, so the janitor can always tell a live asset from an orphan.
		if (!$schema->hasTable('videogallery_assets')) {
			$t = $schema->createTable('videogallery_assets');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('rel_path', Types::STRING, ['notnull' => true, 'length' => 512]);
			$t->addColumn('size', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('version', Types::INTEGER, ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('last_used', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['file_id', 'kind'], 'vgal_asset_file_kind');
			$t->addIndex(['last_used'], 'vgal_asset_lru');
			$t->addIndex(['user_id'], 'vgal_asset_user');
		}

		// What has actually worked before.
		//
		// The rules that choose how to play a file are careful, but they are still
		// rules, and a browser that claims a codec it then stumbles over will fool
		// them every time. So the outcome of each decision is remembered against
		// the situation that produced it — this kind of browser, this kind of file
		// — and a situation seen before is answered from experience rather than
		// worked out again. It makes playback start sooner, and it quietly corrects
		// the places where the rules are wrong.
		if (!$schema->hasTable('videogallery_recipes')) {
			$t = $schema->createTable('videogallery_recipes');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			// A fingerprint of the browser's abilities and the file's make-up.
			$t->addColumn('signature', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('mode', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('segment_type', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'ts']);
			$t->addColumn('profile', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => '']);
			$t->addColumn('successes', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('failures', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('stalls', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('watched_seconds', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('first_segment_ms', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('sample', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['signature', 'mode', 'profile'], 'vgal_recipe_key');
			$t->addIndex(['signature'], 'vgal_recipe_sig');
			$t->addIndex(['updated_at'], 'vgal_recipe_age');
		}

		return $schema;
	}
}
