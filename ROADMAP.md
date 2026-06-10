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

- [x] **Disclaimer de no afiliación** — HECHO: nota ámbar bajo «Cómo funciona» en la
      portada (es/en) y sección Disclaimer en el README.
- [ ] **Dominio propio** — `kobomanager.org` y `.com` comprobados LIBRES (jun-2026, whois).
      Registrar al menos el .org antes de difundir el repo para que README/landing apunten
      a algo estable.
- [ ] **Demo pública** (p. ej. `demo.kobomanager.org`) — plan completo en
      `my.docs/PUBLICDEMO.md` (privado).
  - [x] **Modo demo integrado en la app — HECHO**: `DEMO_MODE` + `DEMO_RESET_MINUTES` +
        `DEMO_LOGIN_HINT` en config (opcionales, retrocompatibles), modo expuesto por
        `/config` (modal de bienvenida en cada carga de la portada + badge «DEMO»
        clickeable junto a la marca que lo reabre), error `DEMO_LOCKED` 403 centralizado
        en el router para las acciones que romperían la demo o filtrarían el token
        (cuentas Kobo, usuarios/contraseñas/sesiones, settings, edición de envíos y sync
        manual); el resto —revisión, filtros, export, shares, stats, mapa— queda abierto.
        Botones deshabilitados con aviso.
        Documentado en `DEPLOY.md` §13 «Running a demo instance» (config, seed, cron de
        reset, hardening) para que cualquiera monte su propia demo.
  - [ ] **Instancia**: VPS + dominio + cuenta Kobo desechable con datos 100 % sintéticos
        + usuarios/permisos/share de ejemplo + dump semilla + cron de reset.
- [ ] **Release «deploy-ready» en GitHub** *(idea del QA de instalación, jun-2026)*: zip
      adjunto a cada release con EXACTAMENTE lo que se sube al servidor — contenido de
      `dist/` (incluido el `.htaccess` raíz) + `api/` podado (sin vendor/tests/composer/
      phpunit) + `db/` — de modo que instalar sea: descomprimir en el webroot, crear
      `api/config.php` desde el example, aplicar `db/*.sql` y (Apache) listo. Implementar
      como job de GitHub Actions al pushear un tag (build + zip + attach al release);
      hacerlo DESPUÉS del QA de la instalación manual, para que el zip encode el layout
      ya verificado. DEPLOY §3 ofrecería entonces dos vías: release zip o build propio.

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

- [ ] **Instalador CLI (`php api/cli/install.php`)** *(idea surgida en el QA de la
      instalación limpia, jun-2026; PRIORIDAD CONFIRMADA por el usuario durante la
      instalación real: crear el primer admin por CLI es la fricción más clara para
      perfiles menos técnicos)*: con `config.php` ya rellenado, un único comando que
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
