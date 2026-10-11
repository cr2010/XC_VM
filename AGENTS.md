# Reglas del fork CRPLAY TV / NETPLAY (cr2010/XC_VM)

Este archivo lo leen **Codex** (directamente) y **Claude Code** (importado desde `CLAUDE.md`).
Estas reglas **tienen prioridad** sobre lo que diga `CLAUDE.md` de upstream cuando ambos
choquen (por ejemplo, la regla de upstream "never git push" o "issues en Vateron-Media/XC_VM").

Contexto: `cr2010/XC_VM` es un fork **público** (AGPL-3.0) de `Vateron-Media/XC_VM`.
El servidor de trabajo es un **laboratorio aislado** (Ubuntu 24.04, sin datos reales).

## Equipo y roles

| Quién | Rol |
|---|---|
| Claude Code | Programa y documenta. Crea ramas, commits y PRs. |
| Codex (OpenAI) | **Revisor independiente, solo lectura.** Se invoca con `codex exec --sandbox read-only "..."`. No edita archivos, no hace commit, push ni merge. |
| Gilberth | Aprueba y fusiona los PRs. |
| ChatGPT | Revisión final opcional del PR. |

### Flujo de cada cambio

1. Claude Code trabaja en una rama nueva (nunca en `main`).
2. Antes de abrir el PR, Claude Code pide la revisión a Codex en modo solo lectura.
   Codex reporta hallazgos con severidad **ALTA / MEDIA / BAJA**, archivo y línea.
3. Claude Code corrige lo válido y explica por qué rechaza lo que no comparte.
   Se repite hasta que Codex **no tenga hallazgos ALTOS**.
4. Claude Code abre el PR hacia `cr2010/XC_VM` con un resumen de la revisión de Codex
   y de lo corregido.
5. Gilberth revisa (opcionalmente con ChatGPT) y hace el merge.

## Reglas

1. **Nada sensible en el repo.** Nunca subir claves, tokens, contraseñas, IPs reales,
   dominios de producción ni datos de clientes. El repo es público. Usar valores de
   ejemplo (`203.0.113.x`, `example.com`, `usuario_demo`).
2. **Todo por rama + PR hacia `cr2010/XC_VM`.** Nunca abrir PRs, issues ni pushes hacia
   `Vateron-Media/XC_VM` (remote `upstream`, solo lectura). Con `gh`, indicar siempre
   `--repo cr2010/XC_VM` (en un fork, `gh` apunta por defecto al repo original).
   **Nadie hace merge salvo Gilberth.**
3. **Codex solo revisa:** siempre `--sandbox read-only`. No edita, no hace commit,
   push ni merge.
4. **Fork sincronizable con upstream.** Cambios lo más separados posible del código
   original: preferir archivos nuevos, módulos (`src/Modules/`), eventos `#[ListensTo]`
   y documentación aparte (`docs/ESTUDIO_CRPLAY.md`, `docs/crplay/`) antes que editar
   archivos de upstream. Si hay que tocar código de upstream, hacerlo en el menor número
   de líneas posible y documentarlo en el PR.
5. **No instalar nada en el sistema** (ni XC_VM, ni paquetes apt/pip/composer, ni
   servicios, ni cambios en `/etc`) sin aprobación explícita de Gilberth.
6. **Idioma:** escribir en español, claro y paso a paso (docs, PRs, revisiones y
   explicaciones). Los mensajes de commit siguen Conventional Commits.

## Remotes

- `origin`   → `https://github.com/cr2010/XC_VM.git` (aquí van las ramas y los PRs)
- `upstream` → `https://github.com/Vateron-Media/XC_VM.git` (solo `git fetch`)
