<?php

namespace XcVm\Module\CrplayMedia;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;

/**
 * cron:crplay_media — keep the audio/subtitle languages in `movie_properties`.
 *
 * 1. Drops rows of deleted streams.
 * 2. Checks up to 500 stored rows (round robin) and merges them back where a TMDb
 *    refresh rewrote `movie_properties`.
 * 3. Probes up to 50 analysed movies/episodes of this server with no stored tracks
 *    (the library before the install) or whose failed probe is due again.
 * After TIME_BUDGET seconds a run starts no new row; exit code 2 when something failed.
 *
 * `php console.php cron:crplay_media <n>` probes up to <n> (max 1000) per run instead of 50.
 *
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class CrplayMediaCronJob implements CommandInterface {
	use CronTrait;

	/** Most probes one run may do (`cron:crplay_media <n>`). */
	private const MAX_LIMIT = 1000;

	/**
	 * Seconds after which a run starts no new row (checked between rows: a probe
	 * already started runs to its own ffprobe timeout).
	 */
	private const TIME_BUDGET = 240;

	/** Seconds after which a run that still holds the cron lock is ended by the next one. */
	private const LOCK_LIMIT = 1800;

	public function getName(): string {
		return 'cron:crplay_media';
	}

	public function getDescription(): string {
		return 'Cron: CRPLAY audio/subtitle languages for movies and episodes';
	}

	public function execute(array $rArgs): int {
		// Core writes module cron lines into root's crontab with no `sudo -u xc_vm`
		// (ModuleLoader::collectCronEntries, StartupCommand::installRootCrontab):
		// run as root, hand the job over to the panel user.
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			$rCommand = 'sudo -u xc_vm ' . escapeshellarg(PHP_BIN) . ' ' . escapeshellarg(MAIN_HOME . 'console.php') . ' ' . $this->getName();
			if (isset($rArgs[0])) {
				$rCommand .= ' ' . max(1, intval($rArgs[0]));
			}
			passthru($rCommand, $rCode);
			return intval($rCode);
		}
		if (!$this->assertRunAsXcVm()) {
			return 1;
		}
		// A run stuck longer than LOCK_LIMIT is ended by the next one.
		$this->initCron('XC_VM[CrplayMedia]', self::LOCK_LIMIT);

		$rLimit = isset($rArgs[0]) ? min(self::MAX_LIMIT, max(1, intval($rArgs[0]))) : 50;
		$rDeadline = time() + self::TIME_BUDGET;
		$rErrors = 0;
		$rMerged = $rProbed = 0;

		try {
			CrplayMediaTracks::forgetOrphans();
		} catch (\Throwable $e) {
			$rErrors++;
			error_log('crplay-media: ' . $e->getMessage());
		}
		try {
			[$rMerged, $rFailed] = CrplayMediaTracks::remerge(500, $rDeadline);
			$rErrors += $rFailed;
		} catch (\Throwable $e) {
			$rErrors++;
			error_log('crplay-media: ' . $e->getMessage());
		}
		try {
			[$rProbed, $rFailed] = CrplayMediaTracks::backfill($rLimit, $rDeadline);
			$rErrors += $rFailed;
		} catch (\Throwable $e) {
			$rErrors++;
			error_log('crplay-media: ' . $e->getMessage());
		}
		echo 'crplay-media: merged ' . $rMerged . ', probed ' . $rProbed . ', errors ' . $rErrors . "\n";

		return $rErrors > 0 ? 2 : 0;
	}
}
