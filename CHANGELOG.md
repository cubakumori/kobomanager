# Changelog

Todos los cambios notables de KoboManager. El formato sigue
[Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y el versionado
[SemVer](https://semver.org/lang/es/).

## [Sin publicar]

### Seguridad

- **Content-Security-Policy y cabeceras de seguridad en el documento de la SPA**
  (`public/.htaccess`, espejo nginx en `DEPLOY.md` §6). La CSP se aplica solo a las
  respuestas `text/html` (no toca `/assets`, el API ni la CSP del proxy de adjuntos)
  y lleva el hash del `<script>` inline de tema para evitar `'unsafe-inline'`. Se
  suman `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer` y
  `X-Frame-Options: DENY` al estático.
- **Modo demo: bloqueado el borrado de formularios** (`DELETE /admin/forms/:id`), que
  purgaba la caché local en cascada y degradaba la demo hasta el siguiente reset.

### Añadido

- **Notificaciones por defecto** (`notifications_default_on`, global, desmarcado por
  defecto) — cuando se activa, los usuarios quedan suscritos al resumen diario por email
  en los formularios **activos** que pueden ver (admins incluidos), sin tener que
  marcarlos a mano. Modelo dinámico: la suscripción efectiva = preferencia explícita del
  usuario o, en su ausencia, el valor por defecto (`COALESCE`), evaluado en vivo; el PUT
  de `/notifications` guarda un 0/1 explícito por formulario visible, de modo que un
  opt-out **persiste** y los formularios nuevos heredan el default. Sin cambio de esquema.
  Checkbox en Configuración + aviso en la página de Notificaciones.
- **Ajuste «líneas del encabezado» en tablas** (`table_header_lines`: 1/2/3, global,
  por defecto 2) — los encabezados de columna largos se ajustan a varias líneas con un
  ancho acotado (`line-clamp` + ancho máx., texto completo en el `title`) en vez de
  estirar la columna a una sola línea muy ancha. Mismo patrón que `table_freeze`
  (Settings → `/config` → `appConfig` cacheado), aplicado a la tabla de envíos y a la
  vista pública de enlaces; control en Ajustes. Ortogonal a «Acortar nombres de campo».
- **Enlaces compartidos: vista de estadísticas** (`expose_stats`). Un enlace público
  puede mostrar el panel de Estadísticas del formulario (mismas gráficas que la vista
  interna), con su filtro de filas y ocultado de columnas aplicados, pero **sin el
  estado de revisión interno**. El cálculo se extrajo a `lib/Stats`, fuente única
  compartida por `forms/stats.php` y el nuevo `public/share/{token}/stats`; el render
  vive en el componente compartido `StatsPanels.vue` (interno + público).
- **Empaquetado «deploy-ready» (`npm run package`)** — `scripts/package.mjs` (sin
  dependencias npm) genera `release/kobomanager-<versión>.zip` con el layout exacto de
  despliegue: contenido de `dist/` + `api/` podado (sin `vendor/`, `tests/`, `phpunit.xml`,
  `composer.*` ni el `config.php` con secretos) + `db/`. DEPLOY §3 lo ofrece como vía A
  (vía B = build manual) y §3.1 documenta la automatización opcional en CI (un workflow que
  en un tag `v*` corre el mismo script y adjunta el zip al GitHub Release).
- **Vista pública de enlaces: sello de frescura** «Datos a fecha de …»
  (`forms.last_synced_at`), para que el visitante sepa cuándo se sincronizó por última
  vez la caché (los enlaces leen la caché local refrescada por el cron, no Kobo en vivo).
- **`SECURITY.md`**: política de divulgación responsable de vulnerabilidades (canal
  privado por GitHub + email de respaldo, alcance y plazos), esperada en un repo
  público AGPL.
- **Portada: CTA de cierre «monta tu propia instancia»** (software libre → enlace al
  repositorio + página «Apoyar»).

### Cambiado

- **«Mis formularios»: color de fondo según el tipo del formulario**, siguiendo la
  columna «Tipo» de admin/forms — desplegado = verde (el accent de marca), borrador =
  ámbar, archivado = gris, con una etiqueta del tipo en las tarjetas no desplegadas.
- **«Mis formularios»: filtro por tipo** (desplegado/borrador/archivado), un `<select>`
  que aparece solo cuando hay más de un tipo, combinable con el filtro por cuenta.
- **Formularios archivados = solo lectura para la revisión.** Se siguen viendo los
  envíos, el estado de revisión y el historial, pero se ocultan la selección y las
  acciones de revisión (individual + por lotes) y el backend las rechaza con
  `FORM_ARCHIVED` (409) — defensa en servidor, no solo en la UI. Un aviso explica el
  modo. La edición de envíos no cambia (sigue dependiendo de `can_edit`).
- **Visibilidad de la parte pública configurable desde Ajustes**: dos interruptores
  globales (ambos activados por defecto) — *Mostrar la página «Apoyar»* (oculta
  /apoyar y sus enlaces; el acceso directo redirige a la portada) y *Mostrar la
  llamada de cierre de la portada*. Pensado para quien autoaloja la app solo para
  uso interno.
- **Enlaces externos públicos (repo, PayPal, Ko-fi) configurables por entorno**
  (`REPO_URL`, `DONATE_PAYPAL_URL`, `DONATE_KOFI_URL`), expuestos vía `/config`. La
  UI oculta los no configurados y, sin donaciones, muestra una línea neutra: una
  instancia clonada ya no enseña botones muertos ni pide donaciones a otra cuenta.

## [1.4.1] - 2026-06-17

### Arreglado

- **`db/001_schema.sql` portable a MySQL desde un dump de MariaDB**: las 12 claves foráneas
  llevan ahora **nombre explícito y único** (`fk_<tabla>_<ref>`). Sin nombre, MariaDB las
  autogenera como `1`/`2` **por tabla**; un `mysqldump` materializa esos nombres y, al
  importarlo en MySQL —que exige nombres de constraint únicos **por base de datos**—
  chocaban (`#1826 Duplicate foreign key constraint name '1'`). Afectaba al flujo de
  preparar la semilla de la demo en MariaDB local e importarla en el MySQL del VPS.

## [1.4.0] - 2026-06-17

### Añadido

- **Sembrado de datos sintéticos para la demo** (`php api/cli/seed_demo.php <form_id>
  <count> [--days N] [--reviews PCT] [--clear]`): herramienta de operador que lee el
  esquema cacheado del formulario y genera envíos FALSOS directamente en
  `submissions_cache` —sin escribir en KoboToolbox—, con fechas repartidas en semanas
  (estadísticas por día/mes/hora y tendencias con forma realista), opciones válidas del
  esquema, geopoints, campos vacíos para los filtros «vacío/no vacío» y revisiones de
  ejemplo. Los envíos sembrados llevan la marca `_km_seed` para poder limpiarlos con
  `--clear` sin tocar los reales. No tiene equivalente en la UI (generar datos falsos
  sobre formularios reales sería un riesgo).
- **`DEMO.md`**: el runbook de la instancia de demostración se traslada de `DEPLOY.md`
  §13 a un documento propio (qué bloquea el flag, orden de instalación, sembrado
  sintético, dump semilla, cron de reset y *hardening*); `DEPLOY.md` §13 queda como
  puntero. Aviso clave: una demo sembrada NO debe llevar cron de sync (lo reconciliaría
  y borraría), solo cron de reset.
- **Instalador CLI** (`php api/cli/install.php`): con `api/config.php` relleno, un solo
  comando verifica los requisitos (PHP 8.1+, extensiones, claves de 64 hex, conexión a
  la BD), aplica el esquema si la base de datos está vacía (con esquema parcial aborta
  pidiendo recrearla), crea el primer administrador (interactivo o `--admin email pass
  nombre`) y sugiere borrar `db/` (`--clean` lo hace; se niega en un checkout de
  desarrollo). Idempotente: re-ejecutarlo no toca un esquema ya instalado. `DEPLOY.md`
  §4 lo ofrece como vía principal (la manual queda como alternativa) y §13 documenta
  además cómo **preparar la semilla de la demo en otra máquina** (compartiendo
  `CONFIG_TOKEN_KEY`) y el aviso del dump MariaDB→MySQL (línea «sandbox»).
- **Credenciales de la demo por rol**: `DEMO_LOGIN_HINT` se sustituye por
  `DEMO_LOGIN_ADMIN` + `DEMO_LOGIN_VIEWER` ('' = línea oculta); el modal pone la
  etiqueta del rol traducida al idioma del visitante («Administrador: …» /
  «Viewer: …»).

- **Modo demo integrado (`DEMO_MODE`)**: nuevas constantes opcionales `DEMO_MODE`,
  `DEMO_RESET_MINUTES` y `DEMO_LOGIN_ADMIN`/`DEMO_LOGIN_VIEWER` en `api/config.php` (con guard `defined()`,
  retrocompatibles: una config sin ellas = demo desactivada) para montar una instancia
  pública de demostración. Con el flag activo:
  - `GET /config` expone `demo_mode`, `demo_reset_minutes` y `demo_login_hint`; el
    frontend muestra un **modal de bienvenida** en cada carga de la portada (con el
    ciclo de reset y las credenciales) y un badge **DEMO** junto a la marca en
    portada/páginas públicas, login, sidebar y barra móvil; el badge es un botón
    que reabre ese modal en cualquier momento.
  - La API **bloquea en un punto central** (403 con el nuevo código `DEMO_LOCKED`,
    i18n es/en) las acciones que romperían la demo o filtrarían secretos: CRUD de
    cuentas Kobo (protege el token), CRUD de usuarios + contraseñas + revocación de
    sesiones (incluidas las propias: el usuario demo es compartido) + recuperación de
    contraseña, ajustes globales, **edición de envíos** (escribe en la cuenta Kobo
    real) y **sync manual** contra Kobo (cuota; los cron del servidor siguen activos).
  - Los botones bloqueados se muestran **deshabilitados con aviso** («No disponible en
    la demo»); todo lo local/restaurable (revisión individual y en lote, filtros,
    export, enlaces compartidos, estadísticas, mapa, idioma, tema…) sigue operativo.
  - Tests de integración HTTP con un servidor propio bajo `DEMO_MODE=true`
    (`DemoModeHttpTest`; `HttpTestCase` ahora soporta una config por clase de test).
  - `DEPLOY.md`: nueva sección **«Running a demo instance»** (config, usuarios demo,
    dump semilla + cron de reset, notas de hardening).

### Cambiado

- **README — sección «Features»**: nueva sección que enumera todas las capacidades de la
  app agrupadas por valor (acceso y permisos, revisión, enlaces públicos, estadísticas y
  mapa, datos, operación), porque antes varias —enlaces públicos, estadísticas, mapa,
  etiquetas, export CSV, adjuntos, notificaciones— no figuraban en el README. La sección de
  modo oscuro se condensó (se solapaba con la nueva lista).

### Arreglado

- **`db/*.sql` instalable en MySQL** (hallazgo del QA de instalación): 003/004/005/007
  arrastraban `ALTER TABLE … ADD COLUMN IF NOT EXISTS`, sintaxis exclusiva de MariaDB
  que MySQL rechaza (#1064). Las seis columnas (forms.deployment_status/schema_json/
  schema_synced_at, users.locale, user_form_permissions.row_filter) viven ahora en los
  `CREATE TABLE` canónicos — coherente con la decisión «sin migraciones
  incrementales» — y los 9 archivos históricos se CONSOLIDAN en dos:
  `db/001_schema.sql` (todas las tablas) y `db/002_defaults.sql` (defaults de
  `settings`, idempotentes). Verificado: BD recreada desde cero = paridad exacta de
  esquema con la BD de desarrollo, PHPUnit 185/185.

- **Login fallido ya no expulsa del formulario**: el interceptor global de 401
  redirigía a `/login` también cuando el 401 era la respuesta del propio intento de
  login (p. ej. desde el modal de la portada); ahora el error se muestra en el
  formulario («Credenciales incorrectas»).

- **Skeleton con aparición retrasada**: los esqueletos de carga aparecen tras ~180 ms con
  un fundido corto, evitando el «flashazo» de skeleton en cargas rápidas.

## [1.3.0] - 2026-06-11

### Añadido

- **Disclaimer de no afiliación**: nota ámbar bajo «Cómo funciona» en la portada (es/en)
  y sección *Disclaimer* en el README — KoboManager es un proyecto independiente, no
  afiliado a, respaldado ni patrocinado por KoboToolbox ni Rakuten Kobo.

### Cambiado

- **Guía de uso rediseñada** (alineada con la página «Apoyar»): titular centrado, tarjetas
  en **gris neutro** y tintes solo en secciones clave — flujo de trabajo y seguridad en
  azul, datos en celeste, y Actualizar/Resync y los enlaces compartidos en verde (este
  último, único con icono). En escritorio, las secciones cortas se emparejan a **dos columnas**
  (Notificaciones+PWA, Contraseñas+Auditoría) para romper la columna larga de texto.
  Variantes para modo oscuro y cuerpo en gris para no fatigar la lectura.

- **Pulido responsive (revisión pre-publicación)**: tanda de mejoras para pantallas
  pequeñas, aplicadas de forma consistente en todos los tamaños.
  - **Cerrar sesión reubicado**: sale del fondo del sidebar (allí queda solo el bloque de
    perfil) y pasa a un icono arriba — junto a la marca en el sidebar (escritorio/drawer)
    y, en móvil, en la barra superior a la izquierda de la hamburguesa. El menú del
    sidebar además hace scroll en pantallas bajas (<568 px) sin tapar el bloque de perfil.
  - **Filtros compactados en una sola fila**: en «Mi actividad», búsqueda + botón
    «Filtros» (con contador) + «Limpiar» (inactivo sin filtros), con acción/formulario/
    fechas en un modal; en la tabla de envíos, búsqueda + **«Vista»** (modal con revisión,
    orden y por página) + «Filtros» — de 3-5 filas de controles a una.
  - **Congelado de columnas en todas las tablas** + nuevo ajuste global «Tablas: columnas
    congeladas» (No congelar / Primera columna, por defecto congelada): envíos, actividad,
    auditoría, usuarios, cuentas, formularios, permisos, enlaces, mensajes y la vista
    pública de enlaces compartidos. En pantallas pequeñas la primera columna se acota al
    ~40 % del ancho visible (con elipsis y tooltip).
  - **Detalle de envío compacto en pantallas estrechas**: «Volver/Anterior/Siguiente» se
    abrevian (←/→) por debajo de 412 px (arriba y abajo), la botonera de revisión cabe en
    una línea («Rechazar» pasa a icono ✕ en <640 px, con tooltip) y los tres botones usan
    **tonos suaves tipo pastel** en ambos temas en lugar de colores sólidos intensos.
  - **Segunda tanda**: en la tabla de envíos, la segunda columna congelada («Enviado»)
    solo se fija a partir de 540 px de ancho (debajo queda fija solo la primera, y las
    columnas congeladas nunca superan ~la mitad de la pantalla); el icono de cerrar
    sesión desaparece del encabezado del sidebar en móvil (ya está en la barra superior);
    el login duplica el logo (regenerado a más resolución) y centra el conjunto en el alto
    *visible* (`100dvh`, también en recuperar/restablecer contraseña); y el «flashazo» al
    cambiar filtros con lista vacía queda eliminado (el esqueleto solo aparece en la
    primera carga real, en envíos, mensajes, auditoría y actividad).

- **Imágenes optimizadas y limpieza de assets**: el banner de la portada pasa de PNG
  (1926×1320, 1,6 MB) a **WebP a 1000 px** (~87 KB; la variante nocturna ~57 KB) y el
  logo de 600×600 (298 KB) a **256×256 PNG cuantizado (~9 KB)** — en total ~3,4 MB menos
  por carga de portada/login, sin pérdida visible a los tamaños de render (448/80 px).
  Eliminados los assets sin uso: `src/assets/hero.png`, `vite.svg`, `vue.svg` (restos del
  scaffold) y `public/favicon.svg`, `public/icons.svg` (el favicon real es `km_logo.png`).

- **Reorganización de los catálogos i18n**: `src/i18n/{es,en}.json` (un fichero monolítico
  por idioma, ~865 claves) se divide en `src/i18n/locales/{es,en}/*.json` — 10 ficheros por
  área (`common`, `landing`, `support`, `guide`, `auth`, `account`, `submissions`, `stats`,
  `admin`, `sharing`), cada uno con namespaces completos de primer nivel, de modo que las
  claves siguen siendo planas por namespace y **ningún `$t(...)` del código cambia**. El
  cargador (`src/i18n/index.js`) fusiona los ficheros con `import.meta.glob` (añadir un
  fichero nuevo no requiere tocarlo). `scripts/check-i18n-parity.mjs` ahora recorre la
  estructura de carpetas y además verifica que ambos locales tengan los mismos ficheros y
  que ningún namespace esté definido dos veces. De paso se eliminan **11 claves huérfanas**
  sin uso en el código (`common.create/account/user/back`, `nav.audit`, `nav.profile`,
  `landing.navDonate`, `landing.soon`, `guide.backHome`, `share.readonly`,
  `attachments.download`): 854 claves en paridad es/en.

### Añadido

- **PWA / soporte de mala conectividad**: KoboManager es ahora una **aplicación web
  progresiva** — instalable desde el navegador (manifest + iconos), con el *shell* de la
  app precacheado (abre al instante incluso sin red) y los GET del API cacheados con
  estrategia *network-first* (timeout 4 s; adjuntos en caché aparte y acotada): lo ya
  consultado (listas, detalle, estadísticas) puede **releerse sin conexión o con el
  servidor caído** — un plugin propio del service worker trata los 5xx como fallo de red
  para cubrir ambos casos. Las escrituras siguen requiriendo conexión y un aviso global
  indica cuándo no la hay. **Privacidad**: al cerrar sesión se borran las cachés de datos
  del dispositivo (el shell se conserva). Service worker propio (`src/sw.js`, modo
  `injectManifest` de `vite-plugin-pwa`, solo en build); sección nueva en la Guía y notas
  de despliegue (`Cache-Control` de `sw.js`) en `DEPLOY.md`.

- **Filtros avanzados en la tabla de envíos**: nuevo botón «Filtros» junto a los filtros
  rápidos que abre el mismo editor de condiciones del scoping por filas (grupos Y/O,
  operadores in/nin/rangos/vacío/conjuntos sobre `select_multiple`, sugerencias de valores).
  El filtro se combina **en AND** con el alcance por filas obligatorio del usuario (solo
  puede restringir, nunca ampliar), se rechaza si referencia campos ocultos (422) y los
  valores sugeridos respetan el alcance del usuario (nuevo endpoint
  `GET /forms/{id}/scope-fields`, la variante para usuarios del de admin). Se recuerda por
  formulario y dispositivo (localStorage `km.filter.<id>`) y **se aplica también al export
  CSV** (exporta exactamente lo que ves); mapa y estadísticas siguen mostrando el alcance
  completo. 3 tests de integración HTTP (PHPUnit 178/178). Verificado contra el form real
  43 (160 → 121 envíos con un `has_any` sobre `select_multiple`; CSV con las mismas 121 filas).

- **Columnas de solo lectura (tercer estado de campo)**: además de ocultar una columna a un
  usuario, ahora puede marcarse como **solo lectura** — la ve pero no puede editarla aunque
  tenga permiso de edición en el formulario. El editor de columnas de Permisos pasa a un
  control de tres estados por campo (Visible / Solo lectura / Oculta); el filtro se guarda
  en el mismo JSON `field_filter` (`{hidden, readonly}`, retrocompatible — una clave oculta
  nunca queda además como solo lectura). El backend rechaza explícitamente (422) cualquier
  edición que toque un campo de solo lectura — nada se escribe a medias en Kobo — y el
  detalle marca esos campos con 🔒 mostrándolos como texto no editable. Los enlaces
  públicos no cambian (ya son de solo lectura; mantienen visible/oculto). Verificado
  además que las **estadísticas agregadas no filtran campos ocultos** («por pregunta»
  excluye la pregunta oculta y sus adjuntos/geo no cuentan), ahora con tests de regresión.
  6 tests nuevos (PHPUnit 175/175).

- **Modo oscuro (claro / oscuro / auto)**: nuevo interruptor de tema (icono sol/luna) en la
  cabecera pública (portada, Guía, Apoyar) y selector en «Mi perfil» («por defecto del sitio»
  / claro / oscuro / auto). «Auto» sigue al sistema (`prefers-color-scheme`); la preferencia
  persiste por dispositivo (localStorage), **siempre gana sobre el tema por defecto** y un
  script inline en `index.html` aplica la clase antes de montar la app (sin destello, también
  con el default del sitio gracias a una caché local). En Configuración el admin dispone de
  **«Tema por defecto»** (claro/oscuro/auto, aplica a quien no haya elegido tema) y de
  **«Mostrar selector de tema»** (al desactivarlo, el botón de la portada y el ajuste del
  perfil se ocultan); ambos viajan en `GET /config`. Implementación: bajo `.dark` solo se
  invierten los **neutros** (`white` + escala `slate`) en `src/style.css`; los tokens de
  marca (`primary`/`accent`/`success`) y los semánticos (rojo/ámbar…) no cambian, así que
  botones y avisos conservan su contraste y el modo oscuro combina con los temas
  `theme-teal`/`theme-violet`; los fondos teñidos claros (pills de la portada, cajas de
  error/éxito/aviso, chips de estado, tarjetas accent de «Mis formularios»/«Apoyar») llevan
  variantes `dark:` apagadas y translúcidas para no deslumbrar; los rojos y naranjas de
  acciones (eliminar, desactivar, revocar) y los botones de peligro también se suavizan en
  oscuro, igual que el badge de rol admin y los badges «Filtro»/«Columnas» de Permisos. Las
  superficies oscuras por diseño (sidebar, drawer móvil) se anclan con `.km-pin-neutrals`;
  los gráficos re-renderizan el texto/rejilla al alternar; `color-scheme: dark` adapta
  inputs nativos y scrollbars. La portada muestra una **variante nocturna del banner** en
  modo oscuro.
- **Skeletons de carga**: nuevo componente `Skeleton.vue` (variantes `table`/`lines`/`cards`)
  que sustituye el texto «Cargando…» en las vistas principales (tabla de envíos, detalle,
  estadísticas, Mis formularios, Mi actividad y las listas de administración). En las tablas
  con filtros (envíos, mensajes, auditoría, actividad) el skeleton solo aparece en la carga
  inicial: al cambiar un filtro la tabla se mantiene (atenuada) en lugar de «parpadear».

- **Bandeja admin de mensajes de contacto (`/admin/messages`)**: los mensajes del formulario
  público de la página «Apoyar» (tabla `contact_messages`) ahora se leen y gestionan desde el
  panel, no solo por email. Lista paginada con filtros por estado y motivo; clic en una fila →
  modal con el mensaje completo (al abrirlo se marca **leído** automáticamente), botón
  **Responder** (mailto con asunto prellenado), **archivar/desarchivar** y **eliminar** con
  confirmación. La tabla gana la columna `status` (`new`/`read`/`archived`, DDL canónico en
  `db/009_contact_messages.sql`). Nueva card «Mensajes» en el Dashboard admin con contador de
  no leídos. La bandeja abre filtrada en **«Nuevo»** por defecto. Archivar y eliminar quedan
  auditados (`contact_message_archive`/`_delete`); el paso a leído no se audita para no
  generar ruido. Endpoints admin `GET /admin/messages` (filtros + `new_count`) y
  `PUT`/`DELETE /admin/messages/{id}`. 4 tests de integración HTTP.

- **Página «Apoyar» (`/apoyar`)**: nueva página pública que reemplaza el enlace «Donar»
  (antes inerte, «Próximamente») por «Apoyar» en el nav y el menú móvil. Reúne: uso libre +
  cómo obtener la app (repo GitHub y Guía), **donaciones** (PayPal y Ko-fi), **servicios**
  (instalación llave en mano, soporte, desarrollos a medida, formación) y un **formulario de
  contacto** con motivo (consulta / contratar / propuesta / organización que la usa). Cada
  mensaje se guarda en la tabla `contact_messages` (fuente de verdad) y se intenta una
  notificación por email best-effort a `CONTACT_TO` con Reply-To del remitente; endpoint
  público `POST /api/v1/public/contact`, rate-limited (5/h por IP). 5 tests de integración HTTP.
- **Promoción de features en la portada**: bajo las 4 tarjetas existentes se añade una sección
  «Y mucho más» que destaca los **enlaces públicos de solo lectura** (con/sin contraseña,
  caducidad y el mismo alcance por filas/columnas que el equipo) como tarjeta principal, y
  presenta el resto de capacidades vendibles como chips: permisos por columna, estadísticas,
  notificaciones por email, etiquetas legibles, mapa/geolocalización, export CSV y edición de
  envíos. Mantiene el lenguaje visual de *pills* verdes (token `accent`), compatible con los
  temas alternativos.
- **Zona horaria de visualización en Estadísticas**: «Actividad por hora» y «Actividad por
  día de la semana» se muestran en hora local en lugar de UTC. Kobo entrega
  `_submission_time` en UTC; ahora se ancla explícitamente como UTC y se convierte a la zona
  configurada en `APP_TIMEZONE` (identificador IANA, por defecto `UTC`), con conversión
  correcta por instante (respeta el horario de verano de cada envío). Bajo cada gráfico se
  indica la zona en lenguaje humano —«Hora de {etiqueta} (UTC±N)»— usando `APP_TIMEZONE_LABEL`.
- **Filtro por cuenta en «Mis formularios»** (`/forms`), igual que en la página admin de
  formularios; se muestra solo si hay 2+ cuentas.
- **Acción «Permisos» en admin/usuarios**: para cada viewer, enlace directo a la página de
  Permisos con ese usuario ya seleccionado.
- **Acción «Formularios» en admin/cuentas**: para cada cuenta, enlace directo a admin/forms
  filtrado por esa cuenta.

### Cambiado

- **Responsive (2.ª pasada)**: las tablas de administración (Usuarios, Cuentas, Enlaces,
  Formularios, Permisos) pasan de `overflow-hidden` (recortaban columnas y no se podían
  desplazar) a desplazamiento horizontal con celdas `whitespace-nowrap` (las columnas ya no se
  aplastan ni pierden contenido). Las barras de filtros de **«Mi actividad»** y de la **tabla de
  envíos** se reorganizan en una rejilla de 2 columnas en móvil (menos filas) y vuelven a una
  sola línea en escritorio. Se corrige además el centrado de **todos los modales** (`Modal.vue`
  pasaba de `grid place-items-center` a contenido que podía exceder el ancho del viewport en
  móvil; ahora usa flex y nunca se sale de la pantalla).
- **Responsive de la tabla de envíos**: en pantallas pequeñas el título del formulario ocupa su
  propia línea (ya no se encoge por los botones) y las acciones (Columnas, Mapa, Estadísticas,
  Exportar) se agrupan en un único menú **«Acciones»** (nunca parten en varias filas); en
  escritorio siguen en línea. La tabla mantiene la **primera columna fija** (checkbox + «Enviado»)
  al desplazar en horizontal, para no perder el ancla de la fila; las celdas dejan de aplastarse
  (`whitespace-nowrap` + truncado con tooltip en valores largos). El selector de columnas se
  muestra como hoja centrada en móvil y anclado a la derecha en escritorio.
- **El logotipo «KoboManager» del backend** (barra lateral y barra superior móvil) ahora enlaza
  al *homepage*.
- **Token de color `success`**: los estados de éxito/aprobado pasan de usar el `green-*` de
  Tailwind directamente a una escala semántica `success` (50–900) en `@theme`, siguiendo la
  convención de `primary`/`accent`. Es **tematizable** (cada tema alternativo puede
  redefinirla; por defecto verde de Tailwind) y distinta de `accent` (que también es verde),
  para que «éxito» no quede atado al color de marca. Se sustituyen las 25 clases `green-*` por
  `success-*` y los verdes fijos de los gráficos («aprobado» / «con ubicación») leen ahora la
  variable CSS del token.
- **Menú lateral admin más corto**: «Auditoría» se mueve del menú a una tarjeta del panel
  (acceso poco frecuente; evita que el menú desborde la pantalla).
- **Estadísticas**: las tarjetas de tendencia (7/30 días) no se muestran en formularios
  *draft*/*archivados* (no se espera actividad reciente).
- **Estadísticas — orden**: «Estado de revisión» pasa delante de «Envíos por mes», y las
  tarjetas de tendencia (7/30 días) bajan justo detrás de la serie temporal a la que se
  refieren; así en pantallas pequeñas (apiladas) quedan inmediatamente tras «Envíos por mes».

### Corregido

- **Zona horaria de `submitted_at`**: al sincronizar, la proyección de `_submission_time` a la
  columna `submitted_at` se anclaba con la zona del servidor PHP; ahora se ancla en UTC (como
  el resto del manejo temporal), para que «por día/mes» y «tendencias» sean correctas también
  en servidores con zona horaria distinta de UTC.
- **Revisión**: el botón del estado actual queda inactivo, evitando re-aplicar el mismo
  estado (que insertaba una revisión duplicada).
- **Gráficos**: el valor mostrado sobre cada porción del donut elige color por contraste,
  legible también sobre las porciones claras («sin adjuntos» / «sin ubicación»).

### Añadido

- **Estadísticas con tendencias**: la serie temporal (por día/mes) añade una línea de
  **total acumulado** (gráfico mixto barra+línea con doble eje), y dos tarjetas de
  **tendencia reciente** — envíos de los últimos 7 y 30 días vs el periodo anterior
  equivalente, con % de variación (▲/▼) y «—» cuando no hay base. Respeta el scoping.
- **Búsqueda por etiqueta legible**: `search_text` indexa ahora, además del código, la
  **etiqueta** de las opciones de `select_one`/`select_multiple` (uniendo todas las
  traducciones del formulario), de modo que buscar «Femenino» casa un envío cuyo valor es
  el código «2». Buscar por código sigue funcionando. Backfill:
  `cli/rebuild_search_text.php`.
- **Ordenar la tabla de envíos por columna calculada**: el orden admite ahora *duración*,
  *nº de adjuntos* y *tiene ubicación* (además de la fecha), expresadas como SQL sobre el
  JSON para que el orden sea **global** (toda la tabla, no solo la página).
- **Historial de edición por envío**: nueva sección en el detalle (para quien puede
  editar) que reconstruye todas las ediciones siguiendo la cadena de `_uuid`
  (`GET /submissions/{id}/history`), mostrando «campo: valor anterior → nuevo» con
  etiquetas legibles. Respeta scoping y campos ocultos.
- **Tests de integración HTTP**: nueva suite (`api/tests/http/`) que arranca la API real
  (`api/index.php`) en un servidor `php -S` efímero y le hace peticiones HTTP de verdad
  (cookies, CSRF, cabeceras, routing del front controller). Cubre el ciclo de
  autenticación/JWT (login, `/auth/me`, logout, rate-limit), la protección CSRF, la
  recuperación de contraseña, la revisión individual y en lote, la lectura con
  permisos + scoping por filas (RowScope) + ocultado por columna (FieldScope), la
  exportación CSV y la **edición** (contra un stub local de Kobo que reproduce el
  contrato del endpoint bulk, incl. el cambio de `_uuid` y los fallos por-envío). El
  servidor de test usa una config aislada (`KM_CONFIG` → `tests/config.http.php`,
  BD `kobomanager_test`). 27 tests HTTP; total de la suite **150 tests**.
- **Integración continua (GitHub Actions, sin Docker)**: workflow `.github/workflows/ci.yml`
  con tres jobs — `lint` (`php -l` + `composer validate`), `frontend`
  (`npm ci` + build + chequeo de paridad i18n) y `phpunit` (instala **MariaDB** con
  `ankane/setup-mariadb`, aplica `db/*.sql` sobre `kobomanager_test` y corre las suites
  unitarias + HTTP). Script reutilizable `scripts/check-i18n-parity.mjs`
  (`npm run i18n:check`).

### Corregido

- **Edición real de envíos contra Kobo**: verificado contra una cuenta real que la
  escritura por `PATCH /data/bulk/` actualiza campos dentro de grupos (`grupo/campo`),
  `select_one` y `select_multiple`, refrescando la caché local y el `search_text` sin
  necesidad de resincronizar. Una edición en Kobo **crea una versión nueva del envío con
  un `_uuid` distinto** (conserva el `_id` numérico): ahora el backend toma ese `_uuid`
  de la respuesta, **migra la clave de caché** (`submissions_cache.submission_uid`) y
  **arrastra el historial de revisiones** (`submission_reviews`) para no perderlo en el
  próximo resync `full`; el detalle del frontend navega al nuevo identificador tras
  guardar.
- **Detección de fallos del endpoint bulk de Kobo**: el endpoint responde `HTTP 200`
  aunque la edición por-envío falle (el detalle viaja en `failures`/`results[].status_code`).
  `KoboClient::editSubmission` ahora inspecciona el cuerpo y lanza error
  (`KOBO_EDIT_FAILED`) en vez de dar la edición por buena.

## [1.2.0] - 2026-06-08

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
