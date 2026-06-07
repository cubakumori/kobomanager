# Changelog

Todos los cambios notables de KoboManager. El formato sigue
[Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y el versionado
[SemVer](https://semver.org/lang/es/).

## [Sin publicar]

### Añadido

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
