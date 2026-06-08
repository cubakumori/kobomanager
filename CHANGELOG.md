# Changelog

Todos los cambios notables de KoboManager. El formato sigue
[Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y el versionado
[SemVer](https://semver.org/lang/es/).

## [Sin publicar]

Segundo hito del **roadmap 1.x**: scoping por filas **multi-condición (AND/OR +
operadores)**.

### Añadido

- **Filtro de filas con grupos AND/OR y operadores** (antes solo `campo = uno de`
  combinado con Y). `lib/RowScope` pasa a una forma canónica de **grupos a 2 niveles**
  (`{match, groups:[{match, conditions:[{field, op, values}]}]}`): los grupos se
  combinan con un conector raíz y, dentro de cada grupo, las condiciones con el conector
  del grupo (`all`=Y, `any`=O). Permite expresar p. ej. *«(provincia=Habana Y edad≥18)
  O (provincia=Santiago Y sexo=F)»*. Aplica a viewers (`user_form_permissions.row_filter`)
  y a enlaces compartidos (`share_links.row_filter`). Pedido en el foro y **no soportado
  por Kobo** ([condition-based-row-level-permissions/55372](https://community.kobotoolbox.org/t/condition-based-row-level-permissions/55372)).
- **Operadores por condición**: `in` (es uno de), `nin` (≠ / ninguno de),
  `lt/lte/gt/gte` (rango numérico o de fechas), `empty`/`not_empty` (vacío / con valor) y,
  para `select_multiple`, operadores de conjunto `has_any`/`has_all`/`has_none`. El editor
  ofrece los operadores y el widget de valor según el **tipo de campo** (opciones con
  casillas para `select_one`/`select_multiple`, rango para numéricos/fechas, texto libre
  con sugerencias para el resto).
- **Editor de filtro reutilizable** (`src/components/RowFilterEditor.vue`): un único
  componente para construir el filtro, usado tanto en **Permisos** (por usuario) como en
  **Enlaces compartidos** (por enlace), con grupos añadibles/eliminables y conectores
  seleccionables.

### Cambiado

- La traducción a SQL (`JSON_EXTRACT`) y la evaluación en PHP (`matches()`) comparten
  exactamente la misma semántica para cada operador (paridad blindada con tests, incluida
  una batería contra datos reales). Se mantiene el escape de barras en rutas de grupo
  (`G01/P1_3`), el **fail-closed** (`in` sin valores no deja pasar la fila) y el bypass de
  administradores.

### Retrocompatibilidad

- El formato anterior `{conditions:[{field,values}]}` (solo-Y, `op` implícito `in`) se
  **sigue leyendo**: `RowScope::normalize()` lo canonicaliza al vuelo a un único grupo
  `all`. **No se reescriben datos en BD**; al re-guardar desde la UI se persiste el nuevo
  formato. Sin cambios de esquema (las columnas `row_filter` siguen siendo `JSON`).

## [1.1.0] - 2026-06-08

Primer hito del **roadmap 1.x** (permisos a nivel de columna) más una tanda de
correcciones y mejoras de UX y estadísticas.

### Añadido

- **Backfill de envíos al importar un formulario**: el descubrimiento traía solo
  metadatos, así que un formulario recién importado mostraba «0 envíos» hasta el
  cron. Ahora la primera vez que se descubre un formulario se traen también sus
  envíos. Si falla la descarga no se interrumpe la importación (lo recoge el cron
  o «Actualizar»). Columna nueva `forms.submissions_synced_at` (la fija
  `SubmissionSync`).
- **Estadísticas · valores sobre los gráficos**: cada barra/segmento muestra el
  conteo —y el % cuando aplica— sin necesidad de pasar el ratón (clave en móvil),
  mediante un plugin propio de Chart.js (sin añadir dependencias).
- **Estadísticas · «Distribución por pregunta» incluye `select_multiple`**: antes
  solo contaba `select_one`, lo que dejaba huecos en la numeración (p. ej. saltaba
  de la pregunta 1 a la 3). Ahora cuenta también las de opción múltiple (cada opción
  elegida; el % es sobre encuestados y puede sumar más de 100 %, indicado en la UI).

### Cambiado

- **Estadísticas · serie temporal**: el gráfico «Envíos por día» pasa a **«Envíos por
  mes»** cuando el tramo entre el primer y el último envío supera 30 días, para que no
  se vuelva ilegible en periodos largos (lo decide el backend en `period_granularity`).
- **Estadísticas · «Por enumerador»** se oculta cuando no aporta (solo se muestra
  con 2+ enumeradores reales; no si los envíos no traen `_submitted_by`).
- En la tabla de envíos, la acción de cada fila se llama ahora **«Detalles»**
  (antes «Abrir formulario», que se confundía con abrir el formulario en Kobo).
- En «Mis formularios», un formulario aún sin sincronizar muestra **«Sin
  sincronizar todavía»** en vez de «0 envíos» (se distingue «0 real» de «pendiente
  de sincronizar» con `forms.submissions_synced_at`).

### Corregido

- Los **modales** ya no se salen de la pantalla cuando su contenido es alto: el panel
  se limita a la altura del viewport y su cuerpo hace scroll (afecta sobre todo al
  filtro de filas al añadir varias condiciones).

- **Permisos a nivel de columna (ocultar campos sensibles)** — primer hito del
  roadmap 1.x. Un administrador puede ocultar campos concretos de un formulario a
  un usuario (p. ej. datos identificativos), por **(usuario, formulario)**. Es el
  gemelo del scoping por filas: mientras aquél decide *qué envíos* se ven, éste
  decide *qué campos* salen. Modelo: lista de **ocultar** (denylist)
  `{"hidden":["clave","g_a/region"]}` en `user_form_permissions.field_filter`
  (NULL = ve todos los campos → retrocompatible); los admin no tienen restricción.
  El ocultado se aplica de forma consistente en **toda** lectura: tabla de envíos,
  detalle, **estadísticas** (las preguntas ocultas no se cuentan), **exportación
  CSV**, el esquema resuelto (no se filtra ni la *etiqueta* del campo oculto), los
  **adjuntos** (incluido el proxy de descarga) y la **geolocalización** (un campo
  geo oculto no aparece en el detalle ni en el mapa). La **edición** de un campo
  oculto se rechaza. La **búsqueda**, para usuarios con columnas ocultas, casa solo
  campos visibles (no el índice FULLTEXT global), para no filtrar que una fila
  contiene un valor sensible oculto.
- El ocultado de columnas también se aplica a los **enlaces compartidos**
  (`share_links.field_filter`), configurable al crear el enlace: la vista pública
  (lista/detalle/mapa/adjuntos/búsqueda) respeta los mismos campos ocultos.
- UI: nueva columna **«Columnas»** en *Permisos* con un selector de campos a ocultar
  por formulario, y una sección **«Ocultar columnas»** al crear un enlace en
  *Compartir*. Reutiliza el endpoint `scope-fields` (admite todos los tipos de
  campo, incluido `select_multiple` y geo). i18n es/en.

## [1.0.0] - 2026-06-08

**Primera versión pública.** Recoge todo lo entregado en 0.1.0–0.4.0 (fases 0–7,
enlaces compartibles, productividad de datos, observabilidad, las cuatro mejoras de
producto P1–P4, búsqueda FULLTEXT, endurecimiento de sesiones/operación y el repaso de
fortalecimiento M5) tras la revisión manual exhaustiva, más los cambios de abajo. El
producto se posiciona en torno al **control de acceso** sobre KoboToolbox —permisos por
formulario, scoping por filas, enlaces de solo lectura gobernados y flujo de revisión
propio— **sin repartir cuentas de Kobo ni exponer el token**.

### Añadido

- **Estado de revisión «En espera» (on-hold)** como tercer estado, además de
  Aprobado y Rechazado: marca un envío como *revisado pero pendiente de
  verificación* —distinto del «Pendiente» de los que aún no se han revisado— y
  sirve para dejar una nota sin aprobar ni rechazar todavía. Disponible en el
  detalle del envío, en la **revisión en lote** y como opción del **filtro** por
  estado; se refleja en el badge, en las **estadísticas** (tarjeta + distribución)
  y en el **visor de auditoría**. Es un estado interno de KoboManager: no escribe
  en el `validation_status` de Kobo. (Valor interno `on_hold`; columna
  `submission_reviews.status` ampliada en el esquema canónico.)

### Cambiado

- Reposicionada la introducción de **«Compartir»** en la Guía para destacar el
  **control** del enlace (contraseña, caducidad, revocación, filtro de filas, sin
  exponer el estado de revisión interno) en lugar de apoyarse en la retirada del
  «compartir sin login» de Kobo —matiz impreciso: compartir el *formulario* para
  recoger datos sigue vigente.

## [0.4.0] - 2026-06-07

Primera tanda hacia la versión pública: enlaces compartibles (M1), productividad de
datos (M2), observabilidad (M3), las cuatro mejoras de producto (P1–P4), búsqueda
FULLTEXT (M4a), endurecimiento de sesiones/operación (M4b) y el repaso de
fortalecimiento (M5). El tag **1.0.0** se reserva para tras la revisión manual.

### Seguridad (M5 · repaso y fortalecimiento)

- **Cabeceras de seguridad** en todas las respuestas de la API (`api/index.php`):
  `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`
  y `Strict-Transport-Security` cuando la petición es HTTPS. Los **proxies de adjuntos**
  (`submissions/{id}/attachments/...` y el público de share) añaden además
  `Content-Security-Policy: default-src 'none'; sandbox` y solo sirven **inline** el contenido
  multimedia (imagen/audio/vídeo); el resto se fuerza como **descarga** (`Content-Disposition:
  attachment`). Cierra el vector de XSS almacenado por MIME-sniffing de un adjunto de tercero.
- **Neutralización de inyección de fórmulas CSV** en la exportación (`forms/export.php`): toda
  celda que empiece por `= + - @`, tabulador o retorno de carro se prefija con un apóstrofo
  (fuerza texto), evitando que Excel/LibreOffice ejecute fórmulas incrustadas en datos de
  envíos rellenados por terceros.
- **Rate-limit de los enlaces públicos de share** (pendiente de M1): nueva tabla `rate_hits` y
  `RateLimit::tooManyBucket/hitBucket` (bucket propio, separado de `login_attempts` para no
  cruzar el throttle de login con el de lectura). `ShareLink::throttle()` limita a **240
  peticiones/60 s por IP** los GET públicos (meta/lista/detalle/mapa/adjuntos) — anti-scraping/
  DoS sobre un enlace filtrado, encima del token impredecible + revocación/caducidad.
- **Defensa en profundidad:** `KoboClient::getAttachment` ahora valida que las redirecciones
  sean HTTP(S) y limita los saltos (`MAXREDIRS`, `REDIR_PROTOCOLS_STR`) — anti-SSRF; el
  decodificador JWT rechaza explícitamente cualquier `alg` distinto de `HS256`; **`Request::json`
  acota el cuerpo a 2 MB** (rechaza por `Content-Length` y al leer → 413, anti-DoS por memoria);
  el `.htaccess` del API bloquea también `tests/` y `vendor/`, y `DEPLOY §6` documenta el
  equivalente **nginx** (bloqueo de `lib`/`cron`/`cli`/`tests`/`vendor`/`config.php`).
- Tests: rate-limit por bucket (independencia entre buckets y de login) y rechazo de JWT con
  `alg` no-HS256. Suite **95 tests / 224 aserciones** en verde.

### Eliminado

- Claves i18n huérfanas `guide.dataReview`/`guide.dataReviewBody` (ya no se renderizaban tras
  reorganizar la Guía); paridad es/en intacta.

### Añadido

- **M4b · Seguridad/operación.** Endurecimiento de sesiones y operativa de claves/backups:
  - **Sesión deslizante (sliding session).** El JWT pasa de expiración *absoluta* (te echaba a
    las 8 h aunque estuvieras trabajando) a **renovarse con la actividad**: en cada request, si al
    token le queda poco (< `SESSION_REFRESH_THRESHOLD`, por defecto la mitad del idle TTL) se
    **re-emite manteniendo el mismo `jti`** —así la invalidación por `jti` sigue intacta— y se
    extiende `user_sessions.expires_at`. Hay un **tope absoluto** desde el login
    (`SESSION_ABSOLUTE_TTL`, 7 días por defecto): pasado ese punto se exige re-login aunque haya
    actividad, lo que **acota la vida de una cookie robada**. Sin cambios de esquema
    (`user_sessions.created_at` ancla el tope). Constantes nuevas en `config.php`:
    `SESSION_ABSOLUTE_TTL` y `SESSION_REFRESH_THRESHOLD`.
  - **«Cerrar las demás sesiones» (autoservicio).** Nuevo `GET/DELETE /profile/sessions`: el GET
    lista las sesiones activas del propio usuario marcando «este dispositivo» (por su `jti`); el
    DELETE **cierra todas menos la actual** (revoca sus JWT), auditado como `revoke_own_sessions`.
    Equivalente de autoservicio del cierre remoto que el admin ya hacía en
    `/admin/users/{id}/sessions`, sin desconectar el dispositivo en uso. Nueva sección
    **«Sesiones activas»** en *Mi perfil* (lista + confirmación + flash).
  - **Rotación de `CONFIG_TOKEN_KEY`.** `TokenVault::encrypt/decrypt` aceptan ahora una **clave
    explícita** (default = `CONFIG_TOKEN_KEY`) y se añade `TokenVault::reencrypt(enc, vieja, nueva)`
    (función pura). CLI `php api/cli/rotate_token_key.php [--dry-run]` re-cifra todos los
    `kobo_accounts.api_token` de la clave vieja (`CONFIG_TOKEN_KEY`) a la nueva
    (`CONFIG_TOKEN_KEY_NEW`) en una **transacción** con verificación de ida y vuelta. Procedimiento
    paso a paso + rollback en `DEPLOY.md §12`.
  - **Copias de seguridad.** Estrategia documentada en `DEPLOY.md §11`: `mysqldump`
    (`--single-transaction`, cron nocturno con retención) + `api/config.php` (único secreto fuera
    de git); restauración y aviso de que no hay ficheros subidos en disco (los adjuntos se
    *streamean* desde Kobo).
  - Tests: rotación de `TokenVault` (función pura), sesión deslizante (renueva cerca de la
    expiración / no renueva con margen) y tope absoluto (la sesión muere y se borra). Suite **92
    tests / 219 aserciones** en verde.
- **M4a · Índices/búsqueda en `submissions_cache`.** La búsqueda de la tabla de envíos (y de la
  exportación CSV y los enlaces compartidos) dejaba de hacer `LIKE` sobre el **JSON completo**
  de cada fila (escaneo total, y matcheaba dentro de claves y metadatos) y pasa a un índice
  **`FULLTEXT`**:
  - Nueva columna `submissions_cache.search_text` con una **proyección en texto plano de los
    valores de respuesta** (sin claves ni metadatos `_*`: nada de URLs de adjuntos, UUIDs
    internos ni rutas de campo), poblada por la app (`lib/SubmissionSearch::textFor`) en cada
    sync y en cada edición de envío. Esto además **quita el ruido**: buscar «audio» ya no casa
    con el `question_xpath` de un adjunto.
  - Las búsquedas usan `MATCH … AGAINST (… IN BOOLEAN MODE)` con prefijo (`+token*`) por palabra
    (multi‑palabra = AND). Para términos demasiado cortos para FULLTEXT (< 3 caracteres) se cae a
    un `LIKE` sobre `search_text` para no perder esas búsquedas. Centralizado en
    `lib/SubmissionSearch::clause()`, usado por los tres endpoints de búsqueda.
  - **Backfill**: `php api/cli/rebuild_search_text.php [form_id]` recalcula `search_text` de los
    envíos ya cacheados (y si cambia la lógica de proyección). En operación normal la columna se
    mantiene sola.
- **P4 · Adjuntos en enlaces compartidos.** Un enlace de solo lectura puede ahora exponer los
  adjuntos de los envíos (fotos, audio, vídeo, documentos) de forma segura, además de la lista /
  detalle / mapa que ya exponía:
  - **Proxy público** `GET /public/share/{token}/submissions/{uid}/attachments/{attId}`
    (`v1/public/share_attachment.php`): descarga el archivo con el token de la cuenta Kobo —que
    **nunca** sale al navegador— y lo *streamea*. Guardado por `ShareLink::requireAccess(token,
    'attachments')`, que valida que el enlace exponga adjuntos, exige **ticket** si el enlace
    tiene contraseña (vía cabecera `X-Share-Ticket` **o** `?k=`, porque un `<img>`/`<audio>` no
    puede enviar cabeceras), comprueba que el envío esté **dentro del alcance de filas** del
    enlace (fuera → 404) y que el adjunto **pertenezca** a ese envío.
  - **Doble capa de protección** (los adjuntos suelen contener PII sensible): solo pueden
    exponerse en enlaces **con contraseña** y si la **política global** `share_attachments_policy`
    (`off` | `require_password`, **`off` por defecto**, en *Configuración*) lo permite. La política
    se valida al crear el enlace **y actúa como *kill-switch* en vivo**: volverla a `off` deja de
    servir los adjuntos de los enlaces ya creados.
  - **Galería agrupada por tipo** (Imágenes / Audio / Vídeo / Documentos·PDF / Otros, vía
    *mimetype*): nuevo componente reutilizable `AttachmentsGallery.vue` y nuevo helper
    `lib/Attachments.php` (`forPayload`/`kind`), usados tanto en la **vista pública** del enlace
    como en el **detalle autenticado** (que antes los listaba en plano).
  - Tabla `share_links`: nueva columna `expose_attachments`. *(El **rate-limit de los GET
    públicos** sigue diferido a M4b/M5; hoy solo el `unlock` de contraseña se limita por IP.)*
- **P3 · Estadísticas enriquecidas.** La vista de *Estadísticas* de un formulario, que antes
  solo mostraba total + envíos por día + estado de revisión, gana —calculado en una sola
  pasada en el backend (`forms/stats.php`), respetando permisos y *scoping* por filas:
  - **Distribución por pregunta** (`select_one`): conteo y % por opción de cada pregunta de
    opción única, con etiquetas resueltas al idioma del usuario y respetando el modo de
    etiquetas; barras horizontales (top 20 opciones + «+N más»). *(Opción múltiple diferida a
    una 2.ª fase, como en el filtrado por filas.)*
  - **Por enumerador** (`_submitted_by`): reparto de envíos por usuario de Kobo (`—` si el
    envío no lo trae).
  - **Duración de cumplimentación**: media, mediana, mínimo, máximo e **histograma** por
    cubetas (reutiliza `lib/Derived`).
  - **Actividad por hora y por día de la semana**, **adjuntos** (% con adjuntos + reparto por
    tipo), **cobertura geográfica** (% con ubicación) y **frescura** (último envío).
  - Frontend: nuevas secciones en `StatsView` con `StatsChart` (barras horizontales/verticales
    y *doughnut*); i18n `stats.*`. *(Agregación semana/mes + acumulado y tendencia 7/30 días
    quedan para una 2.ª fase.)*
- **P2 · Valores «calculados» por envío.** Nueva clase pura `lib/Derived.php` que computa,
  a partir del payload de cada envío y del esquema del formulario, métricas que Kobo no
  entrega directamente: **duración** (`end − start`), **completitud** (preguntas respondidas /
  total), **velocidad** (duración / nº de preguntas), **retraso de subida**
  (`_submission_time − end`), **nº de adjuntos por tipo** (imagen/audio/vídeo/archivo),
  **tiene geolocalización**, **hora/día** del envío, **enviado por** (`_submitted_by`),
  **versión** (`__version__`), **estado de validación de Kobo** (`_validation_status`) y
  **nº de etiquetas/notas** (`_tags`/`_notes`). Las métricas sin dato (p. ej. duración sin
  `start`/`end`, que no están en todos los XLSForm) se muestran como **«—»**. Se reutiliza
  idéntica en tres sitios, computada en el backend junto a `label_mode`/`field_truncate`:
  - **Detalle**: nuevo acápite **«Resumen»** con la lista completa de métricas, formateadas
    y localizadas.
  - **Tabla de envíos**: tres columnas opcionales (**Duración**, **Adjuntos**, **Geo**)
    integradas en el **selector de columnas** existente (grupo «Calculadas», apagadas por
    defecto, arrastrables y persistidas como las demás). *(Ordenar por columna calculada se
    difiere a una 2.ª fase.)*
  - **Exportación CSV**: las mismas tres columnas se anexan al final, calculadas con la misma
    clase. Respeta permisos y *scoping* por filas (solo se computa sobre envíos ya visibles).
  - `FormSchema::normalize` ahora registra también los campos meta `start`/`end`/`today`
    (en `schema_json.meta`) para localizar las marcas de tiempo aunque el formulario los haya
    nombrado de forma no estándar; si faltan, se cae a las claves convencionales `start`/`end`.
- **P1 · Auditoría propia (autoservicio).** Nuevo ajuste global en *Configuración*
  «Auditoría propia» (`audit_self_view_enabled`, **desactivado por defecto**) que habilita a
  cualquier usuario —no solo administradores— a consultar **su propio** registro de actividad
  desde una nueva entrada de menú **«Mi actividad»** (visible solo si el ajuste está activo).
  Endpoint `GET /audit/me` que **fuerza `user_id` = usuario actual** (ignora cualquier
  `user_id` del query) y reutiliza la paginación/filtros del visor admin (acción, formulario,
  rango de fechas y búsqueda), **sin** filtro ni columna de «usuario»; el desplegable de
  acciones se limita a las del propio usuario. Requiere sesión (no admin); si el ajuste está
  desactivado responde **403** para todos (los administradores disponen del visor completo en
  *Auditoría*). La lógica de consulta se extrajo a `Audit::query()`, compartida por
  `admin/audit.php` y `audit/me.php`. El flag viaja con el usuario en `/auth/me` y
  `/auth/login` para gobernar el menú sin peticiones adicionales.
- **Acortar nombres de campo** (ajuste global en *Configuración*, desactivado por defecto):
  un *checkbox* «Acortar nombres de campo» + un número de caracteres (8–120, por defecto 24).
  Al activarlo, los nombres de campo largos se muestran recortados con «…» en las cabeceras
  de la tabla de envíos, el selector de columnas y el detalle (también en los enlaces
  públicos); el **nombre completo** aparece en el *tooltip* al pasar el ratón. La
  **exportación CSV nunca acorta**. El recorte se centraliza en el *labeler*
  (`composables/labels.js`) y el ajuste viaja con `label_mode` en las respuestas de lectura.
- **M3 · Observabilidad/ops.** Nueva sección admin **Auditoría** (`/admin/audit`) con dos
  partes:
  - **Visor de `audit_log`**: tabla paginada de acciones (quién, qué, cuándo) con su
    detalle, **filtrable** por acción, usuario, formulario, rango de fechas y búsqueda
    libre (sobre el envío o el detalle). Las acciones se muestran con etiquetas legibles
    (i18n) y *fallback* al código. Backend `GET /admin/audit` (solo admin).
  - **Estado del sistema**: panel con la **última ejecución de cada cron** (con estado OK/
    error y marca de tiempo) y el **estado de sincronización** (formularios activos, con
    error de sync, envíos en caché, última sincronización, email configurado). Los crons
    (`sync_submissions`, `daily_summary`) registran su ejecución vía un nuevo
    `Settings::recordCronRun()`; **`GET /health`** se amplía con secciones `cron` y `sync`
    **solo para administradores** (el sondeo público sigue devolviendo solo `status`/`checks`).
- **M2 · Productividad de datos.** Dos mejoras en la tabla de envíos (*Mis formularios* →
  un formulario), ambas respetando permisos y el scoping por filas:
  - **Revisión en lote**: selección de envíos con casillas (más «seleccionar todos los de
    la página») y una barra de acciones para **aprobar o rechazar** los seleccionados de
    una vez, con comentario opcional común. Solo visible para quien puede **validar** el
    formulario. Backend `POST /forms/{id}/review` (`forms/review_batch.php`): un único
    chequeo de capacidad y, por seguridad, **revalida en el servidor** que cada envío
    pertenece al formulario y está dentro de alcance (los demás se omiten); devuelve
    *aplicados/omitidos* y audita la operación.
  - **Exportación CSV**: botón *Exportar CSV* que descarga los envíos **con los filtros
    activos** (búsqueda y estado de revisión). CSV **UTF-8 con BOM** (abre bien en Excel),
    una columna por pregunta más *enviado* y *revisión*; cabeceras y valores siguen el modo
    de etiquetas global (en modo *labels*, las opciones se muestran con su texto). Backend
    `GET /forms/{id}/export` (`forms/export.php`), respeta `can_view` + scoping. *(XLSX
    nativo se difiere por la filosofía sin‑dependencias.)*
- **M1 · Compartir — enlaces de solo lectura.** El administrador puede crear, desde
  *Compartir* (nueva sección admin), **enlaces públicos** que muestran los envíos de un
  formulario **sin necesidad de cuenta** en Kobo ni en KoboManager —reemplazo directo del
  «compartir sin login» que KoboToolbox está retirando. Cada enlace decide **qué expone**
  (lista de envíos, detalle y/o mapa) y puede llevar un **filtro de filas** (reutiliza el
  scoping por filas) para mostrar solo un subconjunto. El acceso es por un **token
  impredecible** en la URL (`/s/<token>`); opcionalmente protegido con **contraseña** según
  la política global `share_password_policy` (`off` | `optional` | `required`, por defecto
  *opcional*; configurable en *Configuración*). Los enlaces admiten **caducidad opcional** y
  son **revocables al instante** (o eliminables); registran nº de visitas y última visita.
  La vista pública vive **fuera del shell** del panel, con encabezado propio, pestañas
  Lista/Mapa, detalle navegable (anterior/siguiente) e i18n es/en. Backend sin dependencias:
  tabla nueva `share_links` (`db/008_*.sql`), `lib/ShareLink.php`, endpoints públicos sin
  sesión bajo `v1/public/` y CRUD admin en `v1/admin/shares*`. El endpoint de contraseña
  (`unlock`) está limitado por IP; emite un *ticket* HMAC de vida corta para no reenviar la
  contraseña. No se exponen adjuntos ni el estado de revisión interno. *(Rate-limit de los
  GET públicos: se recomienda a nivel de proxy; ver ROADMAP.)*
- **Scoping por filas**: un *viewer* con acceso a un formulario puede ahora ver solo
  **ciertos envíos**, según un filtro configurable por el administrador en *Permisos*.
  El filtro es una lista de condiciones **campo + valores permitidos** combinadas con **Y**
  (cada condición acepta varios valores); p. ej. «región ∈ {norte, este}» o «usuario que
  envió (`_submitted_by`) ∈ {alice, bob}». Sin filtro, el comportamiento es el de siempre
  (ve todos los envíos). El filtro se aplica en la lista de envíos, las estadísticas, el
  mapa, el conteo de *Mis formularios* y el resumen diario por email; un envío fuera de
  alcance se comporta como inexistente (404) también al ver el detalle, **editar** o
  **validar** (el filtro restringe el conjunto de filas; las capacidades `editar`/`validar`
  siguen aplicando sobre las filas visibles). Configuración con etiquetas legibles y, para
  preguntas de opción, sus etiquetas; para texto/metadatos, sugerencias de valores desde la
  caché. i18n es/en. *(Limitación v1: las preguntas `select_multiple` no se pueden filtrar.)*
- En la portada, nueva tarjeta **«Acceso por filas»** que presenta el control de acceso
  granular; el título «KoboManager» del encabezado público ahora enlaza al inicio.

### Cambiado

- En el menú/encabezado público, **«Tutoriales» pasa a llamarse «Guía»** (es/en), más
  ajustado a su contenido actual.
- En las acciones de formulario, la acción **«Ver»** (que abre el formulario público en
  Enketo) se renombra a **«Abrir formulario»** para no confundirla con **«Ver envíos»** (es/en).
- **Guía de uso ampliada** para cubrir todo lo que hace la app hoy: nuevas secciones de
  **Compartir** (enlaces de solo lectura), **Revisar y exportar** (revisión en lote + CSV),
  **Acciones sobre un formulario** (Enketo/actualizar/resync/login), **Explorar la tabla**
  (búsqueda/filtros/columnas/estadísticas), **Notificaciones**, **Auditoría y estado del
  sistema** y **Seguridad y privacidad**. i18n es/en.
- La **Guía de uso** ya no se abre como página «fuera» del panel: con sesión iniciada se
  carga **dentro del shell** (junto al resto del contenido); sin sesión sigue siendo una
  página pública, ahora con el **mismo encabezado que la portada** (encabezado público
  extraído a un componente reutilizable).

### Corregido

- En **Auditoría**, el nombre del cron en «Últimas ejecuciones» se mostraba crudo
  (`daily_summary`): ahora lleva etiqueta legible (es/en), con el identificador en el *tooltip*.
- El **`<select>` de campo del filtro de filas** (en *Permisos* y *Compartir*) podía
  desbordar el ancho del modal con nombres de campo muy largos; ahora queda contenido
  (`min-w-0` + recorte) dentro del modal.
- Al **cerrar las propias sesiones** desde *Usuarios* (admin), la app no salía del panel
  hasta recargar; ahora cierra sesión y redirige a la portada de inmediato.
- El **diálogo de confirmación** mostraba sus textos por defecto (botón *Cancelar*, título…)
  siempre en español aunque la interfaz estuviera en inglés; ahora se traducen según el
  idioma activo (`common.cancel`/`common.confirm`/`common.areYouSure`).
- El **botón de menú (hamburguesa)** de las páginas públicas aparecía también en pantallas
  grandes (y descolocaba la navegación al centro): su estilo vivía en CSS sin capa y ganaba
  a la utilidad `md:hidden`; ahora va en la capa `components` y se oculta correctamente en
  escritorio, con la navegación alineada a la derecha y el menú lateral móvil a la derecha.

## [0.3.0] — 2026-06-06

### Añadido

- **Licencia AGPL-3.0** y documentación para contribuidores (`ARCHITECTURE.md`,
  `CONTRIBUTING.md`).
- **Tests automatizados del backend** (PHPUnit): cobertura de autenticación y permisos,
  ciclo de sesión JWT (emisión, validación, revocación, logout), *rate limiting*, ajustes,
  cifrado de tokens y el parser geográfico. Se ejecutan contra una base de datos de test
  separada; PHPUnit es la única dependencia de desarrollo (el runtime sigue sin dependencias).
- **Página «Guía de uso»** (`/guide`, pública): explica los roles, el flujo de trabajo,
  la diferencia entre **Actualizar y Resync**, las contraseñas y el trabajo con los datos.
  Enlazada desde «Tutoriales» en la portada y desde una tarjeta en el *Dashboard*. i18n es/en.
- **Acciones de formulario para *viewers*** (configurables por el admin). Desde *Mis
  formularios*, cada usuario puede ahora —si el administrador lo habilita en *Configuración*—
  abrir el formulario público (Enketo), abrirlo en KoboToolbox, **Actualizar** (sync
  incremental) o **Resync** (sync completo) de sus formularios. Cuatro interruptores nuevos
  («Ver/Actualizar/Resync/Login»), desactivados por defecto; los administradores las tienen
  siempre. El backend valida tanto el permiso `can_view` del usuario como el interruptor.
- **Accesibilidad de ventanas y menús**: los modales y los menús laterales (drawers) se
  cierran con **Escape**, atrapan el foco mientras están abiertos (Tab/Shift+Tab circulan
  dentro), llevan el foco al abrirse y lo devuelven al control que los abrió al cerrarse;
  además exponen los roles ARIA (`dialog`, `aria-modal`, etiqueta del título).
- **Indicador global de sincronización** en *Formularios* (admin): un panel muestra, por
  cuenta Kobo, la última sincronización, su estado (correcto / con errores / sin sincronizar)
  y el número de formularios (e inactivos).
- **Cierre de sesión remoto desde el admin**. La lista de usuarios muestra el número de
  sesiones activas y permite **cerrar todas las sesiones** de un usuario (revoca sus tokens;
  tendrá que volver a iniciar sesión), sin necesidad de desactivarlo. Acción auditada.
- **Protección CSRF**: las peticiones que modifican estado (POST/PUT/DELETE) se rechazan si
  su `Origin`/`Referer` no coincide con un origen permitido, reforzando la cookie de sesión
  `SameSite=Lax`.
- **Cambio de contraseña desde el propio perfil**. Sección «Contraseña» en *Mi perfil*
  donde el usuario, ya autenticado, cambia su contraseña indicando la actual y la nueva
  (con confirmación; mínimo 8 caracteres). `POST /profile/password` verifica la contraseña
  actual antes de aplicar el cambio y mantiene la sesión en curso.
- **Recuperación de contraseña por email** («olvidé mi contraseña»). Gobernada por un
  interruptor en *Configuración* admin «Permitir recuperar contraseña» (desactivado por
  defecto). Flujo público: el usuario pide el reset por email (`POST /auth/forgot-password`,
  con *rate-limit* y respuesta genérica que no revela si el email existe) → se genera un
  **token de un solo uso** (en BD se guarda solo su hash SHA-256 + expiración de 1 hora;
  nueva tabla `password_resets`) → email con enlace a la página pública `/reset-password`
  → al fijar la nueva contraseña se **consume el token** y se **invalidan todas las sesiones
  activas** del usuario. El email se envía con Resend (`lib/Mailer.php`); si la clave no está
  configurada, el envío se omite sin error (la UI admin avisa). El enlace «¿Olvidaste tu
  contraseña?» solo aparece en el login si el flujo está habilitado. i18n ES/EN.
- **Vista de mapa** para preguntas de ubicación (`geopoint`/`geoshape`/`geotrace`). El
  detalle de un envío muestra una sección «Ubicación» con su punto, línea o polígono, y
  cada formulario tiene una vista «Mapa» (`/forms/{id}/map`) que pinta todos los envíos con
  coordenadas; al pulsar un marcador se abre el envío. Usa Leaflet + OpenStreetMap (sin
  clave de API).
- **Sincronización de ediciones y borrados de Kobo**. Cada sincronización incremental
  (cron y «Actualizar») hace además un **barrido de bajas**: pide a Kobo solo los `_id`
  vigentes y elimina de la caché los envíos borrados. Nueva acción **«Resync»** por
  formulario que re-descarga todos los envíos y reconcilia por `_uuid`, reflejando también
  las **ediciones hechas directamente en Kobo** (que conservan el `_id` pero cambian el
  `_uuid`). Los resúmenes de sincronización informan de cuántos envíos se eliminaron.
- **Adjuntos en los envíos**. El detalle de cada envío muestra sus `_attachments`
  (fotos, audio, vídeo o archivos) con vista previa según el tipo, y en los campos el
  adjunto se enlaza por su nombre legible. Las descargas pasan por un **proxy
  autenticado** del backend (`GET /submissions/{id}/attachments/{attId}`), de modo que el
  navegador nunca maneja la URL ni el token de Kobo; las redirecciones a almacenamiento
  externo se siguen sin reenviar el token.
- **Etiquetas legibles** de formularios. Al sincronizar se descarga el contenido XLSForm
  del asset (`content.survey` / `content.choices`) y se cachea un esquema normalizado en
  `forms.schema_json` (con soporte multi-idioma y rutas de grupo), refrescándolo en cada
  sincronización. En la **tabla** y el **detalle** de envíos se muestran las *labels* de las
  preguntas y de las opciones (`satisfaccion` → «Satisfacción», `1` → «Muy alta», incluida
  selección múltiple) en lugar de nombres de campo y códigos crudos. La edición de campos de
  opción única usa un desplegable con esas etiquetas. Nuevo ajuste global en *Configuración*
  «Etiquetas en tabla y detalles»: *Labels del formulario* (por defecto) / *Nombres de campo
  y código*.
- **Landing page pública** en `/` con banner de marca, *features* y login en **modal**
  (formulario de login reutilizable); idioma ES/EN conmutable desde la propia portada.
- **Diseño responsive**: en pantallas pequeñas, tanto la portada como el panel usan un
  menú hamburguesa con *drawer* lateral (el sidebar del panel se repliega a favor del
  contenido). Login con el logo centrado y más grande sobre el recuadro.

### Cambiado

- En *Usuarios* y *Cuentas Kobo* (admin), el alta deja de ocupar un bloque fijo: ahora hay
  un botón **«Nuevo»** que abre el formulario en una ventana modal, dejando la lista visible
  de inmediato. En *Formularios*, el panel de estado de sincronización pasa al final.
- El botón de menú (hamburguesa) en móvil pasa a un estilo **neutro**, reservando el color de
  marca para los botones de acción y reduciendo la acumulación visual de azul.
- **Al re-sincronizar con un filtro de estados más restrictivo**, los formularios que dejan
  de cumplirlo ahora se **desactivan** (se ocultan a los usuarios y al cron, conservando su
  caché y revisiones) en lugar de quedarse visibles; vuelven a activarse solos si más adelante
  cumplen el filtro.
- **Tematización por variables CSS**: el color primario (azul) y el secundario/de marca
  (verde) se centralizan como *tokens* de tema en `src/style.css` (`@theme` de Tailwind v4,
  escalas `primary` y `accent` expuestas como variables `--color-primary-*`/`--color-accent-*`).
  Recolorear toda la aplicación es cambiar esas dos escalas en un solo sitio; las clases
  usan `primary`/`accent` en vez de `blue`/`emerald`. El verde de «éxito» se mantiene aparte.
  Se incluyen además dos temas alternativos listos para usar (`theme-teal` y `theme-violet`)
  activables con una clase en `<html>`. Documentado en el README.
- **Diferenciación visual por color**: las tarjetas de *Mis formularios* usan ahora un
  fondo verde claro (emerald, el color de marca) para distinguirse de las tarjetas blancas
  del *Dashboard*, y el encabezado de la tabla de envíos de un formulario va en verde, de
  modo que se reconoce de un vistazo dónde estás.
- Corregido el **espaciado entre etiqueta y campo** en todos los formularios (las etiquetas
  eran *inline* y quedaban pegadas al campo); ahora se separan correctamente.
- El **«Resumen diario por email»** se traslada de *Mi perfil* a una **página propia
  «Notificaciones»** (enlace en el menú lateral, bajo «Mis formularios»). *Mi perfil*
  queda centrado en la cuenta: idioma y contraseña.
- La tabla de envíos permite **filtrar por estado de revisión** (pendiente/aprobado/
  rechazado), **ordenar** por fecha (más recientes/antiguos) y elegir el **tamaño de
  página** (10/25/50/100).
- En la tabla de envíos se puede **elegir qué columnas mostrar y reordenarlas**
  (arrastrando), con «Enviado» siempre visible; la preferencia se guarda por formulario.
- El **detalle de un envío** incluye navegación **Anterior/Siguiente** (arriba y al final).
- El **sidebar** del panel queda fijo al hacer scroll en pantallas grandes (ya no deja un
  hueco cuando el contenido es largo).
- El botón **«Mapa»** se deshabilita cuando ningún envío del formulario tiene coordenadas.
- El botón «Cerrar sesión» del sidebar se alinea a la izquierda como el resto.
- Al cerrar sesión en el panel se vuelve a la **portada** (`/`) en lugar de a `/login`.
- En la portada, el encabezado deja solo el texto «KoboManager» (sin icono) y las tarjetas
  de características adoptan el estilo verde (sin iconos); el encabezado móvil del panel
  iguala al de la portada (marca a la izquierda, botón a la derecha).

### Corregido

- En móvil, al abrir el menú lateral sobre una **vista de mapa**, el mapa ya no queda por
  encima del *drawer*.
- Al sincronizar, los formularios **borrados en Kobo** ahora se eliminan de la app
  (antes seguían listados); el resumen indica cuántos se eliminaron.

Lo previsto a continuación se mantiene en [`ROADMAP.md`](./ROADMAP.md).

## [0.2.0] — 2026-05-31

### Añadido

- **Internacionalización (i18n)** español/inglés con Vue I18n. Idioma por defecto global
  (configurable por el admin en *Configuración*, por defecto español) y override por
  usuario en *Mi perfil*. Resolución: usuario → defecto → español.

- **Configuración global** (página + card en el Dashboard): elegir qué estados de
  KoboToolbox se sincronizan (desplegados/borradores/archivados; por defecto solo
  desplegados). Se guarda el `deployment_status` de cada formulario y se muestra su tipo.
- **Sincronizar por cuenta** desde *Cuentas Kobo* y **filtro por cuenta** en *Formularios*
  y *Permisos* (con opción «Todas las cuentas»).
- **Actualizar por formulario**: trae a la caché los envíos de un único formulario.
- **Eliminar por formulario**: quita un formulario y su caché de KoboManager (no toca Kobo).
- Edición de usuarios: el **email** ahora es editable (con validación de unicidad).

- *Formularios*: acción **Ver** abre el formulario público de **Enketo** (sin cuenta Kobo;
  enlace resuelto vía `deployment__links`), y acción **Login** abre el formulario en
  KoboToolbox (requiere iniciar sesión).
- Diálogos de **confirmación como modal** (componente `ConfirmDialog`) en lugar de `confirm()`/`alert()` del navegador.

### Cambiado

- El filtro por cuenta en *Permisos* se muestra siempre que haya un usuario seleccionado,
  con el mismo estilo de cabecera y filtros que *Formularios*.
- En el Dashboard, el card «Acerca de Kobo» se integra en la rejilla con el resto.

### Corregido

- El primer sync de envíos no traía el histórico porque usaba `forms.last_synced_at`
  (fijado también al descubrir formularios) como cursor. Ahora el cursor incremental
  se deriva del envío más reciente ya en caché.

## [0.1.0] — 2026-05-30

Primera versión funcional completa (fases 0–7 del plan de implementación).

### Añadido

- **Scaffolding y arranque** — monorepo (frontend Vue 3 + Vite en la raíz, backend PHP 8
  en `/api`, migraciones en `/db`). Un solo comando `npm run dev` levanta backend y
  frontend juntos (`concurrently`). Esquema MySQL completo y endpoint `/health`.
- **Autenticación y sesiones** — login con JWT (HS256) en cookie HttpOnly, sesiones en
  `user_sessions` con invalidación activa, contraseñas con `password_hash`. Cifrado de
  tokens de Kobo con libSodium (`TokenVault`). CLI para crear el primer admin.
- **Panel de administración** — CRUD de usuarios y de cuentas Kobo (Tailwind CSS), con
  guards de ruta por rol.
- **Sincronización de formularios** — `KoboClient` (API v2 de KoboToolbox), endpoint de
  sync con estado por cuenta (`sync_status`/`last_sync_error`) y manejo de errores
  mapeados a códigos estándar.
- **Permisos** — matriz usuario-formulario (ver/editar/validar).
- **Caché y vistas de datos** — `cron/sync_submissions.php`, listado paginado de envíos
  con búsqueda, detalle de envío, y registro de visualización en `audit_log`.
- **Edición y revisión** — edición de envíos (escribe en Kobo y luego en caché, con
  integridad ante fallos) y revisión interna (`approved`/`rejected`/`pending`)
  desacoplada de Kobo, con historial.
- **Estadísticas** — endpoint `/forms/{id}/stats` (total, por día, por estado) y vista
  con gráficos (Chart.js).
- **Notificaciones por email** — `Mailer` sobre la API de Resend y cron de resumen
  diario; configuración por usuario en su perfil.
- **Acciones de administración** — editar/eliminar cuentas Kobo (eliminar solo si no
  tienen formularios) y editar/activar/desactivar usuarios, con protecciones
  anti-bloqueo (no auto-desactivarse; siempre un admin activo).

### Seguridad

- Rate limiting en login (5 intentos fallidos por IP por minuto).
- Los tokens de Kobo nunca se exponen al frontend (auditado).
- `.htaccess` endurecido: todo pasa por el front controller; `lib/`, `cron/` y `cli/`
  no son accesibles por web.
- Errores homogéneos con códigos estándar; mensajes claros por código en el frontend.

[Sin publicar]: https://example.com
[0.2.0]: https://example.com
[0.1.0]: https://example.com
