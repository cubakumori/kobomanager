# Changelog

Todos los cambios notables de KoboManager. El formato sigue
[Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y el versionado
[SemVer](https://semver.org/lang/es/).

## [Sin publicar]

### Añadido

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

- El botón «Cerrar sesión» del sidebar se alinea a la izquierda como el resto.
- Al cerrar sesión en el panel se vuelve a la **portada** (`/`) en lugar de a `/login`.
- En la portada, el encabezado deja solo el texto «KoboManager» (sin icono) y las tarjetas
  de características adoptan el estilo verde (sin iconos); el encabezado móvil del panel
  iguala al de la portada (marca a la izquierda, botón a la derecha).

### Corregido

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
