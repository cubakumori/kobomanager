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

## Control de calidad por equipo/encuestador — extensiones diferidas

> El hito base está **entregado en 1.14.0 y pulido hasta 1.19.0** (ver CHANGELOG):
> umbrales por formulario (duración mín/máx y consecutividad mínima), página
> `forms/<id>/quality` con las cuatro banderas (corta, larga, hueco corto, solapada) y
> drill-down, el botón «Marcar en espera las N no admitidas» sobre el flujo de revisión
> en lote existente, alcance por estado de revisión configurable (`qc_scope`), tasa de
> no admitidas con denominador único (n / total recibido) y formato de porcentajes
> configurable (`pct_format`, aplicado también a Estadísticas). En **1.26.0** se añadió su
> contrapartida, **«Aprobar las N admisibles pendientes»** (los pendientes sin ninguna
> bandera): ajuste global `qc_admit_batch` (tabla | QC | ambos | ninguno) que gobierna solo
> ese atajo, filtro «solo admisibles» en la tabla, guardarraíles server-side (solo
> pendientes; exclusión de encuestadores de alto riesgo con el Índice de riesgo activo), y
> banderas **derivadas, no persistidas** (`Quality::admissiblePendingUids`, fuente de verdad
> única para tabla y QC — ver la nota del «marcado on-hold automático» abajo). En
> **1.27.0**: **toggle transitorio del alcance** en la propia página (y su export):
> `?scope=all|pending_hold` sustituye el ajuste global `qc_scope` solo para esa petición.
> Quedan estas extensiones, para cuando haya demanda:

- [ ] **Marcado on-hold totalmente automático al sincronizar** *(el checkbox original)*:
      exige atribución nueva en `submission_reviews` (p. ej. `source='auto'`, cambio de
      ENUM → SchemaCheck + nota de upgrade) y decidir si empuja a Kobo o es solo local,
      y si re-evalúa retroactivamente al cambiar los umbrales.
      *Nota de diseño (jul-2026):* el atajo «aprobar admisibles» (1.26.0) mantiene las
      banderas **derivadas, no persistidas** a propósito — los umbrales son editables y
      señales como duplicados/«GPS clavado» son relativas al cohorte (un sync nuevo puede
      voltear la bandera de otro envío), así que persistirlas sería una vista materializada
      con recálculo en cada sync + edición de umbrales. Este hito (histórico por naturaleza,
      `source='auto'`) es el único donde persistir empezaría a valer la pena.
- [ ] **Horario admisible de trabajo** (franja horaria por formulario, evaluada en
      `APP_TIMEZONE`): encuestas iniciadas de madrugada como bandera propia.
- [ ] **Velocidad imposible entre puntos geo consecutivos** del mismo encuestador
      (distancia/tiempo entre encuestas seguidas) — diseño pesado y delicado (GPS
      ruidoso → falsos positivos). La variante simple, «GPS clavado» (mismo punto
      exacto en ≥3 envíos del mismo encuestador), quedó **entregada en 1.22.0**.
- [ ] **Straight-lining** (misma opción elegida en todas las select_one de un envío):
      señal dudosa con cuestionarios cortos y umbral difícil. La variante fuerte,
      **duplicados exactos de respuestas** (a nivel de formulario, solo contenido),
      quedó **entregada en 1.22.0**.
- [ ] **Comentarios generales / por grupo / por miembro** *(Fase 2 del panel de
      comentarios; la Fase 1 —panel de los comentarios de revisión existentes por
      envío— se entregó en 1.25.0)*: comentarios **desligados de un envío** (una nota
      para todo un equipo o un encuestador). Es un tipo de dato nuevo: tabla
      `form_comments` (`scope_type: general|team|enumerator`, `scope_value`, autor,
      cuerpo, fecha) → SchemaCheck + nota de upgrade, UI de escritura, permisos de
      escritura (¿`can_validate`?) y decisiones de edición/borrado. Hito propio cuando
      haya demanda.
