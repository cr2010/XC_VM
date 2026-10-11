# Módulo `crplay-media`

Muestra a las apps **todos los idiomas de audio y subtítulos** de películas y episodios,
en las respuestas de la API Xtream `get_vod_info` y `get_series_info`.

## Por qué hace falta

- El núcleo de XC_VM guarda en `movie_properties` solo la **primera** pista de audio
  (`src/Streaming/Codec/FFprobeRunner.php:46-57`).
- La API borra `audio`, `video` y `subtitle` de `info`
  (`src/Public/Controllers/Api/PlayerApiController.php:950-954`).
- Un refresco de TMDb reescribe `movie_properties` entero (`src/Domain/Vod/TmdbCron.php:283-303`).

## Qué añade a `info`

`get_vod_info` copia `movie_properties` en `info`
(`src/Public/Controllers/Api/PlayerApiController.php:923`), y `get_series_info` lo copia en
`episodes[].info` (`:482`). El módulo añade estas claves, que llegan a las apps **sin
cambiar la API**:

```json
"info": {
  "audio_languages": ["spa", "eng"],
  "subtitle_languages": ["spa"],
  "audio_tracks": [
    {"index": 1, "codec": "aac", "language": "spa", "title": "Latino", "channels": 2, "default": true},
    {"index": 2, "codec": "ac3", "language": "eng", "title": "", "channels": 6, "default": false}
  ],
  "subtitle_tracks": [
    {"index": 3, "codec": "subrip", "language": "spa", "title": "Forzados", "forced": true}
  ]
}
```

- Los idiomas son la etiqueta `language` que trae el fichero (normalmente ISO 639-2:
  `spa`, `eng`, `por`…).
- Las pistas sin etiqueta o con `und` aparecen en `*_tracks`, pero no en `*_languages`.
- Si el fichero no tiene etiquetas de idioma, las listas salen vacías: el módulo no
  inventa idiomas.

## Cómo funciona

1. **Al analizar una película o un episodio:** cuando `cron:vod` termina, despacha
   `MediaAnalyzedEvent` (`src/Cli/CronJobs/VodCronJob.php:202`). El módulo vuelve a pasar
   ffprobe sobre el fichero con `FFprobeRunner::probeStream(..., false)`, que devuelve
   **todas** las pistas. Guarda el resultado en su tabla `crplay_media_tracks` y lo fusiona
   en `movie_properties`.
2. **Cada 10 minutos, `cron:crplay_media`:**
   - borra las filas de streams que ya no existen;
   - revisa por turnos hasta 500 filas guardadas y vuelve a fusionar las pistas donde un
     refresco de TMDb las borró o las cambió;
   - analiza hasta 50 películas o episodios sin pistas guardadas (la biblioteca anterior a
     instalar el módulo), los análisis fallidos a los que ya les toca reintentar y los
     pendientes. Un análisis queda pendiente cuando el evento de `cron:vod` encontró la
     película ocupada más de 30 s.
   - Pasados **240 s**, la pasada no empieza más filas: el análisis en curso termina con
     su propio timeout de ffprobe. Si una pasada se cuelga más de 30 min, la siguiente la
     termina.
3. **Al borrar streams**, borra sus filas de `crplay_media_tracks` (`StreamsDeletedEvent`).

Si falla el refresco de la caché tras escribir, queda marcado (`cache_pending`) y se
reintenta en la siguiente revisión de esa película.

**Escritura segura en `movie_properties`:** el módulo solo escribe si el documento sigue
siendo el que leyó (comparación optimista, 3 intentos). Así nunca deshace un cambio de TMDb
o de `cron:vod` hecho en medio. Lo trata como objeto JSON, sin cambiar la forma de lo que ya
hay. Si no es un objeto JSON válido, no lo toca; si está vacío (`NULL` o `""`), crea uno con
las claves del módulo. Los análisis y las fusiones de una misma película nunca se
solapan (bloqueo `GET_LOCK` de MariaDB por película, compartido entre `cron:vod` y
`cron:crplay_media`). Si el documento tiene enteros de 19 cifras o más, que PHP no puede
conservar exactos al recodificar, tampoco se toca. Este filtro es conservador: también
salta si esa cifra aparece dentro de un texto. Al recodificar, los decimales pasan por
`float` de PHP: cambian de forma (`7.50` → `7.5`), y los de más de ~15 cifras
significativas pueden redondearse. `cron:vod` del núcleo hace lo mismo con este documento.

El bloqueo solo coordina al módulo consigo mismo (`cron:vod` y `cron:crplay_media`
ejecutando código del módulo). Las escrituras del propio núcleo no lo usan; por eso la
escritura es optimista. Los títulos de pista se limpian: bytes de UTF-8 inválido eliminados, emojis quitados (las
tablas del panel son `utf8` de 3 bytes) y longitud limitada.

## Limitaciones

- **Solo analiza ficheros del servidor MAIN.** Los módulos no existen en los balanceadores
  (`Makefile:25`), así que el VOD que se analiza en un LB no recibe idiomas.
- **No analiza `direct_source`**, porque no hay fichero local.
- El ffprobe se ejecuta una segunda vez por cada película. Lo que tarda depende del
  fichero, del almacenamiento (más en montajes remotos) y de los ajustes `probesize` y
  `stream_max_analyze` del panel.
- Si un análisis falla (fichero ausente, montaje caído, timeout), se guardan el error y el
  número de intentos, y **se conservan las últimas pistas buenas**. Se reintenta tras 10
  min, 20 min, 40 min… hasta un máximo de 1 día. Un análisis nuevo de `cron:vod` también
  lo dispara.
- Si se reemplaza un fichero sin que `cron:vod` lo vuelva a analizar, las pistas guardadas
  no se actualizan.
- Las apps tienen que leer las claves nuevas: las apps Xtream genéricas las ignoran.

## Instalación (en el laboratorio, con aprobación)

1. Crear el ZIP desde la raíz del repo:
   ```bash
   cd crplay/modules && zip -r ../../crplay-media.zip crplay-media && cd ../..
   ```
2. En el panel: **Modules → Upload**, subir `crplay-media.zip`. Se necesita el permiso
   `settings`. El panel crea la tabla con `database.sql`.
3. El cron se registra solo: `cron:root_signals` comprueba cada minuto el crontab de root
   y lo actualiza cuando cambian las entradas de los módulos (`src/Cli/CronJobs/RootSignalsCronJob.php:153-164`,
   `src/Cli/Commands/StartupCommand.php:269`). El núcleo lo lanza como root, y el módulo se
   relanza a sí mismo como `xc_vm`. Para comprobarlo:
   ```bash
   sudo crontab -l | grep crplay_media
   ```
4. Analizar ya la biblioteca existente, sin esperar al cron. El número es el máximo de
   películas por pasada (hasta 1000; a los 240 s no empieza más):
   ```bash
   sudo -u xc_vm /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php cron:crplay_media 500
   ```
5. Comprobar:
   ```bash
   curl -s 'http://<panel>/player_api.php?username=<usuario_demo>&password=<clave_demo>&action=get_vod_info&vod_id=<id>' | python3 -m json.tool | grep -A3 audio_languages
   ```

**Desinstalar:** **Modules → Uninstall** borra la tabla (`database_drop.sql`). Las claves
ya escritas en `movie_properties` se quedan hasta el siguiente refresco de TMDb.

## Por qué está fuera de `src/Modules/`

Upstream ignora `src/Modules/*` en git: los módulos se distribuyen aparte. Tenerlo en
`crplay/modules/` no toca ningún archivo de upstream y mantiene el fork fácil de
sincronizar.
