<?php

namespace XcVm\Module\CrplayMedia;

use XcVm\Cli\CommandRegistry;
use XcVm\Core\Events\ListensTo;
use XcVm\Core\Events\Stream\StreamsDeletedEvent;
use XcVm\Core\Events\Vod\MediaAnalyzedEvent;
use XcVm\Core\Module\BaseModule;

/**
 * CrplayMediaModule — audio and subtitle languages of movies and episodes for the Xtream API.
 *
 * - On MediaAnalyzedEvent (cron:vod finished analysing a movie/episode on MAIN)
 *   it probes the file and stores every audio/subtitle track (CrplayMediaTracks).
 * - cron:crplay_media puts the tracks back after a TMDb refresh rewrote
 *   `movie_properties`, and probes the library that existed before the install.
 *
 * No core file is changed: get_vod_info / get_series_info already copy
 * `movie_properties` into `info`.
 *
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class CrplayMediaModule extends BaseModule {

	public function getName(): string {
		return 'crplay-media';
	}

	public function getVersion(): string {
		return '1.0.0';
	}

	public function registerCommands(CommandRegistry $registry): void {
		$registry->register(new CrplayMediaCronJob());
	}

	public function getCronEntries(): array {
		return [
			'*/10 * * * *' => 'cron:crplay_media',
		];
	}

	/**
	 * Dispatched by cron:vod after it stored the analysis; cron:vod refreshes the
	 * stream's cache right after. A failure here must not stop cron:vod's run.
	 */
	#[ListensTo(MediaAnalyzedEvent::class)]
	public function onMediaAnalyzed(MediaAnalyzedEvent $rEvent): void {
		try {
			// probe() records its own failures (retried later); only the lock can throw.
			// Waits up to 30 s for a probe of the same stream already running; past
			// that, a pending probe is left for cron:crplay_media.
			CrplayMediaTracks::probe($rEvent->streamId, 30);
		} catch (\Throwable $e) {
			error_log('crplay-media: probe of stream ' . $rEvent->streamId . ' failed: ' . $e->getMessage());
		}
	}

	#[ListensTo(StreamsDeletedEvent::class)]
	public function onStreamsDeleted(StreamsDeletedEvent $rEvent): void {
		try {
			CrplayMediaTracks::forget($rEvent->streamIds);
		} catch (\Throwable $e) {
			error_log('crplay-media: cleanup failed: ' . $e->getMessage());
		}
	}
}