- [ ] **Índice de riesgo — Fase 2 e histórico** *(la Fase 1 —percentmatch, señales
      relativas a pares, índice explicado por encuestador y equipo, opt-in
      `forms.risk_min_n`— se entregó en 1.23.0)*:
      - **Fase 2 (lift mayor):** sincronizar el **`audit` de Kobo** (tiempo por pregunta).
        NO viene en el JSON del envío: es un `audit.csv` **adjunto por envío**, y solo
        existe si el formulario añadió la pregunta tipo `audit` en su XLSForm. Requiere
        descargar y parsear ese attachment por envío (sync más pesado + almacenamiento);
        condicionado a que el formulario lo recoja.
      - **Histórico semanal persistido:** solo aportaría para lo *mutable* (estado de
        revisión y z relativos a pares); lo *físico* (banderas QC, percentmatch, Benford,
        GPS) ya da un histórico fiel calculado en vivo (`by_week[]` de QC). Un subsistema de
        snapshots (tabla + cron + retención + UI de series) es un frente propio; encaja con
        la Fase 2.
- [ ] **Control de calidad en enlaces compartibles** (`expose_quality`): que un
      enlace de solo lectura pueda exponer la página de QC, para que un **líder de
      equipo de encuestación** verifique los errores de su gente sin cuenta en la app.
      Decisión de diseño: es información sensible (señales de fabricación, nombres de
      encuestadores), pero el admin ya tiene mitigaciones —ponerlo o no, con
      contraseña/caducidad, y ofuscar el valor de los campos que identifican personas—,
      así que la responsabilidad es suya. Exige columna `expose_quality` + endpoint
      público (variante de `quality.php` que herede el fila/columna del enlace y
      **ofusque/omita** los identificadores según su config) + vista pública + i18n.
      **Diferido a demanda real** una vez el repo sea público; si alguien lo pide, se
      implementa. *El subconjunto menos sensible ya se entregó en **1.27.0***: el
      **resumen de revisión** por equipo/encuestador en enlaces (`expose_review_summary`,
      opt-in por enlace, solo recuentos agregados por estado) — esta extensión queda
      para las **banderas de calidad** completas (umbrales + drill-down de infracciones).

---

## Notificaciones casi inmediatas de envíos nuevos — HITO ACORDADO (jul-2026)

> Hoy solo existe el **resumen diario por email**. Decisión: algunos usuarios quieren
> enterarse «casi al instante» de un envío nuevo. Clave del diseño: KoboManager solo ve
> envíos nuevos **cuando sincroniza** (cron cada 15 min), así que «inmediato» = «al
> siguiente sync» salvo que se añada el webhook de Kobo (ver Fase 2). Se acordó ejecutar
> **Fase 1 y, a continuación, Fase 2**, todo configurable en Configuración.

- [x] **Fase 1 — frecuencia de email por usuario** *(entregada en 1.29.0)*: la
      preferencia por usuario pasó de binaria («resumen diario sí/no») a **frecuencia**
      por formulario: `off | diario | cada hora | cada sync (~15 min)`
      (`notification_config.frequency` + marca de agua `last_notified_at`; default
      global `notifications_default_frequency`). El cron de sincronización, tras cada
      pasada, envía vía `lib/Notifier` un email **agrupado** («N envíos nuevos en
      {formulario}») a quien lo pidió. Guardarraíles implementados: **scoping por
      filas** en el conteo, **throttle anti-inundación** (máx. un email/hora en
      `hourly`; la marca de agua agrupa lo no avisado), **horario de silencio** global
      opcional (Configuración → Sincronización, en `APP_TIMEZONE`, puede cruzar la
      medianoche) y aviso de solo **recuento + enlace**. El resumen `daily` sigue en su
      cron; las sync manuales o al iniciar sesión no disparan emails.
- [ ] **Fase 2 — Web Push sobre la PWA** *(el «aviso al móvil»; a continuación de la
      Fase 1)*: notificación del sistema vía service worker (la PWA ya existe): opt-in
      por dispositivo desde el perfil, tabla de suscripciones push, claves **VAPID** en
      config, envío desde el cron (misma cadencia y guardarraíles que la Fase 1) con
      `minishlink/web-push` (composer). Funciona con el navegador cerrado en Android;
      en iOS requiere la PWA **instalada** (≥16.4). *Acelerador opcional si alguien
      necesita segundos de latencia*: endpoint webhook para los **REST Services** de
      KoboToolbox (Kobo hace POST por cada envío) con token secreto en la URL +
      mini-upsert en caché — además mantiene la caché fresca sin esperar al cron;
      configuración por formulario en Kobo y endpoint público extra que asegurar.
      *(Idea aparcada, solo si se pide: canal Telegram vía bot por instancia.)*

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
> `kobomanager.org` (modo demo integrado, datos sintéticos vía `api/cli/seed_demo.php`,
> cron de reset verificado; runbook en [`DEMO.md`](DEMO.md)). El disclaimer de no
> afiliación, el dominio propio, `SECURITY.md`, el release «deploy-ready» y el instalador
> CLI también están entregados (ver `CHANGELOG.md`). Lo que queda abajo es pulido posterior.

