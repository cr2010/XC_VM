-- crplay-media: one row per movie/episode with every audio and subtitle track.
-- It is the module's source of truth: TmdbCron rewrites `streams`.`movie_properties`
-- whole, so cron:crplay_media merges these tracks back from here.
--   audio_tracks / subtitle_tracks  JSON of the last successful probe (NULL: never probed OK)
--   probed_at                       time of that probe
--   attempts / retry_at / last_error  failed probes: retried with growing waits
--   merged_at                       last time cron:crplay_media checked the row (round robin)
--   pending_since                   an analysis event found the stream busy: probe again (0 = none)
--   cache_pending                   movie_properties written but the cache refresh failed
CREATE TABLE IF NOT EXISTS `crplay_media_tracks` (
  `stream_id` int(11) NOT NULL,
  `audio_tracks` mediumtext COLLATE utf8_unicode_ci,
  `subtitle_tracks` mediumtext COLLATE utf8_unicode_ci,
  `probed_at` int(11) NOT NULL DEFAULT 0,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `retry_at` int(11) NOT NULL DEFAULT 0,
  `last_error` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `merged_at` int(11) NOT NULL DEFAULT 0,
  `pending_since` int(11) NOT NULL DEFAULT 0,
  `cache_pending` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`stream_id`),
  KEY `retry_at` (`retry_at`),
  KEY `merged_at` (`merged_at`),
  KEY `pending_since` (`pending_since`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
