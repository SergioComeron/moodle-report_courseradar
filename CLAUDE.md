# CLAUDE.md — report_courseradar

Plugin de informe Moodle 4.5 que muestra al profesor métricas de interacción de los alumnos con los recursos del curso.

**GitHub:** https://github.com/SergioComeron/moodle-report_courseradar  
**Versión actual:** ver `.release-please-manifest.json`  
**Ruta local:** `/Users/sergiocomeron/moodles/stable_405/moodle/report/courseradar/`  
**Moodle root:** `/Users/sergiocomeron/moodles/stable_405/moodle/`

---

## Estructura de ficheros

```
index.php          — Página principal. PHP puro hasta // phpcs:disable, luego HTML.
locallib.php       — Funciones testeables (barclass, get_students, atrisk, etc.)
lib.php            — Hook de navegación (añade enlace en Reports del curso)
db/access.php      — Capability report/courseradar:view
version.php        — $plugin->version, requires, maturity
lang/en/           — Strings EN (orden alfabético estricto)
lang/es/           — Strings ES (orden alfabético estricto)
tests/lib_test.php — 25 tests PHPUnit
.github/workflows/ci.yml      — CI multimatriz PHP×DB
.github/workflows/release.yml — release-please + ZIP
release-please-config.json    — Configuración de release-please
.githooks/pre-push — Ejecuta PHPUnit antes de push (ramas != main)
Makefile           — make setup (activa hooks), make test (PHPUnit)
```

---

## Comandos esenciales

```bash
# Tests (ejecutar siempre antes de push)
cd /Users/sergiocomeron/moodles/stable_405/moodle
vendor/bin/phpunit --testsuite report_courseradar_testsuite

# Codechecker local
php local/codechecker/run.php report/courseradar/index.php
php local/codechecker/run.php report/courseradar/lang
php local/codechecker/run.php report/courseradar/locallib.php

# Purgar cachés (necesario al añadir strings nuevos)
php admin/cli/purge_caches.php
```

---

## Flujo de desarrollo

1. `git checkout -b feat/nombre dev`
2. Desarrollar → codechecker → PHPUnit (25 tests deben pasar)
3. `git push origin feat/nombre` (el pre-push hook lanza PHPUnit automáticamente)
4. Probar en Moodle local. Purgar cachés si hay strings nuevos.
5. `git checkout dev && git merge feat/nombre && git push origin dev`
6. Validar en entorno de pruebas.
7. `git checkout main && git merge dev && git push origin main` → dispara CI y release-please.

---

## Commits — formato obligatorio

Usar **siempre** conventional commits. release-please los analiza para versión y CHANGELOG:

| Prefijo | Efecto en versión | Aparece en CHANGELOG |
|---------|-------------------|----------------------|
| `feat:` | bump minor | Sí — Features |
| `fix:` | bump patch | Sí — Bug Fixes |
| `perf:` | bump patch | Sí — Performance |
| `docs:` | bump patch | Sí — Documentation |
| `refactor:` | bump patch | Sí — Code Refactor |
| `ci:` | — | No |
| `chore:` | — | No |
| `feat!:` | bump MAJOR | Sí — Breaking Changes |

**Nunca** añadir `Co-Authored-By:` de Claude en los commits.

---

## Convenciones de código

### PHP (index.php)
- Todo el código PHP debe pasar `codechecker --max-warnings 0` antes del `// phpcs:disable`.
- La sección HTML empieza con `// phpcs:disable` justo antes del `?>`.
- Usar `array_multisort` en vez de `uasort` con closures a nivel global (evita cascade MissingDocblock).
- Queries SQL siempre con named params (`SQL_PARAMS_NAMED`), nunca interpolación directa.
- Los datos de alumnos se obtienen con `report_courseradar_get_students($context)` — excluye usuarios con capability `report/courseradar:view`.