Sin pendientes en esta sección.

---

## Ideas reabribles (post-publicación)

- [ ] **«Organizaciones que usan KoboManager»** — acápite/escaparate en la landing o en
      `/apoyar` con las organizaciones que lo usan (con su permiso). Para cuando haya varias.
- [ ] **Export/import PARTICULAR de usuarios y permisos entre instancias** *(extensión
      del export/import de la BD entregado en 1.13.0)*: clonar el equipo (usuarios +
      permisos por formulario) a otra
      instancia exige re-mapear ids por claves naturales (usuario→email,
      formulario→`kobo_asset_uid`) — diseño propio, no un recorte del backup general.
      Para cuando haya demanda real.

---

## Optimización y UX

- [ ] **Cabeceras ordenables también en la tabla pública de enlaces compartidos**
      *(extensión de 1.12.0)*: la tabla interna ya ordena por cualquier columna clicando
      la cabecera (`sort=field:<clave>`); la vista pública `/s/<token>` podría heredar el
      mismo mecanismo (respetando el `field_filter` del enlace, como ya hace la interna
      con FieldScope).
- [ ] **Creación de enlaces en lote sobre más tipos de campo** *(extensión de lo ya
      entregado)* — la creación en lote de enlaces de solo lectura (un enlace por valor de
      un campo distintivo) hoy admite solo campos de **opción única (`select_one`)**, cuyos
      valores son un conjunto acotado y conocido del esquema. Extensiones posibles según
      demanda: (a) **campos de texto / metadatos** (p. ej. `_submitted_by`), enumerando los
      **valores distintos presentes en los datos** (`scope-fields ?values=` ya los da) con un
      tope claro; (b) **`select_multiple`**, donde «un enlace por valor» dejaría de ser una
      partición (un envío con varios valores caería en varios enlaces) y usaría el operador
      `has_any` en vez de `in`. Ambos son incrementales sobre el endpoint actual
      (`admin/shares_bulk.php`) y el modal de `SharesView.vue`.
- [ ] **Agregación semanal explícita en Estadísticas** *(pendiente menor)* — hoy «Envíos por
      día/mes» elige día↔mes automáticamente según el tramo; valorar el escalón intermedio
      por semana.
- [ ] **Filtrado avanzado de estadísticas por cualquier campo** *(mayor esfuerzo; mucho
      más adelante)* — hoy las estadísticas se pueden acotar por estado de revisión
      (tarjetas del encabezado) y por equipo (checkboxes del desglose por equipo). El salto
      siguiente sería reutilizar el **filtro avanzado** que ya tiene la lista de envíos
      (`RowFilterEditor.vue` + `?filter=` con RowScope multi-condición AND/OR) directamente
      sobre la página de estadísticas: acotar por cualquier pregunta o combinación (p. ej.
      «mujeres, de La Habana, desempleadas»). `Stats::compute` ya admite un `$scope`
      arbitrario, así que el grueso es UX. El filtro por equipo de hoy sería un caso
      particular de este mecanismo general.
- [ ] **Carga diferida del catálogo i18n de la Guía** — cargar `guide.json` bajo demanda por
      ruta (vue-i18n `setLocaleMessage` + import dinámico). Solo merece la pena con un
      3.er idioma o si la Guía crece a documentación larga; adaptar entonces el check de
      paridad.
- [ ] **Acciones en lote en las vistas de admin** *(de la revisión de jul-2026)* — la
      revisión de envíos ya tiene selección múltiple, pero Usuarios / Formularios /
      Cuentas no: cambiar el rol o activar/desactivar a varios usuarios, o archivar
      varios formularios, exige ir uno a uno. Reutilizar el patrón de selección +
      `confirmDialog` de la tabla de envíos. Útil sobre todo en instancias grandes;
      esperar a demanda real.
