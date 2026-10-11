# Estudio técnico de XC_VM para CRPLAY TV / NETPLAY

> **Alcance:** estudio de solo lectura del código. No se instaló ni ejecutó nada.
> **Versión estudiada:** `upstream/main` = `origin/main` en el commit `16d9d3e8`
> (merge de Vateron-Media/XC_VM#347). Los dos estaban al mismo commit en la fecha del estudio (2026-10-11).
> **Citas:** todas las rutas son relativas a la raíz del repo, con el formato `archivo:línea`.
> Codex (revisor independiente) comprobó las citas contra el código, y las afirmaciones que
> cuestionó se volvieron a leer a mano (ver el PR). Si una cita se desfasa tras un
> `git pull`, buscar el símbolo citado.
> **Leyenda:** lo que no lleva marca se leyó en el código. **[inferido]** = deducido,
> pendiente de probar en el laboratorio. **[no determinable]** = depende de `xcvm_core.so`
> (extensión compilada cuyo código fuente no está en ningún repo público revisado) o solo se
> puede saber instalando.
> **Repos externos verificados en su estado actual (2026-10-11):**
> `Vateron-Media/XC_VM_Fanout` en el commit `6414e5b` (código fuente de `xc_fanout` y
> `xc_agent`). Se cita como `XC_VM_Fanout:ruta:línea`.

---

## Índice

- [a) Arquitectura](#a-arquitectura)
- [b) Canales on demand: arranque, parada y tiempo de arranque](#b-canales-on-demand-arranque-parada-y-tiempo-de-arranque)
- [c) Conexiones por línea y límites](#c-conexiones-por-línea-y-límites)
- [d) VOD: películas, series e idiomas](#d-vod-películas-series-e-idiomas)
- [e) Compatibilidad con la API de Xtream Codes](#e-compatibilidad-con-la-api-de-xtream-codes)
- [f) Migración desde XUI.one](#f-migración-desde-xuione)
- [g) Seguridad](#g-seguridad)
- [h) Instalador: qué toca y qué descarga](#h-instalador-qué-toca-y-qué-descarga)
- [i) Riesgos y preguntas abiertas](#i-riesgos-y-preguntas-abiertas)
- [j) Lecciones para el diseño del control plane de CRPLAY](#j-lecciones-para-el-diseño-del-control-plane-de-crplay)

---

## a) Arquitectura

### a.1 Visión general

XC_VM es un panel IPTV estilo Xtream Codes escrito en **PHP**. Es un monolito modular
con autoload PSR-4 (`XcVm\` → `src/`). El contenido de `src/` se despliega tal cual en
`/home/xc_vm/` (ver `CLAUDE.md`).

| Componente | Qué es / versión | Origen | Cita |
|---|---|---|---|
| PHP | ≥ 8.1. La plataforma Composer está fijada en 8.1.33 | Bundle binario `Vateron-Media/XC_VM_Binaries` (no está en el repo) | `src/composer.json:7`, `src/composer.json:35` |
| Extensiones PHP | ioncube, opcache, ssh2, redis, igbinary y **`xcvm_core.so`** (compilada, sin código fuente público) | Bundle + `install_xcvm_core.sh` | `src/bin/php/lib/php.ini:1899-1905` |
| PHP-FPM | 4 pools `pm = ondemand`, `max_children 4000`, por socket unix | Bundle | `src/bin/daemons.sh:2-5`, `src/bin/php/etc/1.conf:7-14` |
| nginx | Dos binarios propios: `bin/nginx` (HTTP/HTTPS) y `bin/nginx_rtmp` (RTMP) | Bundle (versión **[no determinable]**) | `src/bin/nginx/conf/nginx.conf`, `src/bin/nginx_rtmp/conf/` |
| MariaDB | 11.4, desde el repo oficial de MariaDB (Ubuntu 20.04 usa la 10.3 de la distro) | apt | `install:1152`, `install:1111-1139` |
| "Redis" | En realidad es **KeyDB** (compatible con Redis), binario incluido en el repo. El binario contiene la cadena `KeyDB-6.3.4` (`grep -a KeyDB src/bin/redis/redis-server`) | `src/bin/redis/redis-server` | `docs/en/README.md:44`; opciones exclusivas de KeyDB en `src/Cli/Commands/StatusCommand.php:365-368` |
| Bus de clúster | Segunda instancia KeyDB, solo por socket unix (MAIN) | repo | `src/bin/cluster_bus/cluster.conf:4-6` |
| FFmpeg / ffprobe | Compilaciones 4.0, 7.1 y 8.1 por distro, descargadas de `Vateron-Media/XC_VM_FFMPEG` | GitHub releases | `src/Cli/Commands/FfmpegBuildsCommand.php:44`, `:84`, `:141` |
| `xc_fanout` | Daemon de distribución de streams (segmentador, fan-out a clientes). **Código fuente abierto** (Go, AGPL-3.0) en `Vateron-Media/XC_VM_Fanout` (`cmd/xc_fanout/`). Solo usa la biblioteca estándar de Go (`XC_VM_Fanout:NOTICE`) | XC_VM descarga el **binario compilado** de las releases de ese repo | `src/Cli/Commands/FanoutBinaryCommand.php:17-19`, `:93` |
| `xc_agent` | Agente del clúster (API main↔LB). **Código fuente abierto** en el mismo repo (`cmd/xc_agent/`, `internal/clusteragent/`). Los tokens del clúster, en cambio, los emite `xcvm_core` en el MAIN (`docs/en/development/cluster-api.md:56`) | Binario compilado de las mismas releases | `src/bin/xc_agent/run.sh:93-114` |
| yt-dlp, guess | Binarios incluidos | repo / GitHub yt-dlp | `src/Cli/Commands/YtDlpCommand.php:31-32` |

Los repos externos que usa están definidos en `src/Core/Config/ConstantsInitializer.php:158-165`.

### a.2 Puntos de entrada

- `src/Public/index.php` es el *front controller* del panel admin, revendedor y jugador,
  y de la API web. Enruta según `XC_SCOPE`/`XC_API`, que pone nginx
  (`src/bin/nginx/conf/nginx.conf:374-410`).
- `src/Public/stream/*.php` contiene `auth`, `live`, `vod`, `segment`, `timeshift` y
  `rtmp`: es la parte de streaming.
- `src/console.php` es la CLI de comandos y crons. Los crons se descubren solos en
  `src/console.php:61-80`.

### a.3 Servicios y supervisión

- **systemd `xc_vm.service`:** corre como `User=root` con `Restart=always` y ejecuta
  `/home/xc_vm/service start` (`install:388-408`).
- **`src/service`** (requiere root, `src/service:6-9`). La función `boot()` (`:13-80`) arranca,
  en este orden:
  1. KeyDB y el bus de clúster (`:27-39`).
  2. Los supervisores de `xc_fanout` y `xc_agent` (`:43-51`).
  3. nginx y nginx_rtmp (`:65-66`).
  4. PHP-FPM (`:67`).
  5. `console.php startup` como root (`:70`).
  6. Los daemons PHP `signals`, `watchdog`, `queue`, `cache_handler` y `fanout_sync` (`:72-79`).
- **Crons del usuario `xc_vm`:** se generan desde la tabla `crontab`
  (`src/Core/Init/LegacyInitializer.php:107-128`). Las filas semilla están en
  `src/bin/install/database.sql:495-524`. Casi todos corren cada minuto: `streams`, `users`,
  `servers`, `vod`, `cache`, `cluster`, etc.
- **Crontab de root:** `cron:root_signals`, `cluster:root` y `cron:root_mysql` cada minuto.
  El fichero queda **inmutable con `chattr +i`** (`src/Cli/Commands/StartupCommand.php:248-327`,
  `:324`).

### a.4 Main y balanceadores (LB)

Hay dos formas de enlace, que se eligen por nodo con `mode` 0, 1 o 2
(`docs/en/development/cluster-api.md:107-113`):

1. **Legacy (mode 0, obsoleto).** El LB se conecta **directamente a MariaDB (3306) y KeyDB
   (6379) del MAIN** (`docs/en/administration/main-lb-cluster.md:13-39`). Al instalar el LB,
   MAIN le concede acceso a la BD con `\XC_VM::db_grant()`, que está en la extensión compilada sin código fuente público
   (`src/Core/Backup/BackupService.php:130-135`). La API interna `/api` se protege con el
   secreto compartido `live_streaming_pass` más una lista de IPs
   (`src/Public/Controllers/Api/InternalApiController.php:53-61`).
2. **API de clúster (ADR 0004, `docs/adr/0004-cluster-api.md`).**
   - El nodo siempre llama al MAIN con `POST /cluster/v1/<op>`
     (`src/bin/nginx/conf/cluster_locations.conf:7-28`, `src/Domain/Cluster/ClusterApi.php:25-35`).
   - Autenticación: claves Ed25519/X25519 generadas en el nodo, más HMAC por petición con
     timestamp y nonce, más cuerpo cifrado AES-256-GCM. El transporte es **HTTP plano por
     defecto** (`docs/en/development/cluster-api.md:10-14`, `:49-66`).
   - La configuración se replica como "réplica" firmada y sellada por nodo
     (`src/Domain/Cluster/ReplicaBuilder.php:18-31`). La aplica `cluster:apply`
     (`src/Core/Cluster/ReplicaApply.php:16-28`).
   - Las instalaciones nuevas la activan solas con `cluster:init --enable` (`install:2436-2442`).
   - El enrolamiento por SSH está en `src/Cli/Commands/LbInstallFlow.php:724-852`.

**Cómo se asigna un cliente a un servidor:**

1. El cliente siempre se autentica en MAIN, en `src/Public/stream/auth.php`.
2. `StreamRedirector::redirectStream()` (`src/Streaming/Delivery/StreamRedirector.php:24-165`)
   filtra los servidores online que tienen el stream (`:52-67`). Los ordena por capacidad y
   clientes (`:85-105`) y aplica GeoIP/ISP (`:110-157`).
3. MAIN responde con un `302` hacia `http(s)://<LB>:<puerto>/auth/<token>`
   (`src/Public/stream/auth.php:632`, `:636`).

---

## b) Canales on demand: arranque, parada y tiempo de arranque

### b.1 Secuencia de arranque (camino por defecto: *fanout*)

`fanout_enabled=1` y `fanout_supervise=1` vienen activos por defecto
(`src/bin/install/database.sql:1741-1743`).

1. nginx reescribe `/live/u/p/ID.ext` a `/stream/auth` (`src/bin/nginx/conf/nginx.conf:121-124`).
2. `auth.php` autentica y responde **302** hacia `/auth/<token>`
   (`src/Public/stream/auth.php:656`, `:661`).
3. `/auth/<token>` cae en `src/Public/stream/live.php` (`src/bin/nginx/conf/nginx.conf:108`).
4. Si el stream no está vivo, `FanoutMode::startFor` (`src/Public/stream/live.php:160-162`) toma un `flock` para
   que dos espectadores no arranquen dos productores (`src/Public/stream/live.php:195`,
   `src/Domain/Stream/StreamProcess.php:124-147`). Después llama a
   `StreamProcess::startMonitor` (`src/Public/stream/live.php:212-217`).
5. `startMonitor` (`src/Domain/Stream/StreamProcess.php:167-184`) entrega la especificación al daemon con
   `superviseStream()` (`src/Domain/Stream/StreamProcess.php:1779-1840`). En este camino los datos de probe salen de
   `cachedProbe()`, que lee la caché o arma datos mínimos, en vez de lanzar ffprobe sobre
   la fuente (`src/Domain/Stream/StreamProcess.php:1724-1737`). Si el canal es solo *copy* y el daemon no
   rechaza la fuente (`remuxRefusal()`), usa el **remuxer nativo** de `xc_fanout` en lugar
   de ffmpeg (`src/Domain/Stream/StreamProcess.php:1620-1691`, condiciones de rechazo en `:1227-1280`).
6. `live.php` espera al productor sondeando:
   - Espera `_.pid` cada 20 ms (`src/Public/stream/live.php:240-246`).
   - **TS:** espera `_0.ts` cada 100 ms (`src/Public/stream/live.php:260-277`).
   - **HLS:** espera `_.m3u8` (`src/Public/stream/live.php:279-284`).
   - Todos los topes los fija `on_demand_wait_time` (20 s por defecto).
7. Entrega:
   - TS: `X-Accel-Redirect /xc_fanout/<id>` (`src/Public/stream/live.php:486-495`).
   - HLS: playlist del daemon, con los segmentos reescritos como URLs tokenizadas. **Si la
     playlist del daemon todavía no contiene ningún segmento, la función devuelve `false`**
     (`src/Streaming/Delivery/HLSGenerator.php:95-112`, `if ($rReplaced === 0) return false;`)
     **y el cliente recibe "not on air"** (`src/Public/stream/live.php:391-409`).

**Camino sin daemon (respaldo):** se lanza `console.php monitor`
(`src/Cli/Commands/MonitorCommand.php:338-361`) y luego `startStream`
(`src/Domain/Stream/StreamProcess.php:2486-2815`). Este camino **hace ffprobe sobre la fuente**
(`src/Domain/Stream/StreamProcess.php:2675-2676`; se lo salta con la caché de ≤300 s, `:2643`, o con LLOD,
`:2659`, `:2683-2687`) y **después** ffmpeg vuelve a abrir la fuente (`:2771`). Es decir,
abre la fuente dos veces.

### b.2 Parada

- **`cron:streams`** corre cada minuto (`src/bin/install/database.sql:498`). Para el stream
  con `stopStream()` si: no tiene espectadores, no tiene hijos (`attached`), la cola de
  `on_demand_instant_off` está vacía, no hay señal de admin de hace ≤30 s, y el stream ya
  produjo playlist o lleva más de `on_demand_wait_time` arrancando
  (`src/Cli/CronJobs/StreamsCronJob.php:217-247`). Si Redis no responde, cuenta **1**
  espectador y no para el stream (`:222-224`). **[inferido]** Por el ciclo de 1 minuto, el
  canal sigue vivo entre 0 y ~60 s después del último espectador.
- **`on_demand_instant_off`** (por defecto 0, `src/bin/install/database.sql:1605`) activa el daemon
  `console.php ondemand` (`src/Cli/Commands/OndemandCommand.php`). Este revisa cada 0,8 s
  (`:105`, `:205`) y aplica una gracia `on_demand_instant_off_grace` (30 s por defecto,
  rango 3–300; `:129`).
- **Direct proxy:** la parada la hace el propio daemon. El ajuste que le pasa PHP es
  `fanout_grace_sec` (10 s por defecto, `src/Streaming/Fanout/FanoutConfig.php:128`). En el
  código de `xc_fanout`, un *reaper* revisa cada `grace/2` y para el stream cuando no le
  quedan espectadores y han pasado `grace_sec` desde el último acceso
  (`XC_VM_Fanout:internal/server/server.go:797-829`, `XC_VM_Fanout:internal/defaults/defaults.go:144`,
  `XC_VM_Fanout:docs/en/05-lifecycle.md:62-74`).

### b.3 Qué determina el tiempo de arranque

| Factor | Valor por defecto | Cita |
|---|---|---|
| Doble HTTP (302 de `auth` → `live`) y arranque de PHP-FPM | — | `src/Public/stream/auth.php:656` |
| Conexión a la fuente (DNS, TCP/TLS, HTTP) | — | — |
| `analyzeduration` on demand **fijo en el código**: 10 s (0,5 s con LLOD) | 10 000 000 µs | `src/Domain/Stream/StreamProcess.php:619` |
| `probesize_ondemand` | 128 000 (columna de `streams`) / 256 000 (valor de `settings`) | `src/bin/install/database.sql:1845`, `:1780-1781` |
| `-re` (`read_native=1`): lee a velocidad real y desaprovecha la ráfaga inicial | 1 | `src/bin/install/database.sql:1822`, `src/Domain/Stream/StreamProcess.php:958-959` |
| `seg_time` (duración del segmento HLS) | **10 s** (INSERT de `settings`) | `src/bin/install/database.sql:1780-1781`, `src/Domain/Stream/StreamProcess.php:520-528` |
| `hls_init_time = min(2, seg_time)`: objetivo de duración del primer segmento | 2 s | `src/Domain/Stream/StreamProcess.php:1001` |
| GOP de la fuente: si `ignore_keyframes=0` (por defecto), ffmpeg corta en keyframes; con `ignore_keyframes=1` usa `+split_by_time` | depende de la fuente | `src/Domain/Stream/StreamProcess.php:998` |
| `client_prebuffer`: en el camino legacy TS, con on demand y cliente no *restreamer*, **espera a que la playlist acumule N segundos de contenido** (con tope `on_demand_wait_time` o hasta que caiga el productor); con fanout se pasa al daemon como `prebuffer=` | 10 s | `src/Public/stream/live.php:606-615`, `:486-495` |
| Sondeo de ficheros cada 100 ms (no usa inotify) | — | `src/Streaming/AsyncFileOperations.php:197-212` |

**Conclusión [inferido]:**

- **TS por fanout:** PHP entrega en cuanto existe `_0.ts`. En `xc_fanout`, el `prebuffer`
  **no es una espera**: es cuánto historial del ring se envía al entrar (`0` se respeta
  tal cual; por defecto `0`, `XC_VM_Fanout:internal/defaults/defaults.go:141`)
  (`XC_VM_Fanout:docs/en/03-endpoints.md:36`, `:43-45`). XC_VM le pasa `client_prebuffer`
  (10 s): con el canal caliente, el espectador se une al ring y recibe ese historial
  (`Hub.Join`, `XC_VM_Fanout:internal/server/server.go:2018-2022`). Con el canal frío, recibe
  datos cuando el productor (el ffmpeg o el remuxer que lanza XC_VM, o el *puller* del daemon
  en direct proxy) empieza a publicar; **[inferido]** el primer *frame* visible requiere
  además el primer keyframe (primer byte ≠ primer frame). Si el *puller* del daemon
  tiene que recurrir a un ffmpeg propio, este usa `-probesize 1000000 -analyzeduration
  1000000` como límites de análisis de entrada (`XC_VM_Fanout:internal/puller/puller.go:859`,
  `XC_VM_Fanout:internal/defaults/defaults.go:78-79`); eso no aplica al productor que construye
  PHP. La meta de **≤5 s parece alcanzable, pero hay que medirla**.
- **HLS** es el caso lento. Con productor **ffmpeg**, hay que cerrar al menos un primer
  segmento (objetivo de 2 s, ajustado a keyframes y leído a velocidad real por `-re`). Con
  el **remuxer nativo** no se aplica `-re` (`src/Domain/Stream/StreamProcess.php:1249-1258`). El
  HLS de `xc_fanout` es una vista sobre su ring: responde `404 no segments yet` hasta tener
  el primer segmento (`XC_VM_Fanout:docs/en/03-endpoints.md:71`), cuyo objetivo es
  `hls_target_sec` (6 s por defecto en el daemon, `XC_VM_Fanout:internal/defaults/defaults.go:142`).
  XC_VM le pasa `seg_time`, que vale 10 (`src/Streaming/Fanout/FanoutConfig.php:102`, `:126`).
  **[inferido]** Por eso el HLS en frío puede tardar alrededor de `seg_time`; hay que medirlo.

### b.4 Palancas para llegar a ≤5 s

**Por configuración (sin tocar código):**

1. `llod=1` por stream: sin ffprobe, `analyzeduration` 0,5 s y `+nobuffer`
   (`src/Domain/Stream/StreamProcess.php:619`, `:916`, `src/Cli/Commands/MonitorCommand.php:338-351`).
2. `read_native=0`, y `read_native_hls=0` si la fuente es HLS: quitar `-re`.
3. Bajar `seg_time` a 2–4 s.
4. Bajar `client_prebuffer` a 0–2. Es crítico en el camino legacy; con fanout también se
   pasa al daemon (`src/Public/stream/live.php:486-495`).
5. Mantener `fanout_enabled=1` y `fanout_supervise=1` (evitan el doble probe) con backend
   `auto` (remuxer nativo para canales *copy*).
6. Canales críticos: **Direct Source + Direct Proxy**. PHP registra el stream en el daemon
   y espera a que tenga datos (`FanoutClient::probe`, `src/Public/stream/live.php:131-145`), sin
   lanzar ffmpeg ni monitor. Que el daemon haga *passthrough* sin segmentar es **[inferido]**.
   Otra opción es `on_demand=0` (siempre encendido).
7. *Keep-warm*: subir `on_demand_instant_off_grace` (máximo 300 s) para que el zapping de
   ida y vuelta no tenga que rearrancar.

**Por código** (cambios pequeños; hacerlos en un módulo o en parches mínimos y documentados):

1. Hacer configurable el `analyzeduration` on demand (`src/Domain/Stream/StreamProcess.php:619`) y el
   `hls_init_time` (`:1001`, `:1168`).
2. En HLS con fanout, **esperar con un tope** en vez de responder "not on air" cuando la
   playlist del daemon aún no tiene segmentos (`src/Public/stream/live.php:391-409`).
3. En TS con fanout, entregar en cuanto el daemon tenga datos (`FanoutClient::probe`), sin
   esperar `_0.ts` (`src/Public/stream/live.php:260-277`).
4. Usar inotify en lugar del sondeo de 100 ms (`src/Streaming/AsyncFileOperations.php:197-212`).

**Próximo paso en el laboratorio:** medir el tiempo hasta el primer byte con TS y HLS, con y
sin `llod`, con `seg_time` en 2/4/10 y `read_native` en 0/1, sobre una fuente de prueba
propia (por ejemplo, `tools/test-stream-generator`).

### b.5 Requisitos para reducir el arranque on demand (meta ≤5 s)

**Objetivo:** en un arranque en frío, que el cliente empiece a recibir datos en cuanto el
productor los tenga, sin acumular buffer antes. Hoy, según el camino:
- **legacy:** intenta esperar a que la playlist acumule `client_prebuffer` segundos
  (`src/Public/stream/live.php:606-615`);
- **fanout:** el prebuffer **no es una espera**, sino historial del ring (b.3); no hace
  falta cambiar código por esto;
- **productor ffmpeg on demand sin LLOD:** `analyzeduration` de 10 s
  (`src/Domain/Stream/StreamProcess.php:619`). No aplica al remuxer nativo.

| # | Requisito | Tipo | Dónde (XC_VM) |
|---|---|---|---|
| R1 | **Solo camino legacy:** prebuffer **0** en arranque en frío (enviar en cuanto haya datos), configurable aparte del prebuffer normal. Con fanout basta con medir | ajuste + código mínimo (legacy) | `src/Public/stream/live.php:606-615` |
| R2 | **Solo productor ffmpeg:** `analyzeduration` on demand configurable (por ejemplo 1–2 s) en vez de 10 s fijos sin LLOD | ajuste nuevo | `src/Domain/Stream/StreamProcess.php:619` |
| R3 | Probar `llod=1` en canales on demand: análisis de 0,5 s (`src/Domain/Stream/StreamProcess.php:619`) y arranque LLOD del monitor (`src/Cli/Commands/MonitorCommand.php:338-351`) | configuración | ídem |
| R4 | Evaluar `read_native=0` (y `read_native_hls=0` si la fuente es HLS) en fuentes en vivo, y comprobar que ninguna otra condición activa `-re` | configuración | `src/Domain/Stream/StreamProcess.php:958-959` |
| R5 | `seg_time` de 2–4 s en vez de 10 | configuración | `src/bin/install/database.sql:1780-1781` |
| R6 | En la espera inicial sin playlist, comprobar que el primer segmento (`_0.ts` o `_0.m4s`) ya **tiene datos** y no solo que existe; antes, verificar en qué punto empieza realmente el envío | código mínimo | `src/Public/stream/live.php:260-277` |
| R7 | **Medir** el tiempo hasta el primer byte y hasta el primer frame, con la misma fuente, antes y después de cada cambio | prueba | — |

R1 (legacy), R2 y R6 tocan código de upstream: hacerlo con el menor cambio posible y documentarlo en
su PR (ver `AGENTS.md`).

---

## c) Conexiones por línea y límites

### c.1 Dónde se registran

- Los endpoints registran a través de `ConnectionTracker::openRecord()`
  (`src/Domain/Stream/ConnectionTracker.php:1016-1054`), que elige el almacén en este orden:
  1. El agente del clúster (`:1034-1043`). Si el agente **rechaza**, devuelve `false` con el
     motivo (`:1039-1041`).
  2. En un nodo mode 2 sin respuesta del agente, no registra (`:1046-1048`).
  3. Redis/KeyDB con `redis_handler`: `createConnection()`, ZSET `LINE#<id>` (`:1049-1050`,
     `:940-962`).
  4. Si no, MySQL `lines_live` (`:1052-1053`).
- Cada conexión tiene un **uuid aleatorio por token** (`src/Public/stream/auth.php:242`). En
  **HLS en vivo** el uuid es fijo por `línea + stream + IP + UA`
  (`src/Domain/Stream/ConnectionTracker.php:923-927`, `src/Public/stream/live.php:319-325`).
- Las conexiones se cierran por varias vías:
  - Al terminar el worker (`src/Streaming/Lifecycle/ShutdownHandler.php:36-70`).
  - Por el limitador (`src/Streaming/Protection/ConnectionLimiter.php:186-273`).
  - Por `cron:users` (`src/Cli/CronJobs/UsersCronJob.php`), que trata como terminada una
    conexión HLS que lleva **30 s** sin pedir la playlist (`src/Cli/CronJobs/UsersCronJob.php:276-284`,
    umbral `STALE_AFTER = 30` en `src/Core/Cluster/HlsReaping.php:44`).

### c.2 Qué pasa al superar `max_connections`

**Política por defecto: "gana el nuevo".** En las rutas que usan
`ConnectionLimiter::closeConnections()`, cuando se supera el límite, **la conexión
solicitante se preserva** y se intentan cerrar conexiones **anteriores**, priorizando
misma IP+User-Agent, luego misma IP y finalmente cualquier conexión elegible. Dentro de cada
grupo, en orden de antigüedad. Hay excepciones que sí rechazan al nuevo (ver abajo), y
otras rutas (el agente del clúster, el cron) aplican su propia lógica.

- `StreamAuth::validateConnections()` se llama **después** de registrar la conexión
  (`src/Streaming/Auth/StreamAuth.php:111-134`).
- `ConnectionLimiter::closeConnections()` (`src/Streaming/Protection/ConnectionLimiter.php:45-184`) calcula
  el excedente `count - max` (`:61`, `:81`) y cierra primero las conexiones con la **misma
  IP+UA**, luego las de la misma IP y luego cualquiera (`:93-101`). No cierra la conexión
  que pide. Cuando encuentra su uuid en la lista ordenada, solo considera las conexiones
  **anteriores** a ella (`:103-112`); si no lo encuentra, esa frontera no se establece.
- Se llama en:
  - live HLS (`src/Public/stream/live.php:375`) y live TS (`src/Public/stream/live.php:454`);
  - VOD (`src/Public/stream/vod.php:189`);
  - timeshift (`src/Public/stream/timeshift.php:186`, `:269`).
- Con la API de clúster, MAIN además "hace sitio" de antemano y **nunca rechaza a un
  espectador válido** (`src/Domain/Cluster/ConnectionAdmission.php:54-58`, `:404-405`).
- Excepciones que **sí rechazan** al nuevo:
  - `disallow_2nd_ip_con` (por defecto 0, `src/bin/install/database.sql:1506`). Si se activa,
    para líneas no *restreamer* cuyo `max_connections` no pase de `disallow_2nd_ip_max` (o
    siempre, si este vale 0), **rechaza una IP distinta de la ya conectada** (comparación
    por subred según `ip_subnet_match`) y le muestra el video "connected". **Solo en live**
    (`src/Public/stream/live.php:299-310`).
  - El agente del clúster puede negar la admisión (`src/Domain/Stream/ConnectionTracker.php:1039-1041`).
    La respuesta depende del motivo (`src/Streaming/Auth/StreamAuth.php:173-179`): `EXPIRED` usa el
    video de expirado; `BANNED`/`DISABLED`, el de baneado; una línea o clave HMAC
    desconocida da error de credenciales; y `LIMIT`, `OFFLINE` u otro motivo muestran el
    video "connected". Solo se ve el video si el ajuste está activo y el fichero existe
    (`src/Streaming/Delivery/OffAirHandler.php:121-140`).

### c.3 ¿Se puede pasar del límite?

Sí, durante ventanas cortas:

1. Entre el registro y el recorte hay un momento con `max+1` conexiones (casi instantáneo en
   local; ~1–1,5 s con la API de clúster, `docs/adr/0004-cluster-api.md:925`).
2. **En HLS solo cuenta la playlist.** Los segmentos no registran conexión: comprueban un
   fichero marcador de la conexión y la IP (`src/Public/stream/segment.php:130-135`,
   `:162-171`). Cuando `cron:users` hace la limpieza (rol `legacy`,
   `src/bin/install/database.sql:506`), una conexión marcada `hls_end=1` se da por terminada en
   seguida. Una **abandonada sin marca de cierre** se da por terminada tras 30 s sin pedir
   playlist, salvo que el nodo limpie las suyas (`src/Cli/CronJobs/UsersCronJob.php:280-285`).
   **[inferido]** En ese caso sigue contando entre 30 y ~90 s (30 s más el ciclo de
   1 minuto del cron).
3. El límite viaja en el token: `live.php` toma `user_info` de los datos del token
   (`src/Public/stream/live.php:76`) y usa su `max_connections` (`:299`). Un cambio del máximo
   no afecta a tokens ya emitidos (sí a `ConnectionAdmission`, que relee la línea,
   `src/Domain/Cluster/ConnectionAdmission.php:568`).
4. **Efecto ping-pong [inferido]:** el dispositivo expulsado vuelve a pedir la URL original,
   recibe otro token y expulsa a otro. Con 4 aparatos y máximo 3, los cuatro pueden
   alternarse.

### c.4 Zapping

- **HLS:** la clave incluye `stream_id` (`src/Domain/Stream/ConnectionTracker.php:926`), así que cambiar de
  canal crea **otra** conexión. La anterior sigue contando hasta que se cierra. Si eso hace
  pasar el límite, se cierra **primero la del mismo IP+UA**, es decir, el canal anterior del
  mismo aparato (`src/Streaming/Protection/ConnectionLimiter.php:121`), no otro aparato.
- **TS:** `live.php` busca primero una conexión con el uuid del token y solo crea una si no
  existe (`src/Public/stream/live.php:426-431`). Como cada pedido a `/live/...` pasa por `auth.php`
  y recibe un token (y uuid) nuevo (`src/Public/stream/auth.php:242`), en la práctica cada cambio
  de canal crea una fila nueva. El worker anterior se cierra cuando el cliente cierra el
  socket, o lo cierra el limitador con la misma prioridad IP+UA.
- **VOD:** sin el daemon de ficheros, para las peticiones `Range` se arma un filtro por
  línea (o clave HMAC), contenedor, UA, stream e IP. La IP se omite cuando
  `ip_subnet_match` está activo y es IPv4 (`src/Public/stream/vod.php:117-121`). Ese filtro se usa
  para reutilizar una conexión existente en vez de crear otra (`src/Public/stream/vod.php:117-143`).

**Conclusión [inferido]:** el zapping normal no debería expulsar a otros aparatos, porque
la prioridad IP+UA cierra primero la conexión anterior del mismo cliente. Detrás de un
mismo NAT (misma IP) y con el mismo UA, el sistema no distingue un aparato de otro.

### c.5 Qué haría falta para que el 4.º aparato vea "conexiones sobrepasadas"

**Lo que ya existe:**

- El video `src/content/video/connected.ts` (está en el repo; el directorio `VIDEO_PATH`
  se define en `src/Core/Config/ConstantsInitializer.php:126`).
- Los ajustes `show_connected_video` (por defecto 1, `src/bin/install/database.sql:1670`) y
  `connected_video_path` (`:1671`).
- El mecanismo para servirlo: `OffAirHandler::showVideoServer()`
  (`src/Streaming/Delivery/OffAirHandler.php:119-195`). Emite un token con `off_air`
  (`:172-177`); para m3u8 devuelve una playlist `EVENT` (`:178-191`) y para el resto responde
  `Location: /auth/<token>` (`:193`). `live.php` sirve ese token con `readfile()` y `exit()`
  antes de registrar ninguna conexión (`src/Public/stream/live.php:59`, `:82-86`).

**Lo que falta: un modo "rechazar al nuevo".** Propuesta:

1. **Ajuste nuevo** `connection_limit_mode` = `kick_oldest` (el actual, por defecto) o
   `reject_new`. Debe agregarse a `src/Core/Cluster/lb_settings_keys.php` para que llegue a
   los LB.
2. **Comprobación previa en `auth.php`**, después de validar la línea y antes del
   `switch ($rType)` (`src/Public/stream/auth.php:530`):
   - Contar las conexiones abiertas de la línea **excluyendo las del mismo aparato**, para
     no romper el zapping ni las peticiones `Range`. Ojo: IP+UA es solo una **heurística**
     de "mismo aparato": varios aparatos detrás del mismo NAT con la misma app parecen uno
     (c.4). Para un límite fiable hace falta una identidad de sesión/dispositivo
     verificable (ver j.2).
   - Si el resultado es `>= max_connections`, registrar `USER_ALREADY_CONNECTED` y llamar a
     `OffAirHandler::showVideoServer('show_connected_video', 'connected_video_path', $ext, …)`,
     con el mismo patrón que se usa para "expirado" en `src/Public/stream/auth.php:351-358`.
3. **Comprobación definitiva** en `src/Public/stream/live.php:357` y `src/Public/stream/live.php:431`,
   `src/Public/stream/vod.php:161`, `src/Public/stream/timeshift.php:166` y `src/Public/stream/timeshift.php:239`,
   justo antes de registrar la conexión.

   > ⚠️ **La admisión tiene que ser ATÓMICA.** No basta con "1) contar conexiones; 2) si
   > hay hueco, registrar": entre los dos pasos, dos peticiones simultáneas pueden pasar
   > ambas (condición de carrera). Hace falta una sola operación
   > **RESERVAR SLOT + REGISTRAR/CONFIRMAR CONEXIÓN** por línea, sobre el almacén
   > autoritativo. Opciones: un script **Redis Lua**, un lock o reserva por usuario, un
   > **servicio de admisión central** o un algoritmo equivalente con *compare-and-set*.
   > XC_VM ya tiene una pieza parecida: `ConnectionAdmission` reserva con `RESV#`
   > (`src/Domain/Cluster/ConnectionAdmission.php:610-621`).
   >
   > Para CRPLAY se recomienda **separar esta lógica del motor de video**:
   > `petición Xtream/API` → `autenticación` → `admisión de conexión` → si se permite:
   > crear sesión/token de reproducción → motor de video → *heartbeat*/liberación.
   > Si la línea está llena: **no matar al cliente anterior** y responder al nuevo con el
   > video/playlist de "máximo de conexiones alcanzado".
4. **Desactivar el "gana el nuevo"** cuando el modo sea `reject_new`. Los puntos que lo
   aplican son:
   - `StreamAuth::validateConnections` (`src/Streaming/Auth/StreamAuth.php:111-134`);
   - `ConnectionAdmission::claim` (`src/Domain/Cluster/ConnectionAdmission.php:404-405`);
   - `ConnectionLimits` en MAIN;
   - `src/Cli/CronJobs/UsersCronJob.php:784-811`, que en ese modo debería cerrar la conexión **más nueva**.
5. **Limitaciones:**
   - Las líneas *restreamer* nunca reciben video, solo un 404 (`src/Streaming/Delivery/OffAirHandler.php:123`).
   - En VOD `.m3u8` habría que pasar `'m3u8'`, porque hoy fuerza `'ts'`.
   - Hay que verificar en el laboratorio cómo reacciona cada app (algunas reintentan en bucle).

Esto **sí toca código de upstream** en varios puntos. Para mantener el fork sincronizable,
conviene encapsularlo en una clase nueva (`ConnectionPolicy`) invocada con una sola línea
en cada punto. Proponer el cambio a upstream sería otra opción, pero según `AGENTS.md`
cualquier contacto con `Vateron-Media/XC_VM` requiere autorización expresa de Gilberth.

---

## d) VOD: películas, series e idiomas

### d.1 Cómo se guardan

- **Tablas** (`src/bin/install/database.sql`):
  - `streams` (`:1809`): `type` 2 = película y 5 = episodio (`:2132-2137`), `stream_source`,
    `movie_properties` (JSON, `:1820`), `movie_subtitles`, `target_container`,
    `direct_source`, `movie_symlink`.
  - `streams_series` (`:2020`): datos de la serie.
  - `streams_episodes` (`:1949`): `season_num`, `episode_num`, `series_id` → `stream_id`.
  - `streams_servers` (`:2052`): `stream_info`, `audio_codec`, `video_codec`, `resolution`.
- **En disco:** `content/vod/<id>.<ext>` (`src/Core/Config/ConstantsInitializer.php:122`).
  Según la configuración, el fichero se obtiene así:
  - *symlink* a la fuente (`src/Domain/Stream/StreamProcess.php:2339-2340`);
  - remux con ffmpeg (`src/Domain/Stream/StreamProcess.php:2360`). El mapa por defecto es `-map 0 -copy_unknown`,
    que conserva todas las pistas; con `remove_subtitles=1` usa `-map 0:a -map 0:v`, y
    `custom_map` puede cambiarlo (`src/Domain/Stream/StreamProcess.php:411-419`);
  - transcodificación (`src/Domain/Stream/StreamProcess.php:2345-2355`);
  - *direct source*: redirect a la fuente (`src/Public/stream/auth.php:187-188`). La consulta
    que elige qué analizar excluye `direct_source = 1` (`src/Domain/Stream/NodeStreams.php:405`),
    así que estas películas no pasan por `cron:vod`.
- **Creación:**
  - manual o M3U con `MovieService::process` (`src/Domain/Vod/MovieService.php:41`);
  - importación masiva con `VodItemImporter` (`src/Domain/Vod/VodItemImporter.php`);
  - datos de TMDb con `TmdbCron` (`src/Domain/Vod/TmdbCron.php`).

### d.2 ¿Lee audio y subtítulos con ffprobe?

**Sí lee, pero se queda con poco:**

- `cron:vod` ejecuta `FFprobeRunner::probeStream` sobre el fichero
  (`src/Cli/CronJobs/VodCronJob.php:119-120`). El comando es
  `ffprobe -probesize … -analyzeduration … -i <fichero> -v quiet -print_format json
  -show_streams -show_format` (`src/Streaming/Codec/FFprobeRunner.php:24`).
- XC_VM ejecuta ffprobe con `-show_streams`, así que **recibe todas las pistas**. Sin
  embargo, `parseFFProbe()` (`src/Streaming/Codec/FFprobeRunner.php:46-57`) solo conserva en
  `codecs` el **primer** stream de audio y el **primer** stream de video (`:48-51`),
  mientras que los subtítulos sí se guardan como lista (`:53-57`). Por tanto, **los
  metadatos (idioma, códec) de las pistas de audio adicionales se descartan durante el
  parseo**. La pérdida ocurre en el parser de XC_VM, no en ffprobe, y las pistas siguen en
  el fichero.
- El resultado se guarda en `movie_properties`: `video` (`src/Cli/CronJobs/VodCronJob.php:164`),
  `audio` (`:167`) y `subtitle`, este último solo si `extract_subtitles` está activo
  (`:169-171`). También se guarda en `streams_servers.stream_info` (`:200`) el resultado
  **ya parseado**, que tampoco incluye los audios adicionales.
- **Cuidado:** `TmdbCron` **reescribe `movie_properties` completo** con `audio: []` y
  `video: []` (`src/Domain/Vod/TmdbCron.php:283-284`, `:297-303`; episodios en `:532-547`).
  Cualquier dato extra que guardemos ahí se pierde en el siguiente refresco de TMDb.

### d.3 Qué reciben las apps hoy

En `src/Public/Controllers/Api/PlayerApiController.php`:

- **`get_vod_info`** (`:897-961`): `info` = `movie_properties`. Agrega `info.subtitles[]`
  con `{index, language, title}` (`:939-948`) y **luego borra `audio`, `video` y `subtitle`**
  (`:950-954`).
- **`get_series_info`** (`:405-523`): lo mismo por episodio. `subtitles` se arma en
  `:501-510` y el borrado está en `:512-516`.
- **`get_vod_streams`** arma cada fila con una lista fija de campos sin datos de pistas
  (`src/Public/Controllers/Api/PlayerApiController.php:1052`). **`get_series`** (`src/Public/Controllers/Api/PlayerApiController.php:525-618`) tampoco los incluye **[inferido]**.

### d.4 Dónde agregar los idiomas (propuesta)

**Opción A, recomendada si el VOD se analiza en MAIN: un módulo propio y cero cambios en la
API.**

1. Crear un módulo `src/Modules/crplay_media/`. Debe escuchar
   `#[ListensTo(MediaAnalyzedEvent::class)]`, que se despacha en `src/Cli/CronJobs/VodCronJob.php:202`
   (`src/Core/Events/Vod/MediaAnalyzedEvent.php:13`).
2. El módulo vuelve a ejecutar ffprobe y guarda en `movie_properties` claves nuevas, por
   ejemplo `audio_languages: ["spa","eng"]` y `subtitle_languages: [...]`.
3. Como `get_vod_info` copia `movie_properties` dentro de `info` (`src/Public/Controllers/Api/PlayerApiController.php:923`) y solo borra
   `audio`, `video` y `subtitle`, **las claves nuevas llegan solas a las apps**. En las series
   pasa lo mismo, en `episodes[].info` (`src/Public/Controllers/Api/PlayerApiController.php:482`).

Limitaciones de la opción A:

- Los módulos solo existen en MAIN: el build de LB los excluye (`Makefile:25`). El evento
  se despacha en el nodo donde corre `cron:vod` (`src/Cli/CronJobs/VodCronJob.php:202`), así que
  **para VOD analizado en un LB la opción A no funciona**: a MAIN solo viajan las claves de
  `ANALYSIS_KEYS` (`src/Domain/Stream/ContentSink.php:42`, `src/Domain/Cluster/EventIngest.php:652`).
  En esa topología habría que usar la opción B o diseñar otro transporte.
- `TmdbCron` borra las claves al refrescar. El módulo tendría que volver a escribirlas
  (por ejemplo, escuchando también el evento de TMDb, si existe; **[pendiente]**).
- `player_api` se atiende con `StreamingRequestBootstrap` (`src/Public/index.php:173`,
  `:194-195`), y en ese camino no se llama a `ModuleLoader` (solo se llama para la API REST
  en `src/Public/index.php:123-126` y para admin/revendedor en `:279-283`). Por eso no se puede usar un
  *hook* en la propia respuesta.

**Opción B: parche mínimo al código de upstream (en nuestro fork).**

1. En `src/Streaming/Codec/FFprobeRunner.php:48-51`, guardar también una lista con **todas** las pistas de audio
   (por ejemplo `codecs['audio_all'][]`).
2. Guardarla en `movie_properties` en `cron:vod` (`src/Cli/CronJobs/VodCronJob.php:164-171`) y
   agregar la clave a `ANALYSIS_KEYS` (`src/Domain/Stream/ContentSink.php:42`), que es la misma lista con
   la que MAIN filtra lo que llega de un LB (`src/Domain/Cluster/EventIngest.php:652`).
3. En `src/Public/Controllers/Api/PlayerApiController.php`, justo antes de los `unset` (`src/Public/Controllers/Api/PlayerApiController.php:950` y `src/Public/Controllers/Api/PlayerApiController.php:512`), generar
   `info.audio_languages` a partir de esa lista.

Son pocas líneas, pero repartidas en 4 archivos de upstream. Proponerlo a upstream
requeriría autorización de Gilberth (ver `AGENTS.md`).

---

## e) Compatibilidad con la API de Xtream Codes

### e.1 Rutas

Todas están en `src/bin/nginx/conf/nginx.conf`:

| URL estilo XC | Destino | Cita |
|---|---|---|
| `/player_api.php`, `/panel_api.php` | `PlayerApiController` | `:137-139`, `:374-410` |
| `/get.php` | `PlaylistApiController` | `:133-135` |
| `/xmltv.php` | `EpgApiController` | `:129-131` |
| `/live/u/p/id.ext` y `/u/p/id.ext` | `auth.php` → `live.php` | `:121-127` |
| `/movie/u/p/id.ext` | `auth.php?type=movie` | `:99-100` |
| `/series/u/p/id.ext` | `auth.php?type=series` | `:101-102` |
| `/timeshift/u/p/dur/start/id.ext` | `auth.php?type=timeshift` | `:111-112` |
| `/enigma2.php`, `/xplugin` | Enigma2 / XPlugin | `:374-410` |
| Legacy `/streaming/*.php` | 302 hacia las rutas nuevas | `:147-161` |
| `/c/` | Portal Ministra (MAG) | `:482-518` |

### e.2 Acciones de `player_api.php`

Implementadas (`src/Public/Controllers/Api/PlayerApiController.php:134`):

- `get_live_categories`, `get_vod_categories`, `get_series_categories`
- `get_live_streams`, `get_vod_streams`, `get_series`
- `get_vod_info`, `get_series_info`
- `get_short_epg`, `get_simple_data_table`
- alias numéricos 200–209

Sin `action`, devuelve `user_info` + `server_info` (`:1065-1106`).

**Diferencias con el XC original:**

- **Extra:** `get_epg` (`:364-403`), `subtitles[]` en VOD y episodios, autenticación con
  `token=`, cuerpo JSON por POST (`:116-125`), paginación (`:129-130`) y login por código
  de activación (`:172-193`).
- **Quitado:** `info.audio` e `info.video` en `get_vod_info` y en los episodios
  (`:950-954`, `:512-516`). Algunas apps los usan para mostrar el códec o la resolución.
  **[Probar con nuestras apps.]**
- Cada endpoint puede apagarse con un ajuste: `legacy_get`
  (`src/Public/Controllers/Api/PlaylistApiController.php:34`), `legacy_xmltv`
  (`src/Public/Controllers/Api/EpgApiController.php:32`), `disable_player_api` (`src/Public/Controllers/Api/PlayerApiController.php:96-105`) y
  `legacy_panel_api` (`:107-114`).
- `get.php`: tipos `m3u`, `m3u_plus` y 18 más (`src/bin/install/database.sql:1122-1142`). Salidas `ts`,
  `m3u8` y `rtmp` (`:1164-1167`).

El script `tools/test_player_api.sh` prueba todas las acciones. Es útil para validar la
compatibilidad en el laboratorio.

---

## f) Migración desde XUI.one

**Sí existe una herramienta.**

- **Comando:** `console.php migrate` (`src/Cli/Commands/MigrateCommand.php:68-70`). La lógica
  está en `src/Cli/migration_logic.php` (unas 1600 líneas).
- **Guía:** `docs/en/info/migration_guide.md`.
- **Pasos:**
  1. `sudo /home/xc_vm/console.php tools migration /ruta/backup.sql`. Restaura el dump en la
     base `xc_vm_migrate` (`src/Cli/Commands/ToolsCommand.php:206-207`, `:134`).
  2. `/home/xc_vm/console.php migrate`, o desde la página web de *setup*
     (`src/Public/Views/admin/setup.php:47-67`).
- **Detección:** si el backup tiene la tabla `access_codes`, se trata como **XUI.one**; si
  no, como **Xtream Codes** (`src/Cli/migration_logic.php:110-111`, `:690`).

**Tablas que mapea desde XUI.one** (casi todas 1:1 con el mismo nombre,
`src/Cli/migration_logic.php:129-689`):

- `access_codes`, `users` (admins/revendedores), `lines` (clientes)
- `users_groups`, `users_packages`, `bouquets`
- `streams`, `streams_servers`, `streams_options`, `streams_categories`
- `streams_series`, `streams_episodes`, `servers`, `epg`
- `mag_devices`, `enigma2_devices`, `rtmp_ips`
- `blocked_ips`, `blocked_uas`, `blocked_isps`
- `profiles`, `tickets`, `tickets_replies`

Cada tabla se vacía (TRUNCATE) y las filas se insertan filtrando con
`QueryHelper::verifyPostTable()`, que **descarta en silencio las columnas que no existen**
(`src/Core/Database/QueryHelper.php:65-90`). El estado de ejecución se reinicia
(`src/Cli/migration_logic.php:547-551`).

El mapeo desde Xtream Codes, con renombres como `users`→`lines`, `reg_users`→`users` y
`series`→`streams_series`, está en `src/Cli/migration_logic.php:690-1590`.

**Lo que NO se migra y puntos de atención:**

- **Ajustes:** solo se copian `server_name` y `default_timezone` (`:1592-1599`). TMDb y el
  resto se configuran de nuevo (`docs/en/info/migration_guide.md:11-22`).
- **LB:** no se migran (`docs/en/info/migration_guide.md:114`). Solo se copian las filas de `servers`.
- **Admin creado en el setup:** al importar `users`, **se borra el admin creado durante la
  instalación**. Hay que usar `tools user` / `tools access` (`docs/en/info/migration_guide.md:94-106`).
- **Contraseñas:** los hashes de admin se copian tal cual y siguen funcionando porque usan
  `crypt()` con la sal guardada (`src/Core/Auth/Authenticator.php:290-292`). Las
  contraseñas de las líneas están en texto plano en el origen y en el destino.
- **Rutas:** las fuentes de stream y las rutas de VOD se copian tal cual. Hay que reajustarlas.
- **`server_id`:** no se ajusta `config.ini`. **[Posible]** desajuste si el main antiguo no
  tenía id 1.
- **`providers`:** aparece en la web pero no en `XUI_TABLES`
  (`src/Cli/Commands/MigrateCommand.php:20`). Desde la CLI no se migra.
- **Bug (rama XC):** el tipo 4 (radio) se traduce a `'radio'` y se escribe en
  `$rResult['bouquet_radio']` (`src/Cli/migration_logic.php:875-877`). Pero el campo que se
  inicializa y normaliza es `bouquet_radios` (`src/Cli/migration_logic.php:870`, `:887-889`), que es la columna
  real (`src/bin/install/database.sql:152`). Resultado: las radios de los bouquets de XC no se
  migran. **[inferido]** `verifyPostTable()` descarta la clave sobrante.
- **Dump no confiable:** el restore solo filtra `USE` / `CREATE DATABASE`
  (`src/Cli/Commands/ToolsCommand.php:155-178`). Usar **solo dumps propios**.

**Recomendación:** antes de migrar datos reales, hacer un ensayo en el laboratorio con un
dump **anonimizado** de XUI.one y comparar conteos por tabla.

---

## g) Seguridad

### g.1 Autenticación

- **Admin y revendedor:**
  - Hash SHA-512 crypt con 20 000 rondas (`src/Core/Auth/Authenticator.php:273-281`),
    comparado con `hash_equals` (`:291`).
  - Sesión ligada a la IP (`ip_logout=1`, `src/bin/install/database.sql:1522`) y cookie `httponly` +
    SameSite (`src/Core/Bootstrap/Stage/SessionStage.php:41-52`). **`session.cookie_secure`
    no se activa:** en `php.ini` está comentado (`src/bin/php/lib/php.ini:1347`) y
    `SessionStage` no lo fija.
  - **Código de acceso:** el panel vive en una ruta secreta (por ejemplo `/abcd1234/`). Se
    genera un `location` de nginx por código, con lista blanca de IPs opcional
    (`src/Core/Auth/AuthRepository.php:115-175`).
  - **2FA TOTP** con 10 códigos de recuperación de un solo uso (`src/Core/Auth/TwoFactor.php:11`,
    `:33`) y bloqueo tras 10 fallos en 900 s (`:24-31`).
    reCAPTCHA está **apagado por defecto** (`src/bin/install/database.sql:1525`).
  - Anti-fuerza bruta: `login_flood` por IP en 24 h (`src/Core/Auth/Authenticator.php:243-251`).
- **Líneas (clientes):**
  - **Usuario y contraseña viajan en la URL** (`/live/u/p/…`, `player_api.php?username=…`).
  - La contraseña se compara **en texto plano** (`src/Domain/User/UserRepository.php:113`).
  - Los tokens de stream van cifrados con AES-256-GCM (`src/Core/Util/Encryption.php:87-117`,
    `secure_stream_tokens=1`, `src/bin/install/database.sql:1773`). Sigue existiendo un formato *legacy*
    AES-CBC con IV fijo y sin MAC (`src/Core/Util/Encryption.php:33-66`).
  - Protección de fuerza bruta en streaming: `BruteforceGuard` registra la IP bloqueada
    (`src/Core/Auth/BruteforceGuard.php:109-127`) y el cron de root la aplica con ipset:
    conjuntos `xcvm_block4`/`xcvm_block6` y una regla `iptables -m set … -j DROP`
    (`src/Cli/CronJobs/RootSignalsCronJob.php:271`, `:304`, `:316`).
- **API del panel:**
  - Tokens `xct_…` guardados como SHA-256 (`src/Core/Auth/ApiTokens.php:24`, `:47-66`).
  - El `api_key` *legacy* está **activo por defecto** y se compara en texto plano
    (`src/bin/install/database.sql:1486`, `src/Core/Auth/ApiTokens.php:75-99`).
  - Hay una acción `mysql_query` que ejecuta **SQL arbitrario**. Exige el permiso
    `database` (`src/Public/Controllers/Api/AdminApiController.php:33`, despacho en `:290-291`) y,
    **además**, si la petición usa un token, que sea de alcance `full` con `allow_sql`
    (`src/Public/Controllers/Api/AdminApiController.php:239-240`, `src/Core/Auth/ApiTokens.php:105-113`).
- **Entre servidores:** se describe en [a.4](#a4-main-y-balanceadores-lb).

### g.2 Qué expone a internet y qué puertos usa

| Puerto | Servicio | Exposición por defecto | Cita |
|---|---|---|---|
| 80 / 443 (se preguntan al instalar) | nginx: panel, streaming, API XC, `/cluster/v1/` | Público | `src/bin/nginx/conf/ports/http.conf:1`, `src/bin/nginx/conf/ports/https.conf:1`, `install:2171-2198` |
| 8880 | RTMP | Público | `src/bin/nginx_rtmp/conf/port.conf:1` |
| 3306 | MariaDB | **Escucha en todas las interfaces** (`bind-address = *` y `0.0.0.0`). Escuchar no es lo mismo que estar accesible desde internet: eso depende del firewall del host o del proveedor | `install:274`, `install:1274` |
| 6379 | KeyDB | **`bind *`**, `port 6379`, `protected-mode yes`, `requirepass` de 512 caracteres y comandos peligrosos desactivados (`CONFIG`, `DEBUG`, `SHUTDOWN`, `SLAVEOF`, `REPLICAOF`, `MIGRATE`, `MODULE`). Reduce el riesgo, pero **no sustituye un firewall** | `src/bin/redis/redis.conf:1-3`, `:25`, `:59-65`, `src/Cli/Commands/StatusCommand.php:375-383` |
| 31210 | Control y estadísticas de RTMP | Escucha en todas las interfaces (`listen 31210`), pero nginx solo **permite** 127.0.0.1 (`allow 127.0.0.1; deny all`) | `src/bin/nginx_rtmp/conf/nginx.conf:29-33` |
| 31290 | Proxy local de `xc_agent` | Solo `127.0.0.1` (`XC_VM_Fanout:internal/clusteragent/relayproxy.go:66`) | `src/Core/Cluster/DataPlane.php:49-50` |
| `cluster_api_port` (opcional) | API de clúster en un puerto aparte | Lo genera la configuración en `cluster.d/`; **[no determinable]** sin instalar | `src/bin/nginx/conf/nginx.conf:537-540` |

**Endpoints públicos:**

- Con credenciales de línea: `/stream/*`, `/player_api`, `/playlist` (`get.php`), `/epg`
  (`xmltv.php`), `/enigma2` y `/xplugin`.
- `/healthz` está abierto; `/metrics` pide un token Bearer (`metrics_token`), comprobado en
  PHP (`src/bin/nginx/conf/nginx.conf:412-421`, `src/Public/Controllers/Api/MetricsController.php:41-55`).
- `/api` (interna) requiere secreto + IP (`src/bin/nginx/conf/nginx.conf:451-479`).
- No incluye phpMyAdmin ni gestor de archivos.

**Puertos mínimos que debería abrir el firewall:**

- **MAIN:** 80/443 (y 8880 si se usa RTMP).
- **LB:** 80/443 hacia los clientes.
- **3306 y 6379: solo hacia los LB en modo legacy**, o cerrados del todo con la API de
  clúster. Para eso existe `cluster:db-allowlist`, que crea la cadena iptables `XCVM_DB`
  (`src/Domain/Cluster/DbAllowlist.php:8`, `:36-38`). Viene **apagado**
  (`src/bin/install/database.sql:1770`).

### g.3 Riesgos detectados

**Riesgos de diseño:**

1. **MariaDB (3306) y KeyDB (6379) quedan escuchando en todas las interfaces.** Si no hay
   un firewall del host o del proveedor, ni una ACL externa, los puertos **podrían** quedar
   accesibles desde redes no deseadas (escuchar ≠ estar accesible). En `install` no hay
   ninguna llamada a ufw, iptables ni ipset: solo aparecen como nombres de paquetes
   (`install:48`, `:235`). El cron de root sí gestiona después las listas de bloqueo de ipset
   (`src/Cli/CronJobs/RootSignalsCronJob.php:271`, `:316`), pero eso no filtra puertos.
2. El usuario de BD de la app tiene **`ALL PRIVILEGES ON mysql.*`**, la base de sistema
   con las tablas de usuarios y privilegios (`install:2089`, `:2098`). El `GRANT OPTION`
   es solo sobre `xc_vm.*` (`install:2090`, `:2099`). **[inferido]** Con escritura en
   `mysql.*` podría modificar privilegios, que se aplicarían al recargarlos o reiniciar
   MariaDB.
3. Las credenciales de las líneas viajan en la URL y se guardan en texto plano. Quedan en
   logs y proxies, sobre todo con HTTP sin TLS. Es lo normal en el protocolo XC, pero hay que
   tenerlo presente.
4. **Componentes compilados: hay que distinguirlos.**
   - **Entre los componentes revisados, `xcvm_core.so` es la única pieza de Vateron Media
     sin código fuente público localizado.** Es una extensión
     compilada cuyo código no está en XC_VM ni en los repos públicos revisados, así que su
     funcionamiento interno no se puede auditar (`src/bin/php/lib/php.ini:1905`). Interviene
     en la conexión a BD y Redis (`\XC_VM::db_connect`, `redis_connect`, `config_pack`), el
     restore, los grants de BD de los LB, las licencias, los módulos y los tokens del clúster
     (`docs/en/development/cluster-api.md:56`). Se descarga de la rama `main` de
     `Vateron-Media/XC_VM_Binaries` (`src/bin/install/install_xcvm_core.sh:16-25`).
   - El loader de **ionCube** (`src/bin/php/lib/php.ini:1899`), un componente de terceros
     sin código público.
   - **`xc_fanout` y `xc_agent` NO son cerrados:** su código fuente Go es público (AGPL-3.0)
     en `Vateron-Media/XC_VM_Fanout` (`cmd/xc_fanout/`, `cmd/xc_agent/`). Lo que sí existe es
     un **riesgo de cadena de suministro**: en producción XC_VM descarga **binarios
     compilados** de las releases de ese repo (`src/Cli/Commands/FanoutBinaryCommand.php:17-19`,
     `:93`), verificados con un `SHA256SUMS` del mismo release (`:241-247`). Nada garantiza
     por sí solo que el binario corresponda al código publicado: habría que compilarlo
     nosotros (`XC_VM_Fanout:release.sh`) y comparar.
5. **"Phone home":**
   - Servidor de licencias: según el README, una instalación con el crédito visible **no lo
     contacta nunca**; la guía de licencias dice lo contrario. Ver **g.4**: la decisión real
     está en `xcvm_core.so` (sin código fuente público).
   - Actualizaciones desde GitHub (`src/Core/Updates/GitHubReleases.php:88`).
   - Resolver DNS 1.1.1.1 (`src/bin/nginx/conf/nginx.conf:81`).
6. **Integridad de las descargas:** se comprueba con MD5 o SHA256 publicados **en el mismo
   sitio** que el binario, **sin firma** (`src/Cli/Commands/UpdateCommand.php:128-157`).
7. Hay una clave por defecto `OPENSSL_EXTRA` en el código
   (`src/Core/Config/ConstantsInitializer.php:167`). `resolveOpensslExtra()` la sustituye por el
   contenido del fichero (`src/Core/Config/ConstantsInitializer.php:68-72`), así que solo se usa si falta (o está vacío)
   `config/openssl_extra`, que el instalador genera (`install:2121-2125`). **Verificar que el
   fichero existe.**

**Hallazgos puntuales [posibles, sin explotar]** (todos requieren estar autenticado como
admin o servidor):

- `rtmp_kill` mete `escapeshellcmd($rName)` dentro de un argumento de wget entre comillas
  dobles (`src/Public/Controllers/Api/InternalApiController.php:205-207`). `escapeshellcmd` no escapa
  comillas **emparejadas**, así que un nombre con `"…"` podría cerrar la comilla e
  inyectar argumentos a wget. Requiere el secreto de la API interna y una IP permitida.
- `signal_send` / `redirect_connection` escriben en `SIGNALS_PATH.$uuid` sin validar la ruta
  (`src/Public/Controllers/Api/InternalApiController.php:383-386`, `:404-413`).
- El código de acceso solo se valida por longitud, lista de nombres reservados y
  duplicados (`src/Core/Auth/AuthService.php:72-90`): no se validan sus caracteres. Luego
  se usa en el nombre del fichero y el `location` de nginx
  (`src/Core/Auth/AuthRepository.php:172-174`). Solo lo puede crear un admin.
- SSRF: `ImageUtils::downloadImage` acepta cualquier URL que empiece por `http` con
  extensión jpg/jpeg/png (o la consulta con `getimagesize()` si no tiene extensión), sin
  filtro de destino (`src/Core/Util/ImageUtils.php:63-75`). `probeTargetAllowed` bloquea
  loopback, link-local y multicast, pero **no** las redes privadas RFC1918
  (`src/Core/Util/NetworkUtils.php:180-202`, lista en `:243`).

Reportarlos a upstream (por ejemplo, con un GitHub Security Advisory privado) **requiere
autorización de Gilberth**: `AGENTS.md` prohíbe abrir PRs o issues en
`Vateron-Media/XC_VM`.


### g.4 Licencia: el README contradice la guía; qué dice el código

**Lo que dice el README** (versión del 05-10-2026):
- "100% free. No license checks. No server locks." (`README.md:55`).
- Con el crédito "Vateron Media · AGPL-3.0" visible, todo es gratis y todo funciona,
  **incluidos los balanceadores** (`README.md:359-362`).
- Solo un **white-label** (crédito quitado) necesita una clave de activación, gratuita y
  ligada a la máquina, para añadir LB o nodos de clúster (`README.md:360-364`).
- Una instalación comunitaria (crédito intacto) **nunca contacta el servidor de licencias**.
  Un white-label contacta `https://www.xcvm.tech` para obtener la clave, revisar
  revocaciones cada semana y hacer un *check-in* diario (`README.md:368-372`).

**Lo que dice la guía** `docs/en/info/licensing-and-activation.md` (versión del 23-09-2026):
- Para las funciones con licencia se necesitan **dos condiciones a la vez: el crédito Y una
  clave** de activación. "Neither condition replaces the other" (`:9-16`).
- La licencia es necesaria para **trabajar con varios servidores** (LB) (`:79-80`).
- El panel verifica la clave **una vez al día** con el servidor de licencias (`:47-48`),
  con 14 días para la primera verificación (`:55`).
- Si la licencia caduca o se revoca, el main **desconecta los LB** (`:84`).

**La contradicción:** según el README, con el crédito visible los LB son gratis y no hay
contacto con el servidor. Según la guía, hace falta una clave además del crédito, y hay
verificación diaria.

**Qué dice el código:**
- Los **comentarios** del PHP coinciden con el README:
  - `LicenseGate::licensed()` se describe como "una instalación comunitaria que conserva el
    crédito, **o** una white-label con clave válida" (`src/Core/License/LicenseGate.php:54-58`);
  - el comentario de `server:install` habla de "un panel white-label sin licencia"
    (`src/Cli/Commands/ServerInstallCommand.php:120-121`).
  El código ejecutable, en cambio, no distingue entre comunidad y white-label: rechaza el LB
  siempre que `licensed()` sea falso (`src/Cli/Commands/ServerInstallCommand.php:124-129`).
- **El veredicto lo da código sin fuente pública.** `licensed()` devuelve lo que diga
  `\XC_VM::license_valid()` de la extensión `xcvm_core` (`src/Core/License/LicenseGate.php:67-68`).
  **Si la extensión o ese método no están, devuelve `true`** (`src/Core/License/LicenseGate.php:71`).
  La comprobación del crédito llama a `\XC_VM::verify_branding()`
  (`src/Core/Integrity/AttributionGuard.php:58-59`). Según el comentario de
  `src/Cli/Commands/ServerInstallCommand.php:122-123`, el permiso de BD de los LB lo da
  `\XC_VM::db_grant()`, también en `xcvm_core.so`. Desde el repo **no se puede confirmar** qué hacen.
- El encabezado de la misma clase da una tercera versión: clave ligada al HWID,
  revalidación **semanal** y **7 días** de gracia sin conexión
  (`src/Core/License/LicenseGate.php:12-14`).
- La licencia controla **también el uso del daemon `xc_fanout`**, y no solo los LB. Si `licensed()` devuelve `false`,
  la entrega en vivo cae al camino legacy (`src/Core/License/LicenseGate.php:42-51`,
  `src/Public/stream/live.php:131`, `src/Domain/Stream/StreamProcess.php:1540`). Ni el README
  (que sí habla del fanout, pero solo de su consumo de RAM, `README.md:270-288`) ni la guía
  relacionan el fanout con la licencia.
- `php.ini` tiene una directiva `xcvm_core.server_url` con un valor ofuscado
  (`src/bin/php/lib/php.ini:1910`). Qué hace la extensión con ella no es verificable.

**Conclusión: comportamiento [no determinable desde el código PHP público].**

> El README, la guía de licencias y algunos comentarios del código no son del todo
> coherentes. Los comentarios del PHP visible describen que una instalación comunitaria con
> el crédito intacto se considera válida, mientras que `licensing-and-activation.md` describe una clave de
> activación para las funciones con varios servidores. La decisión real depende de
> `XC_VM::license_valid()`, implementada en `xcvm_core.so`; por tanto, **el comportamiento
> exacto hay que comprobarlo en el laboratorio**.

- No se da ninguna de las dos versiones por cierta. Por las fechas del último commit de
  cada fichero en upstream (`git log -1 -- README.md`: 05-10-2026;
  `git log -1 -- docs/en/info/licensing-and-activation.md`: 23-09-2026) y por los comentarios
  del PHP, **la guía parece desactualizada**, pero eso no prueba lo que hace la extensión.
- **No es un hecho que sin licencia no funcionen los LB:** queda como riesgo y pregunta
  abierta (R3).

**Prueba de laboratorio propuesta** (dos ensayos comparables, cada uno con instalación
limpia, crédito intacto y sin clave de activación):

0. **En ambos ensayos, antes de nada:** comprobar que `xcvm_core` está cargada y que
   `\XC_VM::license_valid()` existe. Sin la extensión, `licensed()` devuelve `true` y la
   falta del banner de activación (`src/Public/Views/admin/dashboard.php:76`) no
   demostraría nada. Capturar el tráfico saliente (DNS/HTTP/HTTPS) **desde el primer
   arranque**.
1. **Ensayo A, conectado:** sin bloquear nada.
2. **Ensayo B, bloqueado desde el primer arranque:** bloquear la salida hacia el servidor
   de licencias (`xcvm.tech`, `www.xcvm.tech` y cualquier otro destino que aparezca en la
   captura del ensayo A), y comprobar que el bloqueo es efectivo.
3. En los dos: intentar añadir un LB.
4. Comprobar en los dos:
   - si se crea el LB;
   - si sigue funcionando a las 24 y 48 h, a los 8 días (posible revalidación semanal) y
     a los **15 días** (la guía habla de una ventana de 14 días);
   - el resultado de `LicenseGate::licensed()` y de `\XC_VM::license_valid()`, consultados
     directamente;
   - si la entrega en vivo usa el fanout;
   - el tráfico saliente relacionado con licencias. Ojo: no ver tráfico durante la prueba no
     demuestra que "nunca" lo haya.
---

## h) Instalador: qué toca y qué descarga

**Esta sección es para revisarla ANTES de ejecutar nada.**

### h.1 Qué es

- `install`, en la raíz del repo, es un **script Python 3** de unas 2500 líneas (`install:1`).
- **Exige root** (`install:425-430`) y es interactivo: pregunta la contraseña de root de
  MariaDB, si sobrescribir `/home/xc_vm`, los puertos HTTP/HTTPS y si sobrescribir sysctl.
- Sistemas soportados: Ubuntu 20.04/22.04/24.04 y Debian 12/13 (`install:24-27`). **Ubuntu
  24.04 está soportado.**

### h.2 Qué descarga y de dónde

| Qué | URL / origen | Verificación | Cita |
|---|---|---|---|
| Panel `XC_VM.zip` (si no hay uno local) | `github.com/Vateron-Media/XC_VM/releases/download/<tag>/` | MD5 de `hashes.md5` del mismo release | `install:994-1062`, `install:478-480` |
| Repo de MariaDB | **`curl https://r.mariadb.com/downloads/mariadb_repo_setup \| bash`** | Ninguna | `install:1152` |
| PPA de MaxMind (solo Ubuntu) | `ppa:maxmind/ppa` | apt | `install:1170` |
| Bundle de binarios (PHP, nginx) | `github.com/Vateron-Media/XC_VM_Binaries/releases/.../ubuntu_24.tar.gz` | MD5 del mismo release | `install:505-634` |
| `xcvm_core.so` (compilado, **sin código fuente público**) | `raw.githubusercontent.com/Vateron-Media/XC_VM_Binaries/main/bin/xcvm_extention` | SHA256 del mismo sitio | `src/bin/install/install_xcvm_core.sh:16-27` |
| FFmpeg | `Vateron-Media/XC_VM_FFMPEG` releases | MD5 | `src/Cli/Commands/FfmpegBuildsCommand.php:84`, `:172-174` |
| `xc_fanout` / `xc_agent` (binarios compilados de **código abierto**, AGPL) | `Vateron-Media/XC_VM_Fanout` releases | SHA256SUMS del mismo release | `src/Cli/Commands/FanoutBinaryCommand.php:93`, `:241-247` |
| GeoLite2 | Release de `XC_VM_Update`, o `download.maxmind.com` si hay credenciales | — | `src/Core/GeoIP/GeoLiteReleaseUpdater.php:30`, `src/Core/GeoIP/MaxMindUpdater.php:23` |
| Proxy | `Vateron-Media/XC_VM_Proxy` | — | `install:2459-2463` |

### h.3 Qué cambia en el sistema

1. **Paquetes:**
   - Borra a la fuerza los *locks* de dpkg/apt (`install:1886-1897`).
   - Instala unos 40 paquetes, entre ellos mariadb-server, certbot, git, ipset e
     iptables-persistent. En Ubuntu 24 también instala **`ufw`**, sin configurarlo
     (`install:29-254`, `:235`).
2. **Desactiva o desinstala:** para y deshabilita **apache2 y el nginx del sistema**, y
   desinstala `mysql-server` (`install:1908-1914`, lista `rRemove` en `install:256`).
3. **Usuario:** crea el usuario de sistema `xc_vm` sin shell (`install:1958`).
4. **Ficheros del panel:**
   - Extrae el panel en `/home/xc_vm/`.
   - Genera un certificado autofirmado (`install:912-940`).
   - Escribe `/home/xc_vm/config/config.ini` con las credenciales de BD
     (`install:2109-2113`), usando `write_secret()`, que crea el fichero con modo 0600
     (`install:1388-1393`).
5. **MariaDB:**
   - Cambia root a `mysql_native_password` (`install:1244`).
   - **Sobrescribe `/etc/mysql/mariadb.conf.d/50-server.cnf`** con `bind-address = *`
     (`install:257-338`, `:2051-2059`).
   - Crea `99-custom.cnf` con `bind-address = 0.0.0.0` (`install:1273-1291`).
   - Crea las bases `xc_vm` y `xc_vm_migrate` (`install:2069-2077`).
   - Crea un usuario aleatorio con `ALL` sobre `xc_vm.*`, `xc_vm_migrate.*` y `mysql.*`, y
     `GRANT OPTION` sobre `xc_vm.*` (`install:2085-2106`).
6. **`/etc/fstab`:** agrega tmpfs para `content/streams` (90 % de la RAM) y para `tmp`
   (6 GB), y ejecuta `mount -a` (`install:2136-2157`, línea de fstab en `install:2151`).
7. **sysctl (si se responde Y):** **sobrescribe `/etc/sysctl.conf` completo** (BBR, buffers,
   `somaxconn`…) (`install:355-387`, `:2219-2231`).
8. **systemd:**
   - Agrega `DefaultLimitNOFILE` a `/etc/systemd/system.conf` y `user.conf`
     (`install:2238-2254`).
   - Crea y habilita `xc_vm.service` (`install:2258-2267`).
9. **Capabilities:** aplica `setcap cap_net_bind_service` a los dos nginx (`install:2358-2367`).
10. **Después del primer arranque** (`console.php status` como root):
    - escribe `/etc/sysctl.d/50-allports-nonroot.conf` con
      `ip_unprivileged_port_start=0`, que permite a **cualquier** proceso usar puertos <1024
      (`src/Cli/Commands/StatusCommand.php:261`);
    - instala el crontab de root y lo deja inmutable con `chattr +i`
      (`src/Cli/Commands/StartupCommand.php:324`).
11. **Credenciales en claro:**
    - imprime la contraseña de root de MariaDB en pantalla (`install:1846`);
    - la guarda en `/root/credentials.txt` y en un `credentials.txt` junto al instalador
      (`install:2373-2394`).
12. **Generación de secretos:** las contraseñas y los códigos se generan con `random.choice`
    de Python, que **no es criptográficamente seguro** (`install:1396-1406`, `:2298`).
13. **Firewall:** el instalador **no configura ninguno**: en `install` ufw, iptables e
    ipset solo aparecen como paquetes a instalar (`install:48`, `:235`).

**Instalación de un LB por SSH:** además, **detiene y deshabilita AppArmor**
(`src/Cli/Commands/ServerInstallCommand.php:385-386`). Verifica la clave del host SSH con
SHA-1, aceptando la primera que ve o una fijada (TOFU, `src/Cli/Commands/ServerInstallCommand.php:161-188`).

### h.4 Recomendaciones antes de correrlo en el laboratorio

1. Hacer un **snapshot de la VM** antes de empezar. El instalador modifica `/etc` de forma
   difícil de revertir: fstab, sysctl, MariaDB y `chattr +i` (y AppArmor en los LB).
2. Poner antes un **firewall** que deje entrar solo SSH, 80 y 443 desde nuestra red, y
   **cerrar 3306 y 6379**.
3. Revisar o fijar las versiones de las descargas (tag concreto en vez de `latest`/`main`).
4. Mover o borrar `credentials.txt` después de instalar.

---

## i) Riesgos y preguntas abiertas

### Riesgos

| # | Riesgo | Impacto | Sección |
|---|---|---|---|
| R1 | MariaDB 3306 y KeyDB 6379 escuchan en todas las interfaces y el instalador no gestiona ningún firewall: **exposición potencial** si tampoco hay firewall del proveedor | Medio | g.2, g.3, h.3 |
| R2 | **Dependencia de `xcvm_core.so`** (más ionCube), que entre los componentes revisados es la única pieza de Vateron Media sin código fuente público localizado: BD, Redis, grants, licencias y tokens del clúster pasan por ella. Aparte, hay riesgo de **cadena de suministro** de binarios compilados que se descargan de `main`/`latest` sin firma: `xcvm_core.so`, bundle de PHP/nginx, ffmpeg, y `xc_fanout`/`xc_agent` (estos dos **sí** con código público en `XC_VM_Fanout`) | Medio | g.3, h.2 |
| R3 | **Licencia: documentación contradictoria y comportamiento [no determinable desde el código PHP público]:** el README dice que con el crédito visible los LB son gratis y no hay contacto externo; la guía exige una clave y verificación diaria. La decisión está en `xcvm_core.so`, que también controla el uso del fanout. **No es un hecho que sin licencia no funcionen los LB**: hay que comprobarlo en el laboratorio (prueba en g.4) | Medio (pendiente de prueba) | g.4 |
| R4 | El instalador es invasivo: sobrescribe configs de `/etc`, desactiva apache y el nginx del sistema, hace `curl\|bash` y deja `chattr +i`. Al instalar un LB, además desactiva AppArmor | Medio | h.3 |
| R5 | La política "gana el nuevo" permite compartir cuentas por turnos (*ping-pong*) y no muestra el video de conexiones sobrepasadas | Medio (negocio) | c |
| R6 | HLS on demand puede tardar más de 5 s y responder "not on air" en el primer intento | Medio (negocio) | b |
| R7 | Solo se conservan los **metadatos** del primer stream de audio (el parser descarta los demás), y TMDb borra los datos de pistas al refrescar | Bajo/Medio | d |
| R8 | La migración desde XUI borra el admin inicial, no copia la mayoría de los ajustes (solo `server_name` y `default_timezone`) ni los LB, y descarta columnas en silencio | Medio | f |
| R9 | Usuario de BD con `ALL ON mysql.*` | Medio | g.3 |
| R10 | Varios cambios deseados (límite de conexiones, idiomas) tocan código de upstream y ponen en riesgo la sincronización | Medio | c.5, d.4 |
| R11 | **PHP 8.1 está fuera de soporte de seguridad desde el 31-12-2025** (https://www.php.net/eol.php). Composer declara la plataforma 8.1.33 (`src/composer.json:35`) y el README también dice PHP 8.1.33 (`README.md:88`). La versión que se ejecuta de verdad viene del bundle de `XC_VM_Binaries` (ver a.1 y h.2). Prueba de laboratorio: `/home/xc_vm/bin/php/bin/php -v` y guardar la versión exacta. Si es 8.1, pedir o preparar un bundle con una versión con soporte (8.3+) | **Alto solo si el runtime instalado es 8.1** | a.1 |

### Preguntas abiertas

1. **Licencia:** ¿aceptamos mantener visible el crédito de Vateron Media? Según el README,
   así los LB son gratis y no hay contacto externo, pero hay que verificarlo en el
   laboratorio (g.4). ¿Necesitamos white-label? **Según el README**, en ese caso hace falta
   una clave y el panel contacta `xcvm.tech` (no verificado).
2. **Topología:** ¿cuántos LB tendremos? ¿Usaremos la API de clúster o el modo legacy? La API
   de clúster permite que los LB no necesiten acceso remoto a 3306/6379, pero los servicios
   siguen escuchando: hay que cerrarlos con firewall (`cluster:db-allowlist` viene apagado).
3. **Formato de entrega:** ¿nuestras apps usan TS o HLS? Esto decide si llegar a ≤5 s es
   fácil (TS) o requiere cambios (HLS).
4. **Fuentes:** ¿de qué tipo son los canales on demand (HTTP-TS, HLS, UDP)? ¿Cuál es su GOP?
   ¿Se pueden servir en *copy* (remuxer nativo) sin transcodificar?
5. **Conexiones:** ¿queremos "rechazar al nuevo con video", o basta con
   `disallow_2nd_ip_con` (solo en live)? ¿Cómo tratamos a varios aparatos detrás de la misma
   IP (NAT)?
6. **Apps:** ¿cuáles son? ¿Leen `info.audio`, `info.video` y `subtitles` de
   `get_vod_info`? ¿Qué nombre de campo esperan para los idiomas de audio?
7. **Migración:** ¿podemos conseguir un dump de XUI.one **anonimizado** para ensayar? ¿Qué
   versión de XUI.one usamos?
8. **Código sin fuente pública:** ¿aceptamos depender de `xcvm_core.so`? (`xc_fanout` y
   `xc_agent` sí tienen el código publicado en `XC_VM_Fanout`). ¿Hay
   que pedirle a upstream su código o una alternativa (con tu autorización)?
9. **Upstream (requiere tu autorización, según `AGENTS.md`):** ¿proponemos a Vateron-Media
   los cambios genéricos (pistas de audio, modo de límite) para no cargar con parches
   propios? ¿Reportamos en privado los hallazgos de seguridad de g.3?
10. **Pruebas:** ¿qué medición de arranque tomamos como referencia? Por ejemplo, el tiempo
    hasta el primer frame en la app, no hasta el primer byte.

### Tabla de hallazgos revisada (severidades)

Revisada el 2026-10-11 tras la revisión independiente de ChatGPT. Las dos primeras filas
son **errores de redacción del propio estudio, ya corregidos**; el resto son **riesgos
vigentes** del sistema. Es una **selección**: la lista completa de riesgos es la tabla
R1–R11 de arriba (R6, R8, R9 y R10 no se repiten aquí).

| Severidad | Hallazgo | Estado | Sección |
|---|---|---|---|
| **ALTA** (error del estudio) | `xc_fanout` / `xc_agent` descritos como binarios cerrados o "no determinables" | **Corregido:** su código Go es público (AGPL) en `XC_VM_Fanout`; entre los componentes revisados, lo único sin código fuente público localizado es `xcvm_core.so` (+ ionCube, de terceros) | a.1, b, g.3, h.2, R2 |
| **ALTA** (error del estudio) | Licencia descrita como certeza cuando el propio proyecto se contradice y la decisión está en `xcvm_core.so` | **Corregido:** queda como [no determinable desde el código PHP público], con una prueba de laboratorio | g.4, R3 |
| MEDIA | Exposición potencial de MariaDB/KeyDB: escuchan en todas las interfaces y el instalador no gestiona ningún firewall | Riesgo vigente: firewall antes de instalar | g.2, g.3, R1 |
| MEDIA | Política "gana el nuevo" (permite compartir cuentas por turnos y no muestra el video al nuevo) | Riesgo de negocio | c.2, R5 |
| MEDIA | Condición de carrera si se implementa `reject_new` sin una operación atómica | Recomendación de diseño: admisión atómica | c.5 |
| MEDIA | Dependencia de `xcvm_core.so` (BD, Redis, grants, licencias, tokens del clúster) | Riesgo vigente | g.3, R2 |
| MEDIA | Cadena de suministro: binarios compilados (`xcvm_core.so`, bundle PHP/nginx, ffmpeg, `xc_fanout`/`xc_agent`) descargados de `main`/`latest` sin firma | Riesgo vigente: fijar versiones y verificar | h.2, R2 |
| MEDIA | Instalador invasivo (`/etc`, `curl\|bash`, `chattr +i`, AppArmor en LB) | Riesgo vigente: snapshot + revisión | h.3, R4 |
| ALTA solo si el runtime instalado es 8.1 (pendiente) | PHP 8.1 sin soporte de seguridad desde el 31-12-2025 | Pendiente: `php -v` en el laboratorio | R11 |
| BAJA/MEDIA | Pérdida de los metadatos de las pistas de audio adicionales (en el parser, no en ffprobe) | Confirmado | d.2, R7 |
| BAJA/MEDIA | Discrepancias documentales del proyecto (licencia; README frente a guías) | Documentado | g.4 |

### Próximos pasos propuestos (requieren aprobación)

1. Preparar el laboratorio: snapshot y firewall (solo 22/80/443 desde nuestra red).
2. Revisar `install` línea por línea, fijar versiones y decidir si se ejecuta tal cual o con
   ajustes.
3. Instalar en el laboratorio y medir el arranque on demand (TS/HLS, con y sin `llod`,
   variando `seg_time` y `read_native`).
4. Probar `tools/test_player_api.sh` y nuestras apps contra el laboratorio.
5. Diseñar el módulo `crplay_media` (idiomas) y el ajuste `connection_limit_mode` (límite),
   cada uno en su propia rama y su propio PR.

---

## j) Lecciones para el diseño del control plane de CRPLAY

Resumen de lo más útil de este estudio para el panel propio de CRPLAY. **Son ideas, no
código:** no se copia nada de XC_VM.

### j.1 Separar el plano de control del plano de video

El sistema de CRPLAY no debería depender de internals de XC_VM.

- **Plano de control CRPLAY:** API Xtream, autenticación, usuarios, paquetes, límite de
  conexiones, sesiones, dispositivos, idiomas/metadatos, CRM, enrutamiento y observabilidad.
- **Plano de video intercambiable:** Flussonic, XC_VM, nginx + FFmpeg, MediaMTX, u otro
  motor futuro.

### j.2 No modelar "una conexión = un cambio de canal"

Para que el zapping no cuente doble, separar dos conceptos:
- **sesión de dispositivo**;
- **reproducción activa**.

Una sesión de dispositivo puede cambiar de canal sin consumir dos slots a la vez si ese
dispositivo ya tenía uno reservado. XC_VM se acerca en HLS con una clave fija por
línea+canal+IP+UA (c.4), pero sigue siendo por canal.

### j.3 Servicio de admisión de conexiones

Central y **atómico** (ver c.5). Estados sugeridos: `RESERVED` → `ACTIVE` → `CLOSING` →
`EXPIRED`, con TTL y *heartbeats*.

### j.4 Redis para el estado efímero; SQL para el histórico

Opción recomendada: no usar una tabla SQL tipo `lines_live`/`lines_activity` como único
estado operativo de las conexiones en tiempo real.
- **Redis:** sesiones activas, slots, *heartbeats*, locks, TTL.
- **SQL:** historial, auditoría, analítica.

SQL también puede hacer una admisión transaccional; Redis se recomienda por latencia y TTL
nativos, siempre que se definan su consistencia (persistencia, réplica) y cómo se recupera
tras un fallo.

### j.5 Fail-open o fail-closed

Definir desde el principio qué pasa si:
- cae Redis;
- el main no responde;
- el motor de video no responde;
- un LB pierde la comunicación.

Para un límite estricto de conexiones, el servicio de admisión probablemente debe ser
**fail-closed** para no acabar con cientos de conexiones sin contar. Unos *leases* locales
"muy acotados" no bastan por sí solos para un límite global estricto: harían falta cupos
globales preasignados por nodo, con garantías de que no se dupliquen. XC_VM ofrece varias políticas, por ejemplo `lb_offline_admission`
(`docs/adr/0004-cluster-api.md:1102`, `:1118-1122`).

### j.6 La API Xtream como contrato

No copiar los internals de XC_VM. Tratar `player_api.php`, `get.php`, `xmltv.php`, `/live`,
`/movie` y `/series` como un **contrato externo**, y crear pruebas de compatibilidad contra
las apps reales de CRPLAY.
