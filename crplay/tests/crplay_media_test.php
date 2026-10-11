<?php
// crplay-media: loads the module against the real core classes (no database
// needed) and checks the parts that do not touch the database.
// Run from the repo root:  php crplay/tests/crplay_media_test.php
$root = dirname(__DIR__, 2);
require $root . '/src/vendor/autoload.php';
foreach (['CrplayMediaTracks', 'CrplayMediaCronJob', 'CrplayMediaModule'] as $c) {
	require_once $root . '/crplay/modules/crplay-media/' . $c . '.php';
}
use XcVm\Module\CrplayMedia\CrplayMediaTracks as T;
use XcVm\Module\CrplayMedia\CrplayMediaModule as M;
use XcVm\Module\CrplayMedia\CrplayMediaCronJob as C;

$fail = 0;
$ok = function (bool $cond, string $what) use (&$fail) { echo ($cond ? "  OK   " : "  FALLA ") . $what . "\n"; if (!$cond) { $fail++; } };

$m = new M();
$ok($m instanceof \XcVm\Core\Module\BaseModule, 'el módulo extiende BaseModule');
$ok($m->getName() === 'crplay-media' && $m->getVersion() === json_decode(file_get_contents($root . '/crplay/modules/crplay-media/module.json'), true)['version'], 'nombre y versión coinciden con module.json');
$ok($m->getCronEntries() === ['*/10 * * * *' => 'cron:crplay_media'], 'entrada de cron');
$c = new C();
$ok($c instanceof \XcVm\Cli\CommandInterface && $c->getName() === 'cron:crplay_media', 'el cron implementa CommandInterface');

// #[ListensTo] attributes as ModuleLoader reads them
$events = [];
foreach ((new ReflectionClass(M::class))->getMethods() as $method) {
	foreach ($method->getAttributes(\XcVm\Core\Events\ListensTo::class) as $a) {
		$events[$a->newInstance()->eventClass ?? $a->getArguments()[0]] = $method->getName();
	}
}
$ok(($events[\XcVm\Core\Events\Vod\MediaAnalyzedEvent::class] ?? '') === 'onMediaAnalyzed', 'escucha MediaAnalyzedEvent');
$ok(($events[\XcVm\Core\Events\Stream\StreamsDeletedEvent::class] ?? '') === 'onStreamsDeleted', 'escucha StreamsDeletedEvent');

// extract(): ffprobe -show_streams sample with 2 audio, 1 video, 2 subtitles
$streams = [
	['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264'],
	['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2, 'tags' => ['language' => 'SPA', 'title' => 'Latino 😀 <b>'], 'disposition' => ['default' => 1]],
	['index' => 2, 'codec_type' => 'audio', 'codec_name' => 'ac3', 'channels' => 6, 'tags' => ['language' => 'eng']],
	['index' => 3, 'codec_type' => 'subtitle', 'codec_name' => 'subrip', 'tags' => ['language' => 'spa', 'title' => 'Forzados'], 'disposition' => ['forced' => 1]],
	['index' => 4, 'codec_type' => 'subtitle', 'codec_name' => 'subrip', 'tags' => ['language' => 'und']],
	'basura',
	['index' => 5, 'codec_type' => 'audio', 'codec_name' => 'aac', 'tags' => ['language' => "x\xff"]],
];
[$audio, $subs] = T::extract($streams);
$ok(count($audio) === 3 && count($subs) === 2, '3 pistas de audio y 2 de subtítulos');
$ok($audio[0] === ['index' => 1, 'codec' => 'aac', 'language' => 'spa', 'title' => 'Latino  <b>', 'channels' => 2, 'default' => true], 'pista 1: idioma normalizado, emoji quitado, default=true');
$ok($audio[1]['default'] === false && $audio[1]['channels'] === 6, 'pista 2: default=false, 6 canales');
$ok($audio[2]['language'] === '', 'etiqueta inválida → idioma vacío');
$ok($subs[0]['forced'] === true && $subs[1]['language'] === 'und', 'subtítulos: forced y und');
$ok(T::languages($audio) === ['spa', 'eng'], 'audio_languages = [spa, eng]');
$ok(T::languages($subs) === ['spa'], 'subtitle_languages = [spa] (und fuera)');
$ok(json_encode([$audio, $subs], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) !== '', 'las pistas se codifican como JSON');
$ok(T::languages([['language' => 'por'], ['language' => 'por'], ['language' => '']]) === ['por'], 'sin duplicados ni vacíos');

// text(): invalid UTF-8, length cap in characters (not bytes)
$text = new ReflectionMethod(T::class, 'text');
$ok($text->invoke(null, "a\xffb\xc3\xb1", 10) === 'abñ', 'bytes UTF-8 inválidos eliminados, ñ conservada');
$ok($text->invoke(null, str_repeat('ñ', 300), 200) === str_repeat('ñ', 200), 'corte a 200 caracteres (no bytes)');
$ok($text->invoke(null, "  Título 🎬 ", 50) === 'Título', 'trim y emoji quitado');
$ok($text->invoke(null, null, 10) === '', 'null → cadena vacía');
$ok($text->invoke(null, "a\xed\xa0\x80b", 10) === 'ab', 'sustituto D800 (ED A0 80) eliminado');
$ok($text->invoke(null, "a\xed\x9f\xbfb", 10) === "a\xed\x9f\xbfb", 'U+D7FF (último antes de sustitutos) conservado');
$ok($text->invoke(null, "a\xc0\xafb\xe0\x80\xafc", 10) === 'abc', 'secuencias sobrelargas eliminadas');
$ok($text->invoke(null, "a\xe2\x82b", 10) === 'ab', 'secuencia truncada eliminada');
$ok($text->invoke(null, "a\x80\xbfb", 10) === 'ab', 'bytes de continuación sueltos eliminados');
$ok($text->invoke(null, "a\xef\xbf\xbdb\xf0\x90\x80\x80c", 10) === "a\xef\xbf\xbdbc", 'U+FFFD conservado, U+10000 (4 bytes) eliminado');
$ok($text->invoke(null, 'hola', 0) === '' && $text->invoke(null, 'hola', -5) === '', 'máximo 0 o negativo → vacío');
$ok($text->invoke(null, 'hola', 70000) === 'hola', 'máximo enorme acotado sin error de PCRE');
$big = str_repeat('x', 5000000);
$before = memory_get_usage();
$ok($text->invoke(null, $big, 200) === str_repeat('x', 200) && memory_get_peak_usage() - $before < 64 * 1024 * 1024, 'texto de 5 MB: se corta sin disparar la memoria');

echo $fail === 0 ? "\nTODO OK\n" : "\n$fail FALLOS\n";
exit($fail === 0 ? 0 : 1);
