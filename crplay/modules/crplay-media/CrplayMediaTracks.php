<?php

namespace XcVm\Module\CrplayMedia;

use XcVm\Domain\Stream\StreamProcess;
use XcVm\Infrastructure\Database\DatabaseAware;
use XcVm\Streaming\Codec\FFprobeRunner;

/**
 * CrplayMediaTracks — every audio and subtitle track of a movie or episode.
 *
 * Core keeps only the first audio track (FFprobeRunner::parseFFProbe) and the
 * Xtream API drops `audio`/`video`/`subtitle` from `info`. This class probes the
 * VOD file again, keeps every track in `crplay_media_tracks`, and merges the
 * result into `streams`.`movie_properties` under keys core does not touch:
 *
 *   audio_languages    ["spa", "eng"]  (tagged languages, in track order, no duplicates)
 *   subtitle_languages ["spa"]
 *   audio_tracks       [{index, codec, language, title, channels, default}, …]
 *   subtitle_tracks    [{index, codec, language, title, forced}, …]
 *
 * get_vod_info copies `movie_properties` into `info`, and get_series_info into
 * each episode's `info`, so the apps receive these keys with no API change.
 *
 * Rows are always read with get_raw_row()/get_raw_rows(): get_row()/get_rows()
 * pass every value through Database::clean_row() (HTML escaping, trim), which
 * would corrupt the JSON and break the optimistic comparison.
 *
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class CrplayMediaTracks {
	use DatabaseAware;

	/** `movie_properties` keys this module owns. */
	public const KEYS = ['audio_languages', 'subtitle_languages', 'audio_tracks', 'subtitle_tracks'];

	/** `streams`.`type`: 2 movie, 5 episode. */
	private const VOD_TYPES = [2, 5];

	/** Optimistic writes of `movie_properties` tried before giving up. */
	private const MERGE_TRIES = 3;

	/** Longest wait before a failed probe is tried again (seconds). */
	private const MAX_RETRY_WAIT = 86400;

	/** probe() results. */
	public const PROBE_STORED = 1;        // tracks stored and merged
	public const PROBE_MERGE_FAILED = 2;  // tracks stored, merge failed (cron:crplay_media retries it)
	public const PROBE_FAILED = 3;        // probe failed: recorded, retried with growing waits
	public const PROBE_SKIPPED = 4;       // not a local VOD, or another process holds the stream

	/** Seconds merge() waits for a stream's lock held by a probe. */
	private const MERGE_LOCK_WAIT = 5;

	/**
	 * Probe a movie/episode file on this server, store its tracks and merge them.
	 * $rWait: seconds to wait for a probe or merge of the same stream already
	 * running (the analysis event waits, so it is not lost; backfill does not).
	 * Returns one of the PROBE_* results. Any failure of the probe or of storing
	 * its tracks is recorded under the stream's lock (failed()): the last good
	 * tracks stay and the probe is retried with growing waits.
	 */
	public static function probe(int $rStreamID, int $rWait = 0): int {
		$rResult = self::withLock($rStreamID, $rWait, static function () use ($rStreamID): int {
			$rStarted = time();
			try {
				return self::probeLocked($rStreamID, $rStarted);
			} catch (\Throwable $e) {
				error_log('crplay-media: probe of stream ' . $rStreamID . ' failed: ' . $e->getMessage());
				self::failed($rStreamID, $e->getMessage(), $rStarted);
				return self::PROBE_FAILED;
			}
		});
		if ($rResult === null && $rWait > 0) {
			// The analysis event could not wait any longer: leave a pending probe
			// that backfill() runs, so the new analysis is not lost.
			self::run(
				'INSERT INTO `crplay_media_tracks` (`stream_id`, `pending_since`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `pending_since` = GREATEST(`pending_since`, VALUES(`pending_since`));',
				$rStreamID,
				time()
			);
		}
		return $rResult ?? self::PROBE_SKIPPED;
	}

	/** @param int $rStarted when the lock was taken: a pending probe asked for in the same second or later stays pending */
	private static function probeLocked(int $rStreamID, int $rStarted): int {
		self::run('SELECT `type`, `target_container`, `direct_source` FROM `streams` WHERE `id` = ?;', $rStreamID);
		$rStream = self::db()->get_raw_row();
		if (empty($rStream) || !in_array(intval($rStream['type']), self::VOD_TYPES, true) || intval($rStream['direct_source']) === 1) {
			return self::PROBE_SKIPPED;
		}
		$rContainer = preg_replace('/[^a-z0-9]/i', '', (string) $rStream['target_container']);
		$rPath = VOD_PATH . $rStreamID . '.' . $rContainer;
		if ($rContainer === '' || !is_file($rPath)) {
			self::failed($rStreamID, 'file not found', $rStarted);
			return self::PROBE_FAILED;
		}
		$rProbe = FFprobeRunner::probeStream($rPath, [], '', false);
		if (!is_array($rProbe) || !isset($rProbe['streams']) || !is_array($rProbe['streams']) || $rProbe['streams'] === []) {
			self::failed($rStreamID, 'ffprobe returned no streams', $rStarted);
			return self::PROBE_FAILED;
		}
		[$rAudio, $rSubtitles] = self::extract($rProbe['streams']);
		self::run(
			'INSERT INTO `crplay_media_tracks` (`stream_id`, `audio_tracks`, `subtitle_tracks`, `probed_at`, `attempts`, `retry_at`, `last_error`) VALUES (?, ?, ?, ?, 0, 0, NULL) '
			. 'ON DUPLICATE KEY UPDATE `audio_tracks` = VALUES(`audio_tracks`), `subtitle_tracks` = VALUES(`subtitle_tracks`), `probed_at` = VALUES(`probed_at`), `attempts` = 0, `retry_at` = 0, `last_error` = NULL, '
			. '`pending_since` = IF(`pending_since` >= ?, `pending_since`, 0);',
			$rStreamID,
			json_encode($rAudio, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
			json_encode($rSubtitles, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
			time(),
			$rStarted
		);
		// The stream may have been deleted while ffprobe ran (its row was forgotten then).
		self::run('SELECT `id` FROM `streams` WHERE `id` = ?;', $rStreamID);
		if (self::db()->get_raw_row() === null) {
			self::forget([$rStreamID]);
			return self::PROBE_SKIPPED;
		}
		try {
			self::mergeLocked($rStreamID);
		} catch (\Throwable $e) {
			// The tracks are stored: cron:crplay_media merges them on its next pass.
			error_log('crplay-media: merge of stream ' . $rStreamID . ' failed: ' . $e->getMessage());
			return self::PROBE_MERGE_FAILED;
		}
		return self::PROBE_STORED;
	}

	/**
	 * The audio and subtitle tracks of an ffprobe `streams` list.
	 *
	 * @param array<int, mixed> $rStreams
	 * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
	 */
	public static function extract(array $rStreams): array {
		$rAudio = [];
		$rSubtitles = [];
		foreach ($rStreams as $rStream) {
			if (!is_array($rStream)) {
				continue;
			}
			$rTags = (isset($rStream['tags']) && is_array($rStream['tags'])) ? $rStream['tags'] : [];
			$rDisposition = (isset($rStream['disposition']) && is_array($rStream['disposition'])) ? $rStream['disposition'] : [];
			$rTrack = [
				'index'    => intval($rStream['index'] ?? 0),
				'codec'    => self::text($rStream['codec_name'] ?? '', 32),
				'language' => self::language($rTags['language'] ?? ''),
				'title'    => self::text($rTags['title'] ?? '', 200),
			];
			$rType = $rStream['codec_type'] ?? '';
			if ($rType === 'audio') {
				$rTrack['channels'] = intval($rStream['channels'] ?? 0);
				$rTrack['default'] = intval($rDisposition['default'] ?? 0) === 1;
				$rAudio[] = $rTrack;
			} elseif ($rType === 'subtitle') {
				$rTrack['forced'] = intval($rDisposition['forced'] ?? 0) === 1;
				$rSubtitles[] = $rTrack;
			}
		}
		return [$rAudio, $rSubtitles];
	}

	/**
	 * Distinct tagged languages of a track list, in track order ("und" left out).
	 *
	 * @param list<array<string, mixed>> $rTracks
	 * @return list<string>
	 */
	public static function languages(array $rTracks): array {
		$rLanguages = [];
		foreach ($rTracks as $rTrack) {
			$rLanguage = is_array($rTrack) ? (string) ($rTrack['language'] ?? '') : '';
			if ($rLanguage !== '' && $rLanguage !== 'und' && !in_array($rLanguage, $rLanguages, true)) {
				$rLanguages[] = $rLanguage;
			}
		}
		return $rLanguages;
	}

	/**
	 * Copy the stored tracks into `movie_properties` when they differ.
	 *
	 * The write only lands if the document is still the one read (optimistic
	 * check), so a TMDb or analysis write in between is never reverted: the
	 * merge reads again and retries. The document is handled as a JSON object
	 * (stdClass), so nested objects and lists keep their shape; a document that
	 * is not a JSON object is left alone, an empty one (NULL or "") is started.
	 * Returns true when the row was rewritten.
	 *
	 * @throws \RuntimeException|\JsonException when the merge cannot be done safely
	 */
	public static function merge(int $rStreamID): bool {
		$rResult = self::withLock($rStreamID, self::MERGE_LOCK_WAIT, static fn (): bool => self::mergeLocked($rStreamID));
		if ($rResult === null) {
			throw new \RuntimeException('stream ' . $rStreamID . ' is locked by a probe; merge postponed');
		}
		return $rResult;
	}

	private static function mergeLocked(int $rStreamID): bool {
		self::run('SELECT `audio_tracks`, `subtitle_tracks`, `cache_pending` FROM `crplay_media_tracks` WHERE `stream_id` = ?;', $rStreamID);
		$rStored = self::db()->get_raw_row();
		if ($rStored === null || $rStored['audio_tracks'] === null || $rStored['subtitle_tracks'] === null) {
			return false;
		}
		$rAudio = self::trackList((string) $rStored['audio_tracks'], $rStreamID);
		$rSubtitles = self::trackList((string) $rStored['subtitle_tracks'], $rStreamID);
		$rWanted = [
			'audio_languages'    => self::languages($rAudio),
			'subtitle_languages' => self::languages($rSubtitles),
			'audio_tracks'       => $rAudio,
			'subtitle_tracks'    => $rSubtitles,
		];

		for ($rTry = 0; $rTry < self::MERGE_TRIES; $rTry++) {
			self::run('SELECT `movie_properties` FROM `streams` WHERE `id` = ?;', $rStreamID);
			$rRow = self::db()->get_raw_row();
			if ($rRow === null) {
				return false;
			}
			$rOld = $rRow['movie_properties'];
			$rProperties = new \stdClass();
			if ($rOld !== null && $rOld !== '') {
				// PHP turns integers beyond 64 bits into floats; re-encoding would
				// change them. Such a document is left alone.
				if (preg_match('/[\[,:]\s*-?\d{19,}/', (string) $rOld)) {
					throw new \RuntimeException('movie_properties of stream ' . $rStreamID . ' holds integers PHP cannot keep exactly');
				}
				$rProperties = json_decode((string) $rOld, false, 512, JSON_THROW_ON_ERROR);
				if (!$rProperties instanceof \stdClass) {
					throw new \RuntimeException('movie_properties of stream ' . $rStreamID . ' is not a JSON object');
				}
			}
			$rChanged = false;
			foreach ($rWanted as $rKey => $rValue) {
				$rNow = property_exists($rProperties, $rKey) ? json_encode($rProperties->{$rKey}, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null;
				if ($rNow !== json_encode($rValue, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) {
					$rProperties->{$rKey} = $rValue;
					$rChanged = true;
				}
			}
			if (!$rChanged) {
				if (intval($rStored['cache_pending']) === 1) {
					self::refreshCache($rStreamID);
				}
				return false;
			}
			$rNew = json_encode($rProperties, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
			// Marked before the write, cleared only once the cache is refreshed: a
			// crash or failure in between leaves the refresh due (merge retries it).
			self::run('UPDATE `crplay_media_tracks` SET `cache_pending` = 1 WHERE `stream_id` = ?;', $rStreamID);
			self::run(
				'UPDATE `streams` SET `movie_properties` = ? WHERE `id` = ? AND BINARY `movie_properties` <=> BINARY ?;',
				$rNew,
				$rStreamID,
				$rOld
			);
			if (self::db()->num_rows() === 1) {
				self::refreshCache($rStreamID);
				return true;
			}
			// Another writer changed the document since it was read: read it again.
		}
		throw new \RuntimeException('movie_properties of stream ' . $rStreamID . ' kept changing; merge skipped');
	}

	/**
	 * Check stored tracks against `movie_properties`, oldest check first (round
	 * robin over the whole table), and merge them back where a TMDb refresh
	 * dropped or changed them. Returns [rows merged, rows that failed].
	 *
	 * @return array{0: int, 1: int}
	 */
	public static function remerge(int $rLimit, int $rDeadline): array {
		self::run(
			'SELECT `stream_id` FROM `crplay_media_tracks` WHERE `audio_tracks` IS NOT NULL ORDER BY `merged_at` ASC, `stream_id` ASC LIMIT ' . max(1, $rLimit) . ';'
		);
		$rMerged = 0;
		$rFailed = 0;
		foreach (self::db()->get_raw_rows() as $rRow) {
			if (time() >= $rDeadline) {
				break;
			}
			$rStreamID = intval($rRow['stream_id']);
			try {
				if (self::merge($rStreamID)) {
					$rMerged++;
				}
			} catch (\Throwable $e) {
				$rFailed++;
				error_log('crplay-media: merge of stream ' . $rStreamID . ' failed: ' . $e->getMessage());
			}
			try {
				// Checked, merged or not: the next run starts with the rows checked longest ago.
				self::run('UPDATE `crplay_media_tracks` SET `merged_at` = ? WHERE `stream_id` = ?;', time(), $rStreamID);
			} catch (\Throwable $e) {
				$rFailed++;
				error_log('crplay-media: ' . $e->getMessage());
			}
		}
		return [$rMerged, $rFailed];
	}

	/**
	 * Probe the analysed movies/episodes of this server with no stored tracks
	 * (the library before the install) and the failed probes now due again.
	 * Returns [probes stored, probes that failed].
	 *
	 * @return array{0: int, 1: int}
	 */
	public static function backfill(int $rLimit, int $rDeadline): array {
		self::run(
			'SELECT s.`id` FROM `streams` s '
			. 'INNER JOIN `streams_servers` ss ON ss.`stream_id` = s.`id` AND ss.`server_id` = ? '
			. 'LEFT JOIN `crplay_media_tracks` t ON t.`stream_id` = s.`id` '
			. 'WHERE s.`type` IN (2, 5) AND s.`direct_source` = 0 AND ss.`to_analyze` = 0 AND ss.`stream_status` = 0 '
			. 'AND (t.`stream_id` IS NULL OR t.`pending_since` > 0 OR (t.`attempts` > 0 AND t.`retry_at` <= ?)) '
			. 'ORDER BY s.`id` ASC LIMIT ' . max(1, $rLimit) . ';',
			intval(SERVER_ID),
			time()
		);
		$rProbed = 0;
		$rFailed = 0;
		foreach (self::db()->get_raw_rows() as $rRow) {
			if (time() >= $rDeadline) {
				break;
			}
			$rStreamID = intval($rRow['id']);
			try {
				$rResult = self::probe($rStreamID);
			} catch (\Throwable $e) {
				// Only taking or giving back the lock can throw here.
				error_log('crplay-media: probe of stream ' . $rStreamID . ' failed: ' . $e->getMessage());
				$rResult = self::PROBE_FAILED;
			}
			if ($rResult === self::PROBE_STORED) {
				$rProbed++;
			} elseif ($rResult !== self::PROBE_SKIPPED) {
				$rFailed++;
			}
		}
		return [$rProbed, $rFailed];
	}

	/** Forget the tracks of deleted streams. */
	public static function forget(array $rStreamIDs): void {
		$rIDs = array_values(array_filter(array_map('intval', $rStreamIDs)));
		if ($rIDs === []) {
			return;
		}
		self::run('DELETE FROM `crplay_media_tracks` WHERE `stream_id` IN (' . implode(',', array_fill(0, count($rIDs), '?')) . ');', ...$rIDs);
	}

	/** Drop rows whose stream no longer exists (a delete that raced a probe). */
	public static function forgetOrphans(): void {
		self::run('DELETE t FROM `crplay_media_tracks` t LEFT JOIN `streams` s ON s.`id` = t.`stream_id` WHERE s.`id` IS NULL;');
	}

	/**
	 * Record a failed probe: the last good tracks stay, and the probe is due
	 * again after 10 min, 20 min, 40 min… up to a day. A pending probe asked
	 * for before $rStarted counts as done (it waits for the retry like the rest).
	 */
	private static function failed(int $rStreamID, string $rError, int $rStarted): void {
		self::run(
			'INSERT INTO `crplay_media_tracks` (`stream_id`, `attempts`, `retry_at`, `last_error`) VALUES (?, 1, ?, ?) '
			. 'ON DUPLICATE KEY UPDATE `retry_at` = ? + LEAST(' . self::MAX_RETRY_WAIT . ', 600 * POW(2, LEAST(`attempts`, 8))), `attempts` = `attempts` + 1, `last_error` = VALUES(`last_error`), '
			. '`pending_since` = IF(`pending_since` >= ?, `pending_since`, 0);',
			$rStreamID,
			time() + 600,
			self::text($rError, 250),
			time(),
			$rStarted
		);
	}

	/**
	 * Refresh the stream's cache; a failure is remembered (`cache_pending`) and
	 * retried by the next merge of the stream, even when nothing else changed.
	 */
	private static function refreshCache(int $rStreamID): void {
		try {
			StreamProcess::updateStream($rStreamID);
		} catch (\Throwable $e) {
			// `cache_pending` stays 1: the next merge of the stream tries again.
			error_log('crplay-media: cache refresh of stream ' . $rStreamID . ' failed: ' . $e->getMessage());
			return;
		}
		self::run('UPDATE `crplay_media_tracks` SET `cache_pending` = 0 WHERE `stream_id` = ?;', $rStreamID);
	}

	/**
	 * Run $rWork holding the stream's MariaDB lock (GET_LOCK): probes and merges
	 * of one stream never overlap, across cron:vod and cron:crplay_media.
	 * Returns null when the lock was not obtained within $rWait seconds.
	 *
	 * @template T
	 * @param callable(): T $rWork
	 * @return T|null
	 */
	private static function withLock(int $rStreamID, int $rWait, callable $rWork): mixed {
		$rLock = 'crplay_media_' . $rStreamID;
		self::run('SELECT GET_LOCK(?, ?) AS `got`;', $rLock, $rWait);
		$rGot = self::db()->get_raw_row();
		$rValue = $rGot['got'] ?? null;
		if ($rValue === null) {
			// MariaDB answers NULL on an error (not on a timeout, which is 0).
			throw new \RuntimeException('GET_LOCK failed for ' . $rLock);
		}
		if (intval($rValue) !== 1) {
			return null;
		}
		try {
			return $rWork();
		} finally {
			$rReleased = self::db()->query('SELECT RELEASE_LOCK(?) AS `released`;', $rLock);
			$rRow = self::db()->get_raw_row();
			if ($rReleased === false || intval($rRow['released'] ?? 0) !== 1) {
				error_log('crplay-media: could not release lock ' . $rLock);
			}
		}
	}

	/**
	 * Run a query; a failure (Database::query() returns false) becomes an exception.
	 *
	 * @throws \RuntimeException
	 */
	private static function run(string $rQuery, mixed ...$rBinds): void {
		if (self::db()->query($rQuery, ...$rBinds) === false) {
			throw new \RuntimeException('query failed: ' . strtok($rQuery, ' ') . ' (' . self::db()->error() . ')');
		}
	}

	/**
	 * A stored track list: a JSON list of objects, each with a string `language`.
	 *
	 * @return list<array<string, mixed>>
	 * @throws \RuntimeException|\JsonException
	 */
	private static function trackList(string $rJson, int $rStreamID): array {
		$rTracks = json_decode($rJson, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($rTracks) || !array_is_list($rTracks)) {
			throw new \RuntimeException('stored tracks of stream ' . $rStreamID . ' are not a JSON list');
		}
		foreach ($rTracks as $rTrack) {
			if (!is_array($rTrack) || array_is_list($rTrack) || !is_string($rTrack['language'] ?? null)) {
				throw new \RuntimeException('stored tracks of stream ' . $rStreamID . ' hold an invalid track');
			}
		}
		return $rTracks;
	}

	/** An ffprobe language tag as a lowercase code ("" when absent or odd). */
	private static function language(mixed $rTag): string {
		$rTag = strtolower(trim((string) $rTag));
		return preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $rTag) ? $rTag : '';
	}

	/**
	 * A string safe for JSON and for the panel's 3-byte utf8 columns: invalid
	 * UTF-8 replaced, characters outside the BMP (emoji…) dropped, length capped.
	 */
	private static function text(mixed $rValue, int $rMax): string {
		$rValue = mb_scrub((string) $rValue, 'UTF-8');
		$rValue = (string) preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $rValue);
		return mb_substr(trim($rValue), 0, $rMax, 'UTF-8');
	}
}
