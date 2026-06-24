# Roadmap — KoboManager

Estado vivo de lo que falta por hacer e ideas para más adelante. Lo ya entregado se
registra en [`CHANGELOG.md`](./CHANGELOG.md).

> Todas las fases del plan inicial (0–7), los hitos hacia la primera versión pública
> (**M1–M5** + **P1–P4**), los hitos nº1–nº2 del roadmap 1.x (permisos por columna y
> scoping multi-condición), el bloque de portada/landing y las **prioridades acordadas
> de jun-2026** (reorganización i18n, bandeja de mensajes de contacto, modo oscuro +
> skeletons, columnas de solo lectura y filtros avanzados) están **entregados** — ver
> `CHANGELOG.md`. Este documento recoge solo lo pendiente.

---

## Frentes mayores (supeditados a demanda real)

> **Decisión (jun-2026):** estos dos frentes **no se abordan ahora**; quedan supeditados
> a **demanda real una vez el repositorio sea público**. Prioridad actual = reforzar y
> pulir lo ya entregado.

- [ ] **Cadena de aprobación multi-nivel por roles** *(fase futura del flujo de revisión)*.
      Flujo por **etapas ordenadas**, cada una a cargo de un rol distinto
      (p. ej. solicitante → revisor → aprobador), de modo que un envío solo avanza cuando la
      etapa anterior lo despacha. Añade sobre lo ya entregado: definición de la cadena
      (¿global o por formulario?), **gating por rol/capacidad por etapa** (hoy solo hay un
      `can_validate` booleano por formulario), **posición actual** del envío + **transiciones
      válidas** comprobadas en backend (un revisor no puede aprobar del todo), qué hace el
      **rechazo** (terminal vs rebote a etapa anterior/al remitente), **colas** «lo que espera
      por mí» y notificaciones opcionales. Se apoya en `submission_reviews` (historia) y el
      audit log. Esfuerzo **mayor** (toca el modelo de permisos + máquina de estados + UI
      nueva); acotar v1 (cadena lineal por formulario, rechazo = rebote, sin notificaciones).
      Pedido en el foro y **no soportado por Kobo**, el propio staff lo admite
      ([approval-workflow/25499](https://community.kobotoolbox.org/t/approval-workflow-using-kobo-post-submission/25499)).
- [ ] **Dashboards / paneles compartibles** *(mayor esfuerzo; versión futura)*. Dar el salto
      de la página fija de **Estadísticas** (un informe predefinido por formulario) a **paneles
      configurables y publicables**:
      - **Configurable**: el usuario elige qué indicadores/gráficos ve y cómo (qué preguntas,
        qué agregación, qué filtros de fila), montando su propio panel con *widgets* en vez de
        la vista fija actual.
      - **Multi-fuente**: combinar varios formularios/indicadores en una sola vista (KPIs de la
        organización, no de un único formulario).
      - **Compartible/embebible**: igual que los enlaces de solo lectura de envíos, pero para un
        *panel agregado* — un enlace público con contraseña/caducidad/revocación que muestra
        solo gráficos, **sin exponer envíos individuales ni el token de Kobo**. Útil para un
        donante o una web institucional.

      Encaja con la arquitectura (reutiliza `forms/stats.php`, `ShareLink`/`RowScope`/`FieldScope`,
      Chart.js y la vista pública sin shell), pero es el **mayor esfuerzo de UI** del roadmap
      (editor de paneles + persistencia de configuraciones + render público) → su propio hito,
      con modelo acordado primero. Demanda recurrente
      ([open-source-online-dashboards/17702](https://community.kobotoolbox.org/t/open-source-online-dashboards/17702)).

      > **Avance parcial (jun-2026, entregado):** los enlaces públicos ya pueden exponer
      > el **panel de Estadísticas** (`expose_stats`), con scoping de fila/columna y **sin**
      > el estado de revisión interno; un enlace con *solo* estadísticas muestra gráficos
      > sin exponer envíos individuales — cubre la viñeta «compartible/embebible» para un
      > **único formulario** con el informe **fijo**. Queda pendiente lo grande: panel
      > **configurable** (elegir indicadores/widgets) y **multi-fuente** (varios formularios).

### Decisión de diseño: flujo de revisión simple

Se conservan los **4 estados fijos** (pendiente / en espera / aprobado / rechazado) y la
validación **plana por formulario** (`can_validate`). Se exploraron y **descartaron** por
baja utilidad / posible confusión para el caso actual: el **estado inicial automático**
([54994](https://community.kobotoolbox.org/t/can-we-set-an-on-hold-validation-automatically-when-users-submit-data/54994))
y los **estados de validación personalizables**
([15808](https://community.kobotoolbox.org/t/customizing-validation-statuses/15808)).
Quedan como ideas reabribles si aparece una necesidad real.

---

## Publicación (en torno a hacer público el repo)

> **Estado (jun-2026): el repositorio YA es PÚBLICO** y la demo está viva en
> `kobomanager.org` (cron de reset verificado). Lo que queda abajo es pulido
> posterior a la publicación.

- [x] **Disclaimer de no afiliación** — HECHO: nota ámbar bajo «Cómo funciona» en la
      portada (es/en) y sección Disclaimer en el README.
- [x] **Dominio propio** — HECHO: `kobomanager.org` registrado; la portada de la app
      (que es a la vez la presentación del proyecto) vive ahí.
- [x] **Política de seguridad** — HECHO: `SECURITY.md` (divulgación responsable,
      GitHub Private Reporting + email de respaldo) + CSP/cabeceras de la SPA.
- [~] **Demo pública** — VIVA en el apex `kobomanager.org`. Plan completo en
      `my.docs/PUBLICDEMO.md` (privado).
  - [x] **Modo demo integrado en la app — HECHO**: `DEMO_MODE` + `DEMO_RESET_MINUTES` +
        `DEMO_LOGIN_HINT` en config (opcionales, retrocompatibles), modo expuesto por
        `/config` (modal de bienvenida en cada carga de la portada + badge «DEMO»
        clickeable junto a la marca que lo reabre), error `DEMO_LOCKED` 403 centralizado
        en el router para las acciones que romperían la demo o filtrarían el token
        (cuentas Kobo, usuarios/contraseñas/sesiones, settings, edición de envíos y sync
        manual); el resto —revisión, filtros, export, shares, stats, mapa— queda abierto.
        Botones deshabilitados con aviso.
        Documentado en [`DEMO.md`](DEMO.md) (runbook completo: config, usuarios, seed,
        cron de reset, hardening) para que cualquiera monte su propia demo.
  - [x] **Sembrado de datos sintéticos — HECHO**: CLI `api/cli/seed_demo.php` que lee el
        esquema cacheado del formulario y genera envíos FALSOS directamente en
        `submissions_cache` (no escribe en Kobo) con fechas repartidas, opciones válidas,
        geopoints, campos vacíos y revisiones de ejemplo (marca `_km_seed` para `--clear`).
        Decisión clave: una demo sembrada NO lleva cron de sync (lo reconciliaría y
        borraría), solo cron de reset. Documentado en [`DEMO.md`](DEMO.md).
  - [x] **Instancia**: HECHO — VPS + `kobomanager.org` + cuenta Kobo desechable con
        datos sintéticos + usuarios/permisos/share de ejemplo + dump semilla + cron de
        reset (verificado).
- [ ] **Semilla y reset de la demo gestionados por la app** *(idea del usuario en el QA,
      jun-2026)*: hoy la demo exige `mysqldump` + cron SQL a mano (DEMO.md). En su
      lugar: botón admin «Generar semilla de la demo» (exporta la BD a una ruta
      configurada, p. ej. `DEMO_SEED_PATH`; disponible solo con `DEMO_MODE` apagado,
      coherente con el bucle de mantenimiento) + cron `api/cron/demo_reset.php` (como
      los de sync) que restaura esa semilla cada `DEMO_RESET_MINUTES`. Retos: dump y
      restore desde PHP sin `mysqldump` (multi-statement, FK checks, tamaño) y restaurar
      «en caliente» con visitantes activos. Eliminaría todo el SQL manual de DEMO.md.
- [x] **Release «deploy-ready» — HECHO** *(idea del QA de instalación, jun-2026)*: el zip
      con EXACTAMENTE lo que se sube al servidor — contenido de `dist/` (incluido el
      `.htaccess` raíz) + `api/` podado (sin vendor/tests/composer/phpunit y sin el
      `config.php` con secretos) + `db/` — lo genera el **script local `npm run package`**
      (`scripts/package.mjs`, sin dependencias npm). DEPLOY §3 ofrece ya dos vías: release
      zip (opción A) o build propio (opción B). La automatización en CI (workflow que en un
      tag corre el mismo script y adjunta el zip al GitHub Release) queda como paso opcional
      documentado en DEPLOY §3.1 — se activa añadiendo `.github/workflows/release.yml`.

---

## Ideas reabribles (post-publicación)

- [ ] **«Organizaciones que usan KoboManager»** — acápite/escaparate en la landing o en
      `/apoyar` con las organizaciones que lo usan (con su permiso). Para cuando haya varias.

---

## Optimización y UX

- [ ] **Agregación semanal explícita en Estadísticas** *(pendiente menor)* — hoy «Envíos por
      día/mes» elige día↔mes automáticamente según el tramo; valorar el escalón intermedio
      por semana.
- [ ] **Carga diferida del catálogo i18n de la Guía** — cargar `guide.json` bajo demanda por
      ruta (vue-i18n `setLocaleMessage` + import dinámico). Solo merece la pena con un
      3.er idioma o si la Guía crece a documentación larga; adaptar entonces el check de
      paridad.

---

## Operación y mantenimiento

- [x] **Instalador CLI (`php api/cli/install.php`) — HECHO** *(idea del QA de la
      instalación limpia, jun-2026; implementado en la misma ronda)*. Verifica
      requisitos (PHP/extensiones/claves/BD), aplica `db/*.sql` si la BD está vacía
      (aborta con esquema parcial), crea el primer admin (interactivo o `--admin`),
      idempotente, y `--clean` borra `db/` (negándose en un checkout con `.git`).
      DEPLOY §4 lo ofrece como vía principal. Diseño original: con `config.php` ya rellenado, un único comando que
      (1) verifique requisitos (PHP 8.1+, sodium, pdo_mysql, conexión a la BD, claves no
      placeholder), (2) detecte si la BD ya está instalada (tablas presentes) y si no
      aplique `db/*.sql` en orden, (3) cree el primer usuario admin de forma interactiva
      (hoy `cli/create_user.php` aparte), y (4) al terminar SUGIERA borrar `db/` del
      servidor (borrado opcional con flag `--clean`, nunca automático: en la vía SSH la
      carpeta ni siquiera está, y un script que borra archivos por su cuenta sorprende).
      CLI primero. Variante **web** estilo WordPress (idea del usuario) como posible capa
      posterior, solo con TODAS las mitigaciones: se niega a correr si ya hay instalación
      (tabla users no vacía / `install.lock` presente), se autodeshabilita al terminar e
      instruye borrar el fichero del servidor. Aun así el beneficio es limitado: lo duro
      (dominio, vhost, HTTPS, subir archivos, crear BD+usuario MySQL, config.php) exige
      igualmente acceso al servidor, que es lo único que el CLI necesita. Cualquiera de
      las dos reduciría los §§4 y 5 de DEPLOY a «rellena config.php y ejecuta el
      instalador».

- [ ] **Transporte de correo alternativo (SMTP)**: hoy el envío es solo vía Resend (API
      HTTP, `lib/Mailer.php`). Ofrecer SMTP para quien prefiera su propio servidor — choca
      con la filosofía «sin dependencias»; valorar abstraer un `MailTransport` con
      back-ends `resend`|`smtp`.

---

## Ampliaciones futuras (del plan original)

- [ ] **Versión de escritorio** con Tauri (envuelve el mismo frontend Vue).
- [ ] **Webhooks de Kobo** para sincronización en cuasi-tiempo-real (en vez de cron cada 15 min).
- [ ] **Notificaciones por otros canales** (Telegram, Slack, WhatsApp).
- [ ] **Permiso `can_delete`** cuando exista la funcionalidad de borrado de envíos.
- [ ] **Permisos por período de tiempo** (acceso a envíos de un rango de fechas).
- [ ] **2FA** — la tabla `user_sessions` ya está preparada para soportarlo.
- [ ] **Exportación XLSX nativa** (diferida por la filosofía «sin dependencias»; hoy hay CSV).

---

*Cuando una tarea se complete, muévela a `CHANGELOG.md` con su fecha.*
