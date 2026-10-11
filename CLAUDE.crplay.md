# Reglas del fork CRPLAY TV / NETPLAY (cr2010/XC_VM)

Este archivo se importa desde `CLAUDE.md` y **tiene prioridad** sobre lo que diga
`CLAUDE.md` de upstream cuando ambos choquen (por ejemplo, la regla de upstream
"never git push" o "issues en Vateron-Media/XC_VM").

Contexto: `cr2010/XC_VM` es un fork **público** (AGPL-3.0) de `Vateron-Media/XC_VM`.
El servidor de trabajo es un **laboratorio aislado** (Ubuntu 24.04, sin datos reales).

## Reglas

1. **Nada sensible en el repo.** Nunca subir claves, tokens, contraseñas, IPs reales,
   dominios de producción ni datos de clientes. El repo es público. Usar valores de
   ejemplo (`203.0.113.x`, `example.com`, `usuario_demo`).
2. **Todo por rama + PR hacia `cr2010/XC_VM`.** Nunca abrir PRs, issues ni pushes hacia
   `Vateron-Media/XC_VM` (remote `upstream`, solo lectura). **Nunca hacer merge**: lo hace
   Gilberth después de que ChatGPT revise el PR.
3. **Fork sincronizable con upstream.** Cambios lo más separados posible del código
   original: preferir archivos nuevos, módulos (`src/Modules/`), eventos `#[ListensTo]`
   y documentación aparte (`docs/ESTUDIO_CRPLAY.md`, `docs/crplay/`) antes que editar
   archivos de upstream. Si hay que tocar código de upstream, hacerlo en el menor número
   de líneas posible y documentarlo en el PR.
4. **No instalar nada en el sistema** (ni XC_VM, ni paquetes apt/pip/composer, ni
   servicios, ni cambios en `/etc`) sin aprobación explícita de Gilberth.
5. **Idioma:** escribir en español, claro y paso a paso (docs, PRs y explicaciones).
   Los mensajes de commit siguen Conventional Commits.

## Remotes

- `origin`   → `https://github.com/cr2010/XC_VM.git` (aquí van las ramas y los PRs)
- `upstream` → `https://github.com/Vateron-Media/XC_VM.git` (solo `git fetch`)