### Lang files
- Strings en **orden alfabético estricto** en ambos ficheros (EN y ES).
- El CI usa `moodle-cs` que es más estricto que el checker local — verificar siempre ambos ficheros al añadir strings.
- Nombre de strings: minúsculas, sin guiones, `_` permitido. Descriptions: sufijo `_desc`.

### JavaScript (inline en index.php)
- Funciones globales con prefijo `cr`: `crToggle`, `crSortResources`, `crApplyFilters`, etc.
- Filtros de recursos centralizados en `crApplyFilters()` — combina filtro por tipo y actividades ocultas.
- Tooltips Bootstrap inicializados en `DOMContentLoaded` junto con `crApplyFilters()`.
- No usar `data-bs-toggle="collapse"` en `<tr>` — usar `crToggle(btn, rowId)` con `style.display`.

### CSS (inline en index.php)
- Clases con prefijo `cr-`: `cr-card`, `cr-resource-row`, `cr-section-row`, etc.
- `gap-N` en flex no siempre funciona con el tema Boost — usar `me-2 mb-1` en los elementos.
- Usar `table-light border-bottom border-2` para cabeceras de tabla (no `table-dark` — el hover es intrusivo).
- Badges dentro de headings `text-danger`: añadir `text-white` explícito para evitar cascade.

---

## Arquitectura de datos

Toda la interacción se lee de `logstore_standard_log`:
- **Módulos:** `action='viewed'`, `contextlevel=CONTEXT_MODULE`, filtrado por `userid IN (student_ids)`
- **Curso:** `action='viewed'`, `target='course'`, misma tabla

Queries principales en `index.php`:
| Variable | Contenido |
|----------|-----------|
| `$logdata` | `[cmid => {totalviews, uniqueusers, lastaccess}]` |
| `$bycm` | `[cmid][uid] => {views, lastaccess}` |
| `$studentlog` | `[uid][cmid] => views` |
| `$byday` | `['Y-m-d' => count]` |
| `$heatmap` | `[dow][timeblock] => count` |
| `$weekdata` | `[uid][weekts] => count` |
| `$coursevisits` | `[uid => visits]` |
| `$bytype` | `[modname => {modules, views}]` |
| `$topunseen` | Array de CMs menos visitados |
| `$sparklines` | `[uid => [{cnt, height, label}]]` |
| `$daysinactive` | `[uid => days]` |

---

## CI/CD

### ci.yml
- Triggers: push a `main` y `dev`
- Matriz: PHP 8.1, 8.2, 8.3 × pgsql, mariadb
- Pasos: phplint → phpcpd → **codechecker `--max-warnings 0`** → phpdoc → validate → savepoints → phpunit

### release.yml
- Trigger: push a `main`
- Job 1: release-please crea/actualiza PR de release + auto-merge con `gh pr merge --repo "$GITHUB_REPOSITORY" --merge`
- Job 2 (al crear release): checkout en `path: courseradar`, actualiza `$plugin->version` a fecha de hoy, genera ZIP excluyendo ficheros dev, adjunta a GitHub Release.

---

## Pitfalls conocidos

1. **Lang ordering en CI**: El CI (`moodle-cs`) es más estricto que el local. Verificar siempre el orden en AMBOS lang files al añadir strings. Comparar con `strcmp` para orden correcto.

2. **PHPUnit init**: Si cambia la versión de Moodle, ejecutar `php admin/tool/phpunit/cli/init.php` antes de correr los tests.

3. **Bootstrap collapse en `<tr>`**: No funciona (`display:block` vs `display:table-row`). Usar la función JS `crToggle`.

4. **`gh pr merge` sin checkout**: Necesita `--repo "$GITHUB_REPOSITORY"` explícito, si no falla con "not a git repository".

5. **Purgar cachés**: Después de añadir strings nuevos al lang, purgar cachés de Moodle y recargar con Shift+clic en Safari (Cmd+Shift+R abre el Reader en Safari).
