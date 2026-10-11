# `crplay/` — código propio del fork CRPLAY TV / NETPLAY

Todo lo que el fork añade a XC_VM y que **no** es código de upstream vive aquí. Así el fork
se sincroniza con `Vateron-Media/XC_VM` sin conflictos.

| Carpeta | Qué hay |
|---|---|
| `modules/` | Módulos de XC_VM. Se instalan subiendo un ZIP en **Modules** (ver el README de cada uno) |
| `tests/` | Pruebas sin base de datos: `php crplay/tests/crplay_media_test.php` (desde la raíz del repo; necesita `php-cli`) |

Módulos:

- [`crplay-media`](modules/crplay-media/README.md): idiomas de audio y subtítulos en
  `get_vod_info` / `get_series_info`.