- [ ] **Búsqueda global (Cmd/Ctrl+K)** *(de la revisión de jul-2026)* — un buscador en el
      shell que cruce formularios, usuarios, cuentas y enlaces (y quizá envíos por
      formulario), con resultados en un popover. Esfuerzo alto; hoy cada tabla tiene su
      búsqueda propia.
- [ ] **Navegación por teclado en tablas** *(de la revisión de jul-2026)* — flechas para
      moverse entre filas, Enter para abrir el detalle, Espacio para seleccionar,
      Shift+clic para rangos. Para usuarios intensivos; esfuerzo alto.
- [ ] **Pasada transversal de accesibilidad en formularios** *(de la revisión de
      jul-2026)* — asociar `label`/`id` (via `useId()`) en todos los formularios de las
      vistas (hoy los labels envuelven al input sin `for`); revisar validación inline y
      marcado de campos requeridos. Los diálogos, popovers y botones-icono ya están
      cubiertos.
- [ ] **Aviso «nueva versión disponible» en la PWA** *(de la revisión de jul-2026)* — hoy
      el service worker actualiza en silencio (`skipWaiting`); un toast «Hay una versión
      nueva — recargar» daría visibilidad al cambio. Solo descubribilidad, el mecanismo
      funciona.
- [ ] **Escalabilidad más allá de ~200k envíos por formulario** *(de la revisión de
      jul-2026; medir ANTES con slow query log)* — dos palancas si aparecen instancias
      así: columnas generadas/índices MySQL para los filtros `JSON_EXTRACT` más usados, y
      pasar `Stats::compute` de un bucle PHP sobre toda la caché a agregación SQL (o
      cachear también la vista interna; la pública ya se cachea). A los volúmenes típicos
      de Kobo no hace falta.

---

## Operación y mantenimiento

> El **instalador CLI** (`php api/cli/install.php`) y el **release «deploy-ready»**
> (`npm run package`) están entregados (ver `CHANGELOG.md` y DEPLOY §§3–4). Una variante
> **web** del instalador (estilo WordPress, con autodeshabilitado e `install.lock`) sigue
> como idea futura, aunque su beneficio es limitado: lo duro (dominio, vhost, HTTPS, subir
> archivos, crear BD+usuario MySQL, `config.php`) exige igualmente acceso al servidor.

- [ ] **Transporte de correo alternativo (SMTP)**: hoy el envío es solo vía Resend (API
      HTTP, `lib/Mailer.php`). Ofrecer SMTP para quien prefiera su propio servidor — choca
      con la filosofía «sin dependencias»; valorar abstraer un `MailTransport` con
      back-ends `resend`|`smtp`.
- [ ] **Circuit-breaker hacia Kobo en el cron de sync** *(de la revisión de jul-2026)* —
      si Kobo está degradado (timeouts en cadena), el cron reintenta formulario a
      formulario cada 15 min sin enfriamiento. Contar fallos consecutivos por cuenta y
      saltarse el resto de la corrida (o esperar exponencialmente) tras N fallos.
- [ ] **Registro de errores de API consultable** *(de la revisión de jul-2026)* — los
      errores de sync quedan en `forms.last_sync_error` y los cron en `/health`, pero no
      hay un historial de errores de endpoints consultable por el admin (tabla
      `error_log` + vista). Valorar si la auditoría + logs del servidor no bastan.

---

## Ampliaciones futuras (del plan original)

- [ ] **Versión de escritorio** con Tauri (envuelve el mismo frontend Vue).
- [ ] **Webhooks de Kobo** para sincronización en cuasi-tiempo-real (en vez de cron cada 15 min).
- [ ] **Notificaciones por otros canales** (Telegram, Slack, WhatsApp).
- [ ] **Permiso `can_delete`** cuando exista la funcionalidad de borrado de envíos.
- [ ] **Permisos por período de tiempo** (acceso a envíos de un rango de fechas).
- [ ] **2FA** — la tabla `user_sessions` ya está preparada para soportarlo.

---

*Cuando una tarea se complete, muévela a `CHANGELOG.md` con su fecha.*
