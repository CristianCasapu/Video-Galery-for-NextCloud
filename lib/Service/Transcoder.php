<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\VideoGallery\Service;

use OCA\VideoGallery\Db\Item;
use OCA\VideoGallery\Db\ItemMapper;
use OCA\VideoGallery\Db\Session;
use OCA\VideoGallery\Db\SessionMapper;
use Psr\Log\LoggerInterface;

/**
 * Turns a file the browser cannot open into a stream it can, on the fly.
 *
 * The stream is cut into segments and the playlist lists all of them from the
 * start, including the ones that do not exist yet. That is what makes seeking
 * work: when the player asks for a segment far ahead of anything produced so
 * far, the encoder is restarted at that point rather than the viewer waiting
 * for it to grind through the intervening hour.
 */
class Transcoder {



	/**
	 * Codecs MPEG-TS cannot carry. A file being copied through with any of these
	 * has to be packaged as fragmented MP4 instead.
	 */
	private const NOT_IN_MPEGTS = ['av1', 'vp8', 'vp9', 'opus', 'vorbis', 'flac', 'alac'];

	public function __construct(
		private SessionMapper $sessions,
		private ItemMapper $items,
		private FFmpeg $ffmpeg,
		private FileResolver $resolver,
		private Paths $paths,
		private Config $config,
		private PlaybackDecision $decision,
		private SegmentPlan $plan,
		private Tuning $tuning,
		private Janitor $janitor,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Set up a session: work out where the segments will fall, and write that
	 * plan down. Nothing is encoded until the first segment is asked for.
	 *
	 * @param array<string, mixed> $planned the outcome of PlaybackDecision::decide()
	 */
	public function open(string $userId, Item $item, array $planned, float $startSeconds = 0.0, int $audioIndex = -1): Session {
		$token = bin2hex(random_bytes(12));
		$dir = $this->paths->sessionDir($token);
		if (!@mkdir($dir, 0770, true) && !is_dir($dir)) {
			throw new \RuntimeException('Could not create a working directory for playback.');
		}

		$mode = (string)$planned['mode'];
		$segmentDuration = $this->config->getInt('segment_duration');

		$session = new Session();
		$session->setToken($token);
		$session->setUserId($userId);
		$session->setFileId($item->getFileId());
		$session->setMode($mode);
		$session->setProfile((string)$planned['profile']);
		$encoder = $mode === PlaybackDecision::DIRECT ? '' : $this->ffmpeg->chosenEncoder();
		$session->setEncoder($encoder);
		if ($encoder !== '') {
			// On a machine with more than one card, the work goes to whichever is
			// carrying least, so they share the load instead of queueing.
			$session->setDevice($this->tuning->pickDevice($encoder)['index']);
		}
		$session->setDir($dir);
		$session->setSegmentDur($segmentDuration);
		$session->setSegmentType($this->segmentTypeFor($item, $mode));
		$session->setDurationMs($item->getDurationMs());
		$session->setAudioIndex($audioIndex);
		$session->setState(Session::STARTING);
		$session->setCreatedAt(time());
		$session->setLastSeen(time());

		$source = $this->prepareSource($session, $item);
		if ($this->isCopyMode($mode)) {
			// Where a copied stream can be cut is not ours to choose: a segment
			// can only begin at a keyframe that is also a seek point in the
			// container's own index, and those sit wherever the file's author
			// left them. So the encoder is left to cut where it can and to write
			// its own playlist as it goes, and that playlist is what the player
			// is given.
			$session->setTotalSegments(0);
			$session->setStartSegment(0);
		} else {
			// A re-encode forces a keyframe on every boundary, so the boundaries
			// are ours, known in advance, and the whole film can be listed before
			// any of it exists — which is what makes seeking instant.
			$boundaries = $source === null
				? [0.0]
				: $this->plan->build($item, $source, $mode, $segmentDuration);
			$this->writeBoundaries($session, $boundaries);
			$session->setTotalSegments(count($boundaries));
			$session->setStartSegment($this->segmentAt($boundaries, $startSeconds));
		}
		$session = $this->sessions->insert($session);

		// Waiting for the player to ask for the first segment before starting the
		// encoder puts a whole round trip between pressing play and anything
		// happening. Starting here means the opening is usually already written
		// by the time it is asked for.
		if ($this->config->getBool('instant_start')) {
			try {
				$this->start($session, $session->getStartSegment());
			} catch (\Throwable $e) {
				// The segment request will try again and report properly.
				$this->logger->debug('Video Gallery could not start the encoder early: ' . $e->getMessage());
			}
		}
		return $session;
	}

	/** Keep the client's situation with the session, for learning from later. */
	public function recordContext(Session $session, string $signature, array $client): void {
		@file_put_contents($session->getDir() . '/context.json', (string)json_encode([
			'signature' => $signature,
			'client' => $client,
			'opened_at' => microtime(true),
		]));
	}

	/** @return array<string, mixed> */
	public function context(Session $session): array {
		$path = $session->getDir() . '/context.json';
		if (!is_file($path)) {
			return [];
		}
		$decoded = json_decode((string)file_get_contents($path), true);
		return is_array($decoded) ? $decoded : [];
	}

	/** True when the streams are passed through rather than re-encoded. */
	public function isCopyMode(string $mode): bool {
		return $mode !== PlaybackDecision::TRANSCODE;
	}

	/**
	 * Fragmented MP4 whenever the stream being packaged holds something MPEG-TS
	 * was never designed to carry.
	 */
	private function segmentTypeFor(Item $item, string $mode): string {
		if ($mode === PlaybackDecision::TRANSCODE) {
			// Everything is re-encoded to H.264, which MPEG-TS carries happily.
			return 'ts';
		}
		$codecs = [strtolower($item->getVcodec())];
		if ($mode === PlaybackDecision::REMUX) {
			$codecs[] = strtolower($item->getAcodec());
		}
		foreach ($codecs as $codec) {
			if (in_array($codec, self::NOT_IN_MPEGTS, true)) {
				return 'fmp4';
			}
		}
		return 'ts';
	}

	/**
	 * Make sure there is a local file to read, keeping a copy inside the session
	 * directory when the original lives on storage this machine cannot open
	 * directly. The copy goes when the session does.
	 */
	private function prepareSource(Session $session, ?Item $item = null): ?string {
		$marker = $session->getDir() . '/source.txt';
		if (is_file($marker)) {
			$path = trim((string)file_get_contents($marker));
			if ($path !== '' && is_file($path)) {
				return $path;
			}
		}
		$file = $this->resolver->getFile($session->getUserId(), $session->getFileId());
		if ($file === null) {
			return null;
		}
		$resolved = $this->resolver->localPath($file);
		if ($resolved === null) {
			return null;
		}
		$path = $resolved['path'];
		if ($resolved['temporary']) {
			$parked = $session->getDir() . '/source.' . pathinfo($path, PATHINFO_EXTENSION);
			if (@rename($path, $parked)) {
				$path = $parked;
			}
		}
		@file_put_contents($marker, $path);
		return $path;
	}

	// -- the playlist -------------------------------------------------------

	/** @param list<float> $boundaries */
	private function writeBoundaries(Session $session, array $boundaries): void {
		@file_put_contents($session->getDir() . '/segments.json', (string)json_encode($boundaries));
	}

	/** @return list<float> */
	public function boundaries(Session $session): array {
		$path = $session->getDir() . '/segments.json';
		if (is_file($path)) {
			$decoded = json_decode((string)file_get_contents($path), true);
			if (is_array($decoded) && $decoded !== []) {
				return array_map('floatval', $decoded);
			}
		}
		// Fall back to even spacing rather than refusing to play.
		$boundaries = [];
		$total = $session->getDurationMs() / 1000;
		for ($t = 0.0; $t < $total; $t += $session->getSegmentDur()) {
			$boundaries[] = $t;
		}
		return $boundaries === [] ? [0.0] : $boundaries;
	}

	/** @param list<float> $boundaries */
	private function segmentAt(array $boundaries, float $seconds): int {
		$index = 0;
		foreach ($boundaries as $i => $start) {
			if ($start <= $seconds + 0.001) {
				$index = $i;
			} else {
				break;
			}
		}
		return $index;
	}

	public function segmentIndexFor(Session $session, float $seconds): int {
		return $this->segmentAt($this->boundaries($session), $seconds);
	}

	/** The playlist the player is given. */
	public function playlist(Session $session): string {
		return $this->isCopyMode($session->getMode())
			? $this->copyPlaylist($session)
			: $this->plannedPlaylist($session);
	}

	/**
	 * For a copied stream, ffmpeg's own playlist, rewritten.
	 *
	 * It grows as the work proceeds, which is exactly what an event playlist is
	 * for: the player keeps re-reading it and finds more of the film each time.
	 * Copying runs many times faster than watching, so in practice the whole
	 * list appears within a second or two of pressing play.
	 */
	private function copyPlaylist(Session $session): string {
		$path = $session->getDir() . '/ffmpeg.m3u8';
		if (!is_file($path)) {
			$this->ensureRunning($session);
			$deadline = microtime(true) + 20;
			while (microtime(true) < $deadline && !is_file($path)) {
				usleep(100000);
			}
		}
		$contents = (string)@file_get_contents($path);
		if (trim($contents) === '') {
			// Nothing yet. An empty event playlist is valid, and the player will
			// come back for it.
			return "#EXTM3U\n#EXT-X-VERSION:7\n#EXT-X-TARGETDURATION:" . $session->getSegmentDur()
				. "\n#EXT-X-MEDIA-SEQUENCE:0\n#EXT-X-PLAYLIST-TYPE:EVENT\n";
		}
		$suffix = '?g=' . $session->getGeneration();
		$out = [];
		foreach (explode("\n", $contents) as $line) {
			$line = rtrim($line, "\r");
			if ($line === '') {
				continue;
			}
			if (str_starts_with($line, '#EXT-X-MAP:URI="')) {
				$out[] = '#EXT-X-MAP:URI="init.mp4' . $suffix . '"';
				continue;
			}
			// Segment file names get the generation, like the planned ones do.
			$out[] = str_starts_with($line, '#') ? $line : $line . $suffix;
		}
		return implode("\n", $out) . "\n";
	}

	/** The playlist for a re-encode: every segment of the film, existing or not. */
	private function plannedPlaylist(Session $session): string {
		$boundaries = $this->boundaries($session);
		$totalSeconds = $session->getDurationMs() / 1000;
		$durations = $this->plan->durations($boundaries, $totalSeconds);
		$fmp4 = $session->getSegmentType() === 'fmp4';

		$lines = [
			'#EXTM3U',
			'#EXT-X-VERSION:' . ($fmp4 ? '7' : '3'),
			'#EXT-X-PLAYLIST-TYPE:VOD',
			'#EXT-X-TARGETDURATION:' . max(1, (int)ceil(max($durations ?: [1.0]))),
			'#EXT-X-MEDIA-SEQUENCE:0',
			'#EXT-X-INDEPENDENT-SEGMENTS',
		];
		if ($fmp4) {
			// Fragmented MP4 keeps the stream's headers in a separate piece that
			// the player has to load before any segment will make sense.
			$lines[] = '#EXT-X-MAP:URI="init.mp4?g=' . $session->getGeneration() . '"';
		}
		$suffix = '?g=' . $session->getGeneration();
		foreach ($durations as $index => $length) {
			$lines[] = sprintf('#EXTINF:%.6f,', $length);
			// The generation is part of the address. Segment five at 1080p and
			// segment five at 480p are different files with the same number, and
			// without this a cache anywhere along the way would happily serve one
			// in place of the other.
			$lines[] = 'seg' . $index . '.' . ($fmp4 ? 'm4s' : 'ts') . $suffix;
		}
		$lines[] = '#EXT-X-ENDLIST';
		return implode("\n", $lines) . "\n";
	}

	/** A one-entry master playlist, so the player is told the bitrate up front. */
	public function master(Session $session, string $indexUrl): string {
		$rung = $this->decision->rung($session->getProfile());
		$bandwidth = (int)(($rung !== null ? $this->decision->rungKbps($rung) : 4000) * 1000);
		$resolution = '';
		if ($rung !== null && ($rung['height'] ?? 0) > 0) {
			$width = (int)round(($rung['height'] * 16 / 9) / 2) * 2;
			$resolution = ',RESOLUTION=' . $width . 'x' . $rung['height'];
		}
		return "#EXTM3U\n"
			. '#EXT-X-STREAM-INF:BANDWIDTH=' . $bandwidth . $resolution . "\n"
			. $indexUrl . "\n";
	}

	public function segmentPath(Session $session, int $index): string {
		$extension = $session->getSegmentType() === 'fmp4' ? 'm4s' : 'ts';
		return $session->getDir() . '/seg' . $index . '.' . $extension;
	}

	public function initPath(Session $session): string {
		return $session->getDir() . '/init.mp4';
	}

	public function segmentContentType(Session $session): string {
		return $session->getSegmentType() === 'fmp4' ? 'video/mp4' : 'video/mp2t';
	}

	// -- running the encoder ------------------------------------------------

	/**
	 * Make sure a segment exists, starting or restarting the encoder if that is
	 * what it takes, and waiting for it to appear.
	 */
	public function ensureSegment(Session $session, int $index): ?string {
		$path = $this->segmentPath($session, $index);
		if (is_file($path)) {
			$this->onSegmentServed($session, $index);
			return $path;
		}
		if ($index < 0) {
			return null;
		}

		if ($this->isCopyMode($session->getMode())) {
			// There is one pass through the file and it only goes forwards, so
			// the only thing to do about a segment that is not there yet is to
			// make sure the pass is running, let it off its leash, and wait.
			$this->ensureRunning($session);
			$this->resume($session);
			$found = $this->waitForSegment($session, $index, $this->tuning->segmentWait(true));
			if ($found !== null) {
				$this->onSegmentServed($session, $index);
			}
			return $found;
		}

		if ($index >= $session->getTotalSegments()) {
			return null;
		}

		$running = $session->getPid() > 0 && $this->janitor->isAlive($session->getPid());
		$reachable = $running
			&& $index >= $session->getStartSegment()
			&& $index <= $this->highestSegment($session) + $this->tuning->restartDistance();

		if (!$reachable) {
			// Either nothing is running, or the viewer has jumped somewhere the
			// current encoder will not reach for a long time.
			$this->start($session, $index);
		} else {
			$this->resume($session);
		}

		$path = $this->waitForSegment($session, $index);
		if ($path === null) {
			$path = $this->recoverAndRetry($session, $index);
		}
		if ($path !== null) {
			$this->onSegmentServed($session, $index);
		}
		return $path;
	}

	/**
	 * Give it one more go on the processor when the graphics card turns out not
	 * to be usable after all.
	 *
	 * Hardware encoding can be present, pass its test, and still fail here,
	 * because the web server may be running under restrictions the test did not
	 * face. Rather than showing the viewer an error about CUDA, the stream is
	 * simply built the slower way, and what was learned is written down so the
	 * next file does not repeat the discovery.
	 */
	private function recoverAndRetry(Session $session, int $index): ?string {
		if (!$this->ffmpeg->isHardware($session->getEncoder())) {
			return null;
		}
		if (!$this->hardwareTroubleInLog($session)) {
			return null;
		}
		$reason = $this->firstTrouble($session) ?: $this->lastError($session);

		// Give up the card a piece at a time rather than all at once. Decoding
		// on it is the fragile half — how many frames a card will hold at once
		// varies, and the ffmpeg build and the driver have to agree about it —
		// while the encoder usually carries on working perfectly. Dropping only
		// the decoding costs a fraction of the speed; dropping the whole card
		// costs most of it.
		$noDecode = $session->getDir() . '/no-gpu-decode';
		if (!is_file($noDecode)) {
			@file_put_contents($noDecode, $reason);
			$this->logger->warning('Video Gallery could not decode on the graphics card, and is decoding on the processor instead: {reason}', ['reason' => $reason]);
			$this->restartAfterFallback($session, $index);
			$found = $this->waitForSegment($session, $index);
			if ($found !== null) {
				return $found;
			}
			$reason = $this->lastError($session);
		}

		$fellBack = $session->getDir() . '/fell-back';
		if (is_file($fellBack)) {
			return null;
		}
		@file_put_contents($fellBack, (string)time());
		$this->logger->warning('Video Gallery could not use {encoder} either, and is converting entirely on the processor: {reason}', [
			'encoder' => $session->getEncoder(),
			'reason' => $reason,
		]);
		// Test the hardware again in this context, so the next viewer is not sent
		// down the same dead end.
		try {
			$this->ffmpeg->capabilities(true);
		} catch (\Throwable) {
			// The retry below matters more than the bookkeeping.
		}
		$session->setEncoder('software');
		$this->restartAfterFallback($session, $index);
		return $this->waitForSegment($session, $index);
	}

	private function restartAfterFallback(Session $session, int $index): void {
		$session->setState(Session::STARTING);
		$session->setError(null);
		$session->setPid(0);
		$this->sessions->update($session);
		$this->start($session, $index);
	}

	/**
	 * Whether the encoder ran into trouble with the card, anywhere in its log.
	 *
	 * The tail of a log is the wrong place to look for this. What goes wrong
	 * first is the decoder losing its frames, and what is printed last is some
	 * downstream consequence of that — a filter that cannot be fed, a queue that
	 * would not drain — which reads like a quite different problem. The whole
	 * log is read instead, and the first real complaint in it is the one worth
	 * repeating to anybody.
	 */
	private function hardwareTroubleInLog(Session $session): bool {
		return $this->firstTrouble($session) !== null;
	}

	/** The first line in the log that names a hardware problem. */
	private function firstTrouble(Session $session): ?string {
		$log = $session->getDir() . '/ffmpeg.log';
		if (!is_file($log)) {
			return null;
		}
		$handle = @fopen($log, 'rb');
		if (!is_resource($handle)) {
			return null;
		}
		try {
			$read = 0;
			while (($line = fgets($handle)) !== false && $read < 262144) {
				$read += strlen($line);
				$line = trim($line);
				if ($line !== '' && $this->looksLikeHardwareFailure($line)) {
					return mb_substr($line, 0, 300);
				}
			}
		} finally {
			fclose($handle);
		}
		return null;
	}

	/** Whether an encoder's complaint is about the hardware rather than the file. */
	private function looksLikeHardwareFailure(string $message): bool {
		$message = strtolower($message);
		foreach ([
			// The card refusing to start at all.
			'cuda', 'cuvid', 'nvenc', 'no capable devices', 'no device available',
			'device creation failed', 'vaapi', 'qsv', 'failed to initialise',
			'cannot load libcuda', 'driver version', 'no such device', 'permission denied',
			// The card running out of room part way through, which is the more
			// common failure and reads nothing like the others.
			'surfaces left', 'no free surface', 'decoder surfaces',
			'hwaccel initialisation returned error', 'failed setup for format',
			'impossible to convert between the formats',
			'out of memory', 'cannot allocate memory',
			// What the rest of the pipeline says once the card has let it down.
			'inject frame into filter network', 'function not implemented',
			'error while decoding stream',
		] as $needle) {
			if (str_contains($message, $needle)) {
				return true;
			}
		}
		return false;
	}

	/** Start the pass if it is not already going. */
	private function ensureRunning(Session $session): void {
		if ($session->getPid() > 0 && $this->janitor->isAlive($session->getPid())) {
			return;
		}
		$this->start($session, $session->getStartSegment());
	}

	/** The initialisation segment, which only fragmented MP4 streams have. */
	public function ensureInit(Session $session): ?string {
		$path = $this->initPath($session);
		if (is_file($path)) {
			return $path;
		}
		// It is written alongside the first segment, so producing that produces it.
		$this->ensureSegment($session, $session->getStartSegment());
		$deadline = microtime(true) + 10;
		while (microtime(true) < $deadline) {
			if (is_file($path)) {
				return $path;
			}
			usleep(100000);
		}
		return null;
	}

	/** Start, or restart, the encoder at a given segment. */
	public function start(Session $session, int $fromSegment): void {
		if ($session->getPid() > 0) {
			// Segments already written stay where they are. They were cut on the
			// same boundaries with the same settings, so they are still exactly
			// what the playlist promises — and keeping them means that seeking
			// back to a part already watched costs nothing at all.
			$this->janitor->endSession($session, 'restarting at a new position');
			$this->clearPartials($session);
		}
		$input = $this->prepareSource($session);
		if ($input === null) {
			$this->fail($session, 'the file could not be opened for reading');
			return;
		}

		try {
			$args = $this->buildArgs($session, $input, $fromSegment);
		} catch (\Throwable $e) {
			$this->fail($session, $e->getMessage());
			return;
		}
		$pid = $this->spawn($session, $args);
		if ($pid <= 0) {
			$this->fail($session, 'the encoder did not start');
			return;
		}
		$session->setPid($pid);
		$session->setStartSegment($fromSegment);
		$session->setState(Session::RUNNING);
		$session->setLastSeen(time());
		$session->setError(null);
		$this->sessions->update($session);
	}

	/**
	 * The ffmpeg command for this session.
	 *
	 * @return list<string>
	 */
	private function buildArgs(Session $session, string $input, int $fromSegment): array {
		$binary = $this->ffmpeg->ffmpeg();
		if ($binary === null) {
			throw new \RuntimeException('ffmpeg is not available.');
		}
		$boundaries = $this->boundaries($session);
		$offset = $boundaries[$fromSegment] ?? ($fromSegment * $session->getSegmentDur());
		$segmentDuration = $session->getSegmentDur();
		$mode = $session->getMode();
		$family = $session->getEncoder() ?: $this->ffmpeg->chosenEncoder();
		$rung = $this->decision->rung($session->getProfile());
		$videoKbps = (int)($rung['bitrate'] ?? 0);
		$fullTranscode = $mode === PlaybackDecision::TRANSCODE;
		$size = $this->targetSize($session, (int)($rung['height'] ?? 0));
		// Decoding on the card is used only where it has been shown to work, and
		// never for a video shot sideways: applying a rotation is a filter that
		// only works on frames in main memory, and there is no step in the
		// graphics pipeline to match it.
		$onCard = $fullTranscode
			&& $this->config->getBool('hw_decode')
			&& $this->ffmpeg->canDecodeOnCard()
			&& !$this->hasRotation($session)
			&& !is_file($session->getDir() . '/no-gpu-decode');
		$hwDecode = $onCard;

		$args = [$binary, '-hide_banner', '-loglevel', 'warning', '-nostdin', '-y'];
		$decoderThreads = $this->config->getInt('decoder_threads');
		if ($decoderThreads > 0) {
			// Fewer decoding threads means fewer frames held at once, which is
			// what a card short of surfaces needs.
			$args = array_merge($args, ['-threads', (string)$decoderThreads]);
		}

		// Hardware decoding, where the encoder takes the frames directly and they
		// never have to come back to main memory.
		if ($hwDecode && $family === 'nvenc') {
			$args = array_merge($args, ['-hwaccel', 'cuda']);
			if ($onCard) {
				$args = array_merge($args, ['-hwaccel_output_format', 'cuda']);
			}
			if ($session->getDevice() > 0) {
				$args = array_merge($args, ['-hwaccel_device', (string)$session->getDevice()]);
			}
			// Frames stay on the card, and the encoder holds on to a good many of
			// them at once for its lookahead and its B-frames. Without room set
			// aside for that, the decoder runs out of surfaces part way through
			// and the whole conversion collapses with "No decoder surfaces left".
			$args = array_merge($args, ['-extra_hw_frames', (string)$this->tuning->extraHwFrames()]);
		} elseif ($hwDecode && $family === 'vaapi') {
			$args = array_merge($args, [
				'-hwaccel', 'vaapi',
				'-hwaccel_device', $this->config->getString('vaapi_device'),
				'-extra_hw_frames', (string)$this->tuning->extraHwFrames(),
			]);
			if ($onCard) {
				$args = array_merge($args, ['-hwaccel_output_format', 'vaapi']);
			}
		} elseif ($hwDecode && $family === 'qsv') {
			$args = array_merge($args, ['-hwaccel', 'qsv', '-extra_hw_frames', (string)$this->tuning->extraHwFrames()]);
			if ($onCard) {
				$args = array_merge($args, ['-hwaccel_output_format', 'qsv']);
			}
		}

		// Only a re-encode is ever started part way in. A copied stream runs once,
		// from the beginning: seeking into it means waiting for the pass to reach
		// that point, which takes moments, and starting it elsewhere would land
		// on whatever seek point the container happens to hold rather than the
		// one the playlist promised.
		if ($offset > 0 && !$this->isCopyMode($mode)) {
			// Seeking before the input is the fast form: ffmpeg jumps in the file
			// rather than decoding its way there.
			$args = array_merge($args, ['-ss', sprintf('%.6f', $offset)]);
		}
		$args = array_merge($args, ['-i', $input]);
		// Timestamps keep their original values, so a stream started in the middle
		// still lines up with the playlist the player is already holding.
		$args = array_merge($args, ['-copyts', '-avoid_negative_ts', 'disabled', '-start_at_zero']);

		$args = array_merge($args, ['-map', '0:v:0']);
		$audioIndex = $session->getAudioIndex();
		$args = array_merge($args, ['-map', $audioIndex >= 0 ? '0:' . $audioIndex : '0:a:0?']);
		$args = array_merge($args, ['-sn', '-dn', '-map_chapters', '-1']);

		if ($fullTranscode) {
			$args = array_merge($args, $this->videoArgs($family, $size, $videoKbps, $segmentDuration, $onCard));
		} else {
			$args = array_merge($args, ['-c:v', 'copy']);
		}
		if ($mode === PlaybackDecision::REMUX) {
			$args = array_merge($args, ['-c:a', 'copy']);
		} else {
			$audio = $this->tuning->audio();
			$args = array_merge($args, [
				'-c:a', $audio['codec'],
				'-b:a', $audio['bitrate'] . 'k',
				'-ac', (string)$audio['channels'],
				'-af', 'aresample=async=1',
			]);
		}

		$dir = $session->getDir();
		$fmp4 = $session->getSegmentType() === 'fmp4';
		$copying = $this->isCopyMode($mode);
		$args = array_merge($args, [
			'-f', 'hls',
			'-hls_time', (string)$segmentDuration,
			// An event playlist is written as the work proceeds; a VOD one only
			// at the end, which would be no use to a player waiting to start.
			'-hls_playlist_type', $copying ? 'event' : 'vod',
			'-hls_segment_type', $fmp4 ? 'fmp4' : 'mpegts',
			'-hls_list_size', '0',
			// temp_file means a segment only appears under its real name once it
			// is complete, so a half-written one is never served.
			'-hls_flags', 'independent_segments+temp_file',
			'-start_number', (string)($copying ? 0 : $fromSegment),
		]);
		if ($fmp4) {
			$args = array_merge($args, [
				'-hls_fmp4_init_filename', 'init.mp4',
				'-hls_segment_filename', $dir . '/seg%d.m4s',
			]);
		} else {
			$args = array_merge($args, ['-hls_segment_filename', $dir . '/seg%d.ts']);
		}
		$args[] = $dir . '/ffmpeg.m3u8';
		return $args;
	}

	/**
	 * The picture size to encode at.
	 *
	 * A quality rung names a number of lines — 720p, 1080p — and for a landscape
	 * video that is simply its height. For one shot on a phone held upright it is
	 * not: reading it as height there would squeeze the narrow side down to a few
	 * hundred pixels and make a portrait clip look far worse than a landscape one
	 * at the same setting. So the rung is applied to the shorter side either way,
	 * which is what every video service means by it.
	 *
	 * @return array{width: int, height: int}|null null when the source is already
	 *         at or below the rung and should be left alone
	 */
	private function targetSize(Session $session, int $rungHeight): ?array {
		if ($rungHeight <= 0) {
			return null;
		}
		$item = $this->items->find($session->getUserId(), $session->getFileId());
		if ($item === null || $item->getWidth() <= 0 || $item->getHeight() <= 0) {
			// Nothing to reason from; scale by height and keep the aspect ratio.
			return ['width' => -2, 'height' => $rungHeight];
		}
		// Rotation is applied before any filter of ours runs, so these are the
		// dimensions the frame will already have by the time it is scaled.
		$width = $item->displayWidth();
		$height = $item->displayHeight();
		$shortSide = min($width, $height);
		if ($shortSide <= $rungHeight) {
			return null;
		}
		$scale = $rungHeight / $shortSide;
		return [
			'width' => $this->even((int)round($width * $scale)),
			'height' => $this->even((int)round($height * $scale)),
		];
	}

	/** Whether this file is one shot sideways. */
	private function hasRotation(Session $session): bool {
		$item = $this->items->find($session->getUserId(), $session->getFileId());
		return $item !== null && $item->getRotation() !== 0;
	}

	/** H.264 requires even dimensions. */
	private function even(int $value): int {
		return max(2, $value - ($value % 2));
	}

	/**
	 * @param array{width: int, height: int}|null $size null to leave the picture at its own size
	 * @return list<string>
	 */
	private function videoArgs(string $family, ?array $size, int $videoKbps, int $segmentDuration, bool $onCard): array {
		$factors = $this->tuning->rateFactors();
		$maxrate = (int)round($videoKbps * $factors['maxrate']);
		$bufsize = (int)round($videoKbps * $factors['bufsize']);
		$dimensions = $size === null ? '' : $size['width'] . ':' . $size['height'];
		// A keyframe exactly on every boundary is what lets a segment stand on
		// its own, and what makes the boundaries ours to choose.
		$keyframes = ['-force_key_frames', 'expr:gte(t,n_forced*' . $segmentDuration . ')'];

		return match ($family) {
			'nvenc' => array_merge(
				$dimensions !== '' ? ['-vf', ($onCard ? 'scale_cuda' : 'scale') . '=' . $dimensions] : [],
				['-c:v', 'h264_nvenc',
					'-preset', $this->tuning->nvencPreset(),
					'-tune', $this->config->getString('nvenc_tune'),
					'-rc', $this->config->getString('nvenc_rc'),
					'-cq', (string)$this->config->getInt('nvenc_cq'),
					'-profile:v', $this->config->getString('nvenc_profile')],
				// Looking ahead lets the encoder spend its bits more wisely, and
				// it pays for that by holding frames. When the decoder is on the
				// same card those frames come out of the same small pool and it
				// starves, so the allowance is separate for the two cases.
				$this->tuning->lookahead($onCard) > 0 ? ['-rc-lookahead', (string)$this->tuning->lookahead($onCard)] : [],
				['-bf', (string)$this->tuning->bFrames($onCard)],
				$this->config->getString('nvenc_multipass') !== 'disabled'
					? ['-multipass', $this->config->getString('nvenc_multipass')] : [],
				$this->config->getBool('nvenc_spatial_aq') ? ['-spatial-aq', '1'] : [],
				$videoKbps > 0 ? ['-b:v', $videoKbps . 'k', '-maxrate', $maxrate . 'k', '-bufsize', $bufsize . 'k'] : [],
				$keyframes,
			),
			'vaapi' => array_merge(
				['-vf', ($onCard ? '' : 'format=nv12,hwupload,') . ($dimensions !== '' ? 'scale_vaapi=' . $dimensions : 'scale_vaapi')],
				['-c:v', 'h264_vaapi', '-profile:v', 'high'],
				$this->config->getInt('vaapi_quality') > 0 ? ['-quality', (string)$this->config->getInt('vaapi_quality')] : [],
				$videoKbps > 0 ? ['-b:v', $videoKbps . 'k', '-maxrate', $maxrate . 'k'] : [],
				$keyframes,
			),
			'qsv' => array_merge(
				$dimensions !== '' ? ['-vf', 'scale_qsv=' . $dimensions] : [],
				['-c:v', 'h264_qsv', '-preset', $this->config->getString('qsv_preset')],
				$videoKbps > 0 ? ['-b:v', $videoKbps . 'k', '-maxrate', $maxrate . 'k'] : [],
				$keyframes,
			),
			'videotoolbox' => array_merge(
				$dimensions !== '' ? ['-vf', 'scale=' . $dimensions] : [],
				['-c:v', 'h264_videotoolbox', '-profile:v', 'high'],
				$videoKbps > 0 ? ['-b:v', $videoKbps . 'k'] : [],
				$keyframes,
			),
			default => array_merge(
				$dimensions !== '' ? ['-vf', 'scale=' . $dimensions] : [],
				['-c:v', 'libx264',
					'-preset', $this->config->getString('x264_preset'),
					'-crf', (string)$this->config->getInt('x264_crf'),
					'-profile:v', 'high', '-pix_fmt', 'yuv420p'],
				$videoKbps > 0 ? ['-maxrate', $maxrate . 'k', '-bufsize', $bufsize . 'k'] : [],
				$keyframes,
			),
		};
	}

	/**
	 * Run ffmpeg detached, so it outlives the request that started it.
	 *
	 * @param list<string> $args
	 * @return int the process id, or 0 if it would not start
	 */
	private function spawn(Session $session, array $args): int {
		if (!$this->ffmpeg->canRunProcesses()) {
			return 0;
		}
		$dir = $session->getDir();
		$log = $dir . '/ffmpeg.log';
		$pidFile = $dir . '/ffmpeg.pid';
		@unlink($pidFile);

		$prefix = [];
		$nice = $this->config->getInt('nice_level');
		if ($nice > 0 && is_executable('/usr/bin/nice')) {
			$prefix = ['/usr/bin/nice', '-n', (string)$nice];
			$ioClass = $this->config->getString('io_class');
			if ($ioClass !== '' && is_executable('/usr/bin/ionice')) {
				$prefix = array_merge(['/usr/bin/ionice', '-c', $ioClass === 'idle' ? '3' : '2'], $prefix);
			}
		}
		$command = implode(' ', array_map('escapeshellarg', array_merge($prefix, $args)));
		// Backgrounded from a shell so the process is handed to init and keeps
		// running once this request is gone; the shell reports its id back to us.
		$shell = $command . ' > ' . escapeshellarg($log) . ' 2>&1 & echo $! > ' . escapeshellarg($pidFile);

		$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$process = @proc_open(['/bin/sh', '-c', $shell], $descriptors, $pipes, $dir, $this->ffmpeg->environment());
		if (!is_resource($process)) {
			return 0;
		}
		foreach ($pipes as $pipe) {
			if (is_resource($pipe)) {
				fclose($pipe);
			}
		}
		proc_close($process);

		for ($i = 0; $i < 50; $i++) {
			$pid = (int)trim((string)@file_get_contents($pidFile));
			if ($pid > 0) {
				return $pid;
			}
			usleep(20000);
		}
		return 0;
	}

	/** Wait for a segment to be written, giving up rather than hanging a request. */
	private function waitForSegment(Session $session, int $index, ?int $timeoutSeconds = null): ?string {
		$timeoutSeconds ??= $this->tuning->segmentWait($this->isCopyMode($session->getMode()));
		$path = $this->segmentPath($session, $index);
		$deadline = microtime(true) + $timeoutSeconds;
		$checkedLog = 0.0;
		while (microtime(true) < $deadline) {
			if (is_file($path)) {
				return $path;
			}
			// An encoder that cannot get frames from the card does not stop: it
			// keeps going and keeps complaining, and waiting out the whole
			// timeout to discover that wastes half a minute of somebody's
			// evening. The log is glanced at instead.
			if (microtime(true) - $checkedLog > 2.0) {
				$checkedLog = microtime(true);
				if ($this->highestSegment($session) === $session->getStartSegment()
					&& !is_file($this->segmentPath($session, $session->getStartSegment()))
					&& $this->hardwareTroubleInLog($session)) {
					return null;
				}
			}
			if ($session->getPid() > 0 && !$this->janitor->isAlive($session->getPid())) {
				// The encoder is gone. If it finished the job the file will be
				// there; otherwise something went wrong and the log will say so.
				usleep(200000);
				if (is_file($path)) {
					return $path;
				}
				$this->fail($session, $this->lastError($session));
				return null;
			}
			usleep(150000);
		}
		return is_file($path) ? $path : null;
	}

	/** The highest numbered segment written so far. */
	public function highestSegment(Session $session): int {
		$highest = $session->getStartSegment();
		$extension = $session->getSegmentType() === 'fmp4' ? 'm4s' : 'ts';
		foreach (@glob($session->getDir() . '/seg*.' . $extension) ?: [] as $file) {
			if (preg_match('/seg(\d+)\.' . $extension . '$/', $file, $m)) {
				$highest = max($highest, (int)$m[1]);
			}
		}
		return $highest;
	}

	/**
	 * Keep the encoder from running away with the whole film.
	 *
	 * Once it is far enough ahead of what the viewer is watching it is stopped
	 * where it stands, and started again when they catch up. Someone who opens a
	 * two hour film and watches five minutes never pays for the other hour and
	 * fifty-five.
	 */
	public function throttle(Session $session, float $playbackSeconds): void {
		if ($session->getPid() <= 0 || !$this->janitor->isAlive($session->getPid())) {
			return;
		}
		$ahead = $this->tuning->throttleAhead();
		$aheadSeconds = $this->producedSeconds($session) - $playbackSeconds;
		if ($aheadSeconds > $ahead) {
			$this->pause($session);
		} elseif ($aheadSeconds < $ahead * 0.6) {
			$this->resume($session);
		}
	}

	private function pause(Session $session): void {
		$this->janitor->pauseProcess($session->getPid());
	}

	public function resume(Session $session): void {
		$this->janitor->resumeProcess($session->getPid());
	}

	/**
	 * Change quality without interrupting the viewer: the old encoder stops, the
	 * session takes on the new profile, and playback carries on from where it was.
	 */
	public function switchProfile(Session $session, string $profile, float $atSeconds): Session {
		if ($session->getProfile() === $profile) {
			return $session;
		}
		$this->janitor->endSession($session, 'quality change');
		$this->clearSegments($session);

		$wasTranscoding = $session->getMode() === PlaybackDecision::TRANSCODE;
		$session->setGeneration($session->getGeneration() + 1);
		$session->setProfile($profile);
		$session->setMode(PlaybackDecision::TRANSCODE);
		$session->setSegmentType('ts');
		$session->setPid(0);
		$session->setState(Session::STARTING);
		if (!$wasTranscoding) {
			// Coming from a copied stream, the segment boundaries were the file's
			// own keyframes. Re-encoding puts them wherever we choose, so the plan
			// is redrawn and the player told to reload the playlist.
			$total = $session->getDurationMs() / 1000;
			$boundaries = [];
			for ($t = 0.0; $t < $total; $t += $session->getSegmentDur()) {
				$boundaries[] = $t;
			}
			$this->writeBoundaries($session, $boundaries === [] ? [0.0] : $boundaries);
			$session->setTotalSegments(max(1, count($boundaries)));
		}
		$session->setStartSegment($this->segmentAt($this->boundaries($session), $atSeconds));
		$session->setLastSeen(time());
		return $this->sessions->update($session);
	}

	/** Everything produced so far, for when it is no longer valid. */
	private function clearSegments(Session $session): void {
		foreach (['ts', 'm4s'] as $extension) {
			foreach (@glob($session->getDir() . '/seg*.' . $extension) ?: [] as $file) {
				@unlink($file);
			}
		}
		$this->clearPartials($session);
		@unlink($session->getDir() . '/init.mp4');
	}

	/** Half-written pieces left by an encoder that was stopped mid-segment. */
	private function clearPartials(Session $session): void {
		foreach (@glob($session->getDir() . '/*.tmp') ?: [] as $file) {
			@unlink($file);
		}
	}

	private function onSegmentServed(Session $session, int $index): void {
		$this->sessions->touch($session->getToken());
		$at = $this->isCopyMode($session->getMode())
			? $index * $session->getSegmentDur()
			: ($this->boundaries($session)[$index] ?? ($index * $session->getSegmentDur()));
		$this->throttle($session, $at);
	}

	/**
	 * How far into the film the work has got.
	 *
	 * For a re-encode the boundaries are known, so it is a lookup. For a copied
	 * stream the only record of where the cuts fell is the playlist ffmpeg has
	 * been writing, so the lengths in it are added up.
	 */
	public function producedSeconds(Session $session): float {
		if (!$this->isCopyMode($session->getMode())) {
			$boundaries = $this->boundaries($session);
			$next = $this->highestSegment($session) + 1;
			return (float)($boundaries[$next] ?? ($session->getDurationMs() / 1000));
		}
		$path = $session->getDir() . '/ffmpeg.m3u8';
		if (!is_file($path)) {
			return 0.0;
		}
		$total = 0.0;
		foreach (explode("\n", (string)@file_get_contents($path)) as $line) {
			if (preg_match('/^#EXTINF:([0-9.]+)/', $line, $m)) {
				$total += (float)$m[1];
			}
		}
		return $total;
	}

	/** How many segments the copied stream's playlist lists so far. */
	public function listedSegments(Session $session): int {
		$path = $session->getDir() . '/ffmpeg.m3u8';
		if (!is_file($path)) {
			return 0;
		}
		return substr_count((string)@file_get_contents($path), '#EXTINF:');
	}

	private function fail(Session $session, string $reason): void {
		$session->setState(Session::FAILED);
		$session->setError(mb_substr($reason, 0, 990));
		$this->sessions->update($session);
		$this->logger->warning('Video Gallery playback failed for file {file}: {reason}', [
			'file' => $session->getFileId(),
			'reason' => $reason,
		]);
	}

	/** The tail of the encoder log, for the error shown to the viewer. */
	public function lastError(Session $session): string {
		$log = $session->getDir() . '/ffmpeg.log';
		if (!is_file($log)) {
			return 'the encoder stopped without saying why';
		}
		$contents = (string)@file_get_contents($log);
		$lines = array_values(array_filter(array_map('trim', explode("\n", $contents))));
		if ($lines === []) {
			return 'the encoder stopped without saying why';
		}
		return mb_substr(implode(' | ', array_slice($lines, -3)), 0, 500);
	}

	/** How many encoder slots are in use right now. */
	public function activeCount(): int {
		return $this->sessions->countActiveEncoders();
	}

	public function slotAvailable(): bool {
		return $this->activeCount() < $this->tuning->totalSessionLimit();
	}
}
