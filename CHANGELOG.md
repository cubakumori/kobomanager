# Changelog

Todos los cambios notables de KoboManager. El formato sigue
[Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y el versionado
[SemVer](https://semver.org/lang/es/).

## [1.40.0] - 2026-07-20

**Retención y borrado de envíos en la caché local** (minimización de datos), por
formulario. Cierra el frente de seguridad interno junto al 2FA (el cifrado de campos
sensibles queda pospuesto en el ROADMAP).

### Añadido

- **Ventana de retención por formulario** (Ajustes → «Retención de envíos»): con N
  días configurados (vacío = conservar para siempre, el comportamiento de siempre),
  cada sincronización **purga de verdad** de la caché local los envíos más viejos que
  la ventana (`submitted_at`), junto con su historial de revisión y comentarios, y el
  import **deja de re-importarlos**. Pensado para datos de riesgo: reduce lo que una
  incautación del servidor se llevaría.
- **KoboToolbox no se toca nunca**: sigue siendo la fuente de verdad y el archivo
  completo. Al ampliar (o quitar) la ventana, una **sincronización completa** del
  formulario re-importa los datos purgados; el historial local purgado no vuelve (el
  envío re-importado recupera el estado de validación que tenga en Kobo). Lo que el
  import salta por estar fuera de ventana **no** cuenta como «retirado» y la guardia
  anti-vaciado queda intacta.
- **Avisos honestos en la interfaz**: si hay un plan de muestra vigente, Ajustes
  avisa de que la purga descuenta la cuota (la retención encaja con recogida
  continua, no con campañas acotadas); Estadísticas y el panel de Muestra indican
  que sus métricas cubren solo la ventana retenida.
- **CLI `php api/cli/purge_submissions.php [form_id]`** para aplicar la retención al
  momento sin esperar al siguiente ciclo (en operación normal no hace falta).
- La ejecución automática de la purga no se audita (política mecánica, como la purga
  del propio registro de auditoría); lo auditado es **quién fijó la retención** (el
  guardado de Ajustes ya se registra).

### Nota de actualización (esquema)

- Columna nueva `forms.retention_days` (INT UNSIGNED NULL = conservar para siempre;
  registrada en `SchemaCheck`, sin backfill). `php api/cli/migrate.php` la aplica; a
  mano:

  ```sql
  ALTER TABLE forms ADD COLUMN retention_days INT UNSIGNED NULL AFTER sample_denominator;
  ```

## [1.39.0] - 2026-07-20

**Segundo factor (2FA) con TOTP** — primer hito del frente de seguridad: app
autenticadora + códigos de recuperación, login en dos pasos y política global de
obligatoriedad.

### Añadido

- **2FA por usuario (TOTP, RFC 6238)**: cada usuario puede activar el segundo factor
  desde su perfil — código QR (o secreto manual), confirmación con un código válido y
  **8 códigos de recuperación de un solo uso** que se muestran una única vez (en BD
  solo quedan sus hashes). El secreto TOTP se guarda **cifrado con TokenVault** (como
  el token de Kobo); implementación propia sin dependencias (`lib/Totp.php`),
  verificada contra los vectores oficiales de los RFC 4226/6238.
- **Login en dos pasos**: con 2FA activo, las credenciales solas no abren sesión — el
  servidor responde un reto de corta vida (5 min) y un segundo paso
  (`POST /auth/login/totp`) lo canjea junto al código TOTP o un código de
  recuperación. Defensas: rate-limit propio por IP, **anti-replay** (un mismo código
  no vale dos veces) y los intentos fallidos de credenciales solo se limpian al
  completar los dos pasos.
- **Política global «2FA obligatorio»** (Configuración → Seguridad): desactivada
  (cada usuario decide), obligatoria para administradores u obligatoria para todos.
  A quien está obligado y no lo tiene, la API se le corta (403 `TOTP_ENROLL_REQUIRED`)
  salvo para activar su propio 2FA, y la interfaz lo lleva directo a la tarjeta del
  perfil. Guardarraíl anti-cierre: exigirlo requiere que el propio admin que guarda ya
  tenga su 2FA activo.
- **Reset por admin** («perdió el móvil»): en Usuarios, la edición permite restablecer
  el 2FA de una cuenta (borra secreto y códigos; el usuario re-enrola). El listado
  marca con un chip quién tiene 2FA activo. Todo queda en el registro de auditoría
  (activación, desactivación, uso de código de recuperación, reset).
- En el **modo demo**, activar/desactivar el 2FA queda bloqueado (la cuenta demo es
  compartida).

### Nota de actualización (esquema)

- Cuatro columnas nuevas en `users` (NULL = sin 2FA; sin backfill; registradas en
  `SchemaCheck`). `php api/cli/migrate.php` las aplica; a mano:

  ```sql
  ALTER TABLE users ADD COLUMN totp_secret TEXT NULL AFTER ui_prefs;
  ALTER TABLE users ADD COLUMN totp_enabled_at DATETIME NULL AFTER totp_secret;
  ALTER TABLE users ADD COLUMN totp_recovery_codes JSON NULL AFTER totp_enabled_at;
  ALTER TABLE users ADD COLUMN totp_last_step BIGINT UNSIGNED NULL AFTER totp_recovery_codes;
  ```

## [1.38.0] - 2026-07-20

**Agrupación de equipos bajo un «meta-equipo»** (nivel padre opcional: región,
provincia…) en los paneles de Muestra y Estadísticas, con detección asistida del
campo de agrupación.

### Añadido

- **Campo de agrupación de equipos** (`forms.team_group_field`, opcional): un campo del
  envío un nivel POR ENCIMA del equipo (cadena `encuestador → equipo → meta-equipo`,
  cada eslabón muchos-a-uno). Se configura en los ajustes del formulario, debajo del
  desglose por equipo; debe ser monovaluado y **distinto** del campo de equipo y del de
  encuestador (guards mutuamente excluyentes), y se anula solo al quitar el campo de
  equipo. El selector lo llenan **todos** los candidatos de tipo válido: el algoritmo
  propone, no dicta.
- **Toggle «Agrupar equipos» en el panel de Muestra** (preferencia por dispositivo):
  roll-up de **solo presentación** — el plan sigue por equipo (`sample_targets`
  intacto); el objetivo/hecho del meta-equipo es la Σ de sus equipos. Cada equipo se
  asigna al valor **dominante** observado en sus envíos; un equipo sin envíos (o sin
  valor del campo) queda en el cubo **«Sin agrupar»** hasta su primer envío (la
  pertenencia se infiere de los datos, avisado en la propia UI). El modo lineal se
  vuelve un **árbol de dos niveles** (tarjeta de meta-equipo plegable → sus equipos; la
  pleca global colapsa a la vista-resumen de meta-equipos) y los modos tabla / mapa de
  calor / semáforo / barras / resumen conmutan sus filas equipo ⇄ meta-equipo.
- **Toggle «Agrupar equipos» en Estadísticas** (`forms/{id}/stats?group=1`): el desglose
  de dos niveles pasa a **meta-equipo → equipos** con su drill-down (aquí cada envío
  agrega por su propio valor del campo, sin inferencia); el filtro interactivo de
  equipos opera sobre claves de meta-equipo (la selección se resetea al conmutar).
- **Botones de detección** en los ajustes (`GET /admin/forms/{id}/team-group`, permiso
  «Ajustes», solo lectura; lib nueva `TeamGroups`): **«Detectar meta-equipos»** rankea
  los `select_one` visibles por dependencia funcional `equipo → F` (equipos que
  resuelven a un único valor) y grosor (menos valores que equipos) — clic en un
  candidato lo pone en el selector; **«Detectar problemas»** lista por equipo el valor
  dominante y los **conflictos** (envíos repartidos entre >1 valor) como aviso de
  calidad de dato. Con datos insuficientes informan, no bloquean.

### Nota de actualización (esquema)

- Columna nueva `forms.team_group_field` (VARCHAR(255) NULL; registrada en
  `SchemaCheck`, sin backfill). `php api/cli/migrate.php` la aplica; a mano:

  ```sql
  ALTER TABLE forms ADD COLUMN team_group_field VARCHAR(255) NULL AFTER stats_enumerator_field;
  ```

## [1.37.0] - 2026-07-18

Tanda de ajustes pre-release: **corte de revisión y backlog en el panel de muestra**,
**favoritos en «Mis formularios»** (con la vista persistida por usuario), nota del
congelado en móvil y los administradores fuera del selector de Permisos.

### Añadido

- **Panel de muestra — contexto de revisión** (`forms/<id>/sample`): bajo el «Total
  general» aparece el **corte** (fecha/hora de la **última acción de aprobar**, venga
  de KoboManager o del pull de KoboToolbox — en ese caso, cuando el sync la registró;
  se toma del historial `submission_reviews`, no del estado vigente) y el **backlog
  actual**: nº de envíos **pendientes de revisión** y **en espera**. El backlog se
  desglosa además **por equipo** (línea en cada tarjeta del modo lineal, solo si hay
  algo esperando). Todo respeta el scoping por filas: un jefe de equipo ve su corte y
  su backlog. Un equipo con *solo* envíos pendientes también aparece en el panel.
  Matiz honesto: como no se revisa en orden cronológico estricto, «pendientes ahora»
  no equivale exactamente a «llegados después del corte»; son dos datos complementarios.
- **Tarjetas de equipo plegables** (panel de muestra, modo lineal): el encabezado
  pliega/despliega el detalle (celdas, proyección, backlog) con un clic; el nombre,
  el total y la barra de avance quedan siempre visibles. Estado efímero (se resetea
  al recargar) y accesible por teclado. La tarjeta «Total general» gana una **pleca
  global** (abajo a la derecha, solo en modo lineal) que pliega todos los equipos de
  golpe — o los despliega si ya están todos plegados.
- **Favoritos en «Mis formularios»** (`/forms`): estrella en la esquina de cada
  tarjeta para marcar el formulario como favorito (por usuario, guardado en servidor:
  tabla nueva `user_form_favorites` + `PUT /forms/{id}/favorite`, exige `can_view`) y
  botón «Favoritos» junto al filtro de tipo para ver solo los marcados (se ofrece en
  cuanto existe algún favorito). Combina con los filtros de cuenta y tipo.
- **La «vista» de «Mis formularios» se persiste por usuario**: la combinación de
  cuenta + tipo + favoritos se guarda en el servidor (`users.ui_prefs`, vía
  `PUT /profile/prefs` con lista blanca de claves; viaja en `/auth/me` y el login) y
  se restaura al volver — sobrevive al cierre de sesión y sigue al usuario entre
  dispositivos. Es ortogonal al ajuste global «Mis formularios: orden de las
  tarjetas»: aquel ordena, los filtros seleccionan. Si la cuenta o el tipo guardados
  ya no existen, se ignoran con gracia.

### Cambiado

- **Permisos (admin/permissions)**: los **administradores ya no aparecen** en el
  selector de usuario — tienen acceso total y estos permisos no les aplican (misma
  regla que el enlace «Permisos» de admin/usuarios, que ya se ocultaba para admins);
  una nota bajo el selector lo explica. El deep-link `?user=<admin>` cae con gracia
  al estado vacío.
- **Configuración → «Tablas: columnas congeladas»**: nota nueva que documenta el
  comportamiento deliberado en pantallas estrechas (<540 px, de la 2ª tanda
  responsive): si la tabla de envíos incluye la casilla de selección, solo esta queda
  fija, para que lo congelado nunca ocupe media pantalla.
- **Documentación y escaparate sincronizados** (auditoría docs vs. código): la
  **portada** gana dos tarjetas — **«Muestra por equipo»** (el buque insignia de
  1.32–1.36, que no se mencionaba en absoluto; con enlace a su acápite de la guía) y
  **«Búsqueda y filtros que se recuerdan»** — más el chip «Instalable (PWA) con
  lectura sin conexión»; la **guía** añade «Favoritos» a las acciones de «Mis
  formularios» y el congelado de columnas al acápite de columnas; ARCHITECTURE
  documenta `PUT /forms/{id}/favorite`, `PUT /profile/prefs`, `users.ui_prefs` y el
  contexto de revisión del panel de muestra; README suma favoritos/vista persistida
  al bloque «Operation»; DEMO añade el panel de muestra al tour de la demo.

### Corregido

- **La copia de seguridad completa omitía los planes de muestra y los favoritos.** La
  lista de tablas del backup «full» (`DemoSeed::SEEDED_TABLES`, compartida con la semilla
  de la demo) no incluía `sample_targets`, `sample_target_history` (muestreo, desde 1.32)
  ni `user_form_favorites` (favoritos, 1.37), así que un backup/migración de servidor las
  perdía en silencio. Añadidas a la lista; las columnas nuevas ya iban cubiertas (el
  volcado hace `SELECT *`). CONTRIBUTING documenta ahora la regla para no repetirlo.
- **Mensaje de sincronización más claro**: «{n} envío(s) actualizados» pasa a «{n}
  envío(s) sincronizados» (es un *upsert*, no solo cambios) y la nota de bajas «· {n}
  eliminado(s)» a «· {n} retirado(s) (ya no están en KoboToolbox)» — «eliminado» sonaba
  a un borrado propio cuando en realidad son envíos que ya no existen en KoboToolbox.

### Nota de actualización (esquema)

- Tabla nueva `user_form_favorites` (favoritos por usuario: PK `user_id, form_id`,
  FKs con `ON DELETE CASCADE`) y columna nueva `users.ui_prefs` (JSON NULL,
  preferencias de interfaz por usuario). `php api/cli/migrate.php` aplica ambas; sin
  backfill (empezar sin favoritos ni preferencias ya es el estado correcto). A mano:

  ```sql
  ALTER TABLE users ADD COLUMN ui_prefs JSON NULL AFTER locale;
  CREATE TABLE IF NOT EXISTS user_form_favorites (
      user_id         INT UNSIGNED NOT NULL,
      form_id         INT UNSIGNED NOT NULL,
      created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id, form_id),
      CONSTRAINT fk_fav_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_fav_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ```

## [1.36.0] - 2026-07-18

La **paleta de cumplimiento gobierna todo el panel de muestra** y Configuración gana la
pestaña **«Paneles»**. Sin cambio de esquema.

### Cambiado

- **Paleta de cumplimiento en todo el panel** (antes solo mapa de calor, semáforo y
  resumen): también la barra del «Total general», las barras del modo lineal, los
  textos de «cumplido» (✓, sobre-muestra, proyección alcanzada) y el color «hecho» del
  gráfico de barras. Razón de peso: con la paleta **Accesible**, un «Total general» en
  verde anulaba su propósito; con **Monotonal**, el panel quedaba bicolor. Matiz: la
  «Distribución observada» no codifica cumplimiento (no hay objetivos), así que adopta
  el **tono neutro de la familia** de la paleta activa (Monotonal → el color elegido;
  Accesible → azul; Clásica/Suave → el primario, como antes). En Accesible, la barra
  de avance «en curso» es azul claro (no naranja): una barra a medias no está «mal»,
  solo incompleta.
- **Configuración reorganizada**: pestaña nueva **«Paneles»** (alcance de estadísticas,
  desglose/tope de equipos, alcance del QC, atajo de admisibles y Muestras — antes en
  «Tablas y vistas», que quedaba con 11 acápites heterogéneos) y **«Valores
  porcentuales» pasa a «General»** (aplica a toda la app, no solo a tablas). «Tablas y
  vistas» conserva lo que sí es de tablas: congelado, líneas de encabezado, etiquetas,
  truncado y el orden de «Mis formularios». El enlace de Control de calidad a los
  umbrales apunta a la pestaña nueva.

## [1.35.0] - 2026-07-18

Acápite **«Muestras»** en Configuración (reparto rápido ocultable + paleta de
cumplimiento con 4 presets) y editor del plan en dos tarjetas. Sin cambio de esquema
(los ajustes viven en la tabla `settings`).

### Añadido

- **Configuración → Tablas y paneles → «Muestras»** (ajustes globales de la instancia):
  - **«Mostrar el reparto rápido en el editor del plan»** (checkbox, activado por
    defecto): permite ocultar la columna de atajos Uniforme / Según lo recibido — son
    ayuda de escritura, no metodología, y sobran donde el diseño muestral viene de fuera.
  - **«Paleta de cumplimiento»** del panel de muestra (mapa de calor, semáforo y
    doughnut de resumen; los demás modos comunican con cifras): **Clásica** (rojo→verde,
    la actual), **Suave** (pastel), **Accesible** (azul↔naranja, apta para daltonismo) y
    **Monotonal** (un solo color cuya *opacidad* codifica el cumplimiento; color
    elegible, vacío = el primario del tema; texto de contraste por luminancia, también
    en modo oscuro). Presets **curados** (pares fondo/texto fijos) en vez de selectores
    libres, para no romper contraste ni legibilidad. «Fuera de plan» queda fuera de la
    paleta (ámbar fijo): es otra semántica, no un grado. Nuevo
    `composables/samplePalette.js`; la leyenda del semáforo sigue la paleta activa.

### Cambiado

- La página del plan de muestra separa en **dos tarjetas** la selección (campo de
  muestreo, denominador, secundarios) y la matriz «Objetivo por equipo × valor», con el
  guardado único abajo (config y celdas siguen viajando en el mismo PUT).
- ROADMAP: los futuros objetivos por celda para campos secundarios se gobernarán con un
  select global «Objetivos por equipo» (solo campo principal / todos los campos); el
  select no se añade hasta que exista la funcionalidad.

## [1.34.0] - 2026-07-18

Permiso **«Muestra»** por formulario (jerárquico sobre «Ajustes») y **página propia** para
el plan de muestra. **Cambio de esquema** → requiere `php api/cli/migrate.php` al actualizar.

### Añadido

- **Permiso «Muestra»** (`user_form_permissions.can_sample`) en la página de Permisos:
  puede editar el **plan de muestra** del formulario. Es **jerárquico**: implica «Ajustes»
  (quien planifica la muestra configura también el campo de equipo del que depende el
  plan); al marcarlo, la casilla de «Ajustes» queda marcada y deshabilitada, y el servidor
  normaliza la implicación en cada guardado (ningún cliente puede guardar el estado
  incoherente). Un usuario con solo «Ajustes» sigue editando desglose por equipo, QC e
  índice de riesgo, pero recibe 403 en el plan de muestra.
- **Página propia del plan de muestra** (`/admin/forms/{id}/sample-plan`): la matriz sale
  de la página de Ajustes (le robaba el foco y quiere espacio); en su lugar hay una tarjeta
  con el botón **«Configurar la muestra»**, deshabilitado —con el motivo visible— si falta
  el permiso «Muestra» o el campo de equipo. El aviso «no configurada» del panel de
  muestra enlaza ahora directo a la página nueva (y solo para quien tiene «Muestra»).
- **Aviso al cambiar el campo de equipo con un plan vigente** (en Ajustes): los objetivos
  están clavados a los códigos del campo anterior, así que cambiarlo los desalinea todos
  (pasarían a «fuera de plan»). Importa especialmente porque un usuario de solo «Ajustes»
  puede cambiar el campo sin poder editar el plan.

### Nota de actualización (esquema)

- Columna nueva `user_form_permissions.can_sample` (TINYINT(1) DEFAULT 0). `migrate.php`
  la añade y **hereda el permiso a quien ya tenía «Ajustes»** (`can_sample = can_settings`):
  hasta 1.33.x el plan se editaba con «Ajustes» y nadie debe perder esa capacidad al
  actualizar; el admin puede restringirlo después desde Permisos.

## [1.33.1] - 2026-07-18

Mejoras del editor del plan de muestra (Ajustes → «Muestra por equipo»), de la revisión
del usuario.

### Añadido

- Botón **«Borrar objetivos»** junto al de guardar (visible con la matriz, deshabilitado
  si ya está vacía): vacía la matriz y los campos de reparto **en pantalla**, con
  confirmación que indica cuántos objetivos se borran. No guarda nada: el plan vigente
  sigue intacto (y con snapshot en el histórico) hasta pulsar «Guardar el plan de muestra».

### Cambiado

- La columna auxiliar **«Total del equipo» pasa a llamarse «Reparto rápido»** (placeholder
  «total a repartir»): era fácil leerla como «lo hecho» cuando en realidad es una ayuda de
  escritura para repartir un objetivo total entre las celdas de la fila.
- El texto de ayuda de la matriz **reposiciona los atajos de reparto**: los objetivos salen
  del diseño muestral, que se hace **fuera de la app** con los datos que correspondan
  (demografía, marcos, estratos); «Uniforme» y «Según lo recibido» solo ayudan a rellenar,
  no son un criterio muestral.

## [1.33.0] - 2026-07-18

**Selector de tipo de vista** en el panel de muestra por equipo. Solo presentación
(frontend): el endpoint no cambia y no hay cambio de esquema.

### Añadido

- **Selector de vista** en `/forms/{id}/sample` con seis modos; la elección se guarda
  por dispositivo (`localStorage`, como el tema):
  - **Lineal** — el diseño original (tarjeta por equipo con barras y proyección).
  - **Tabla** — filas = equipos, columnas = valores; cada celda `hecho / objetivo` con
    lo que falta (o `+n`/`✓` al superar/cumplir), totales por fila, por columna y general.
  - **Mapa de calor** — la misma tabla con cada celda coloreada por % de cumplimiento
    (rojo → ámbar → verde; el verde final usa el token `success` del tema).
  - **Barras** — gráfico Chart.js de barras agrupadas «hecho vs objetivo» por equipo.
  - **Semáforo** — rejilla `equipo × valor` sin cifras: verde = cumplido, ámbar = en
    ritmo, rojo = atrasado. «Atrasado» es **relativo al avance global del plan**
    (celdas con objetivo, con el hecho acotado a su objetivo para que la sobre-muestra
    de un equipo no haga parecer atrasados a los demás); leyenda con el umbral.
  - **Resumen** — doughnut del **% del plan cumplido** (hecho acotado por celda vs lo
    que falta, misma base que el semáforo) + lista compacta por equipo.
- Las tablas van en contenedor con scroll horizontal en móvil; la cabecera, el total
  general, los avisos y la distribución de campos secundarios se mantienen en todos los
  modos (la proyección por equipo vive solo en el modo lineal).

## [1.32.0] - 2026-07-18

Monitorización de **muestra por equipo**: compara lo *recibido* contra un *plan* de
campaña. Cada equipo debe reunir un número de encuestas por cada valor de un campo de
muestreo (p. ej. rango de edad); el panel muestra hecho/objetivo por equipo × valor,
con proyección de cierre al ritmo actual y distribución observada de campos secundarios.
**Cambio de esquema** → requiere `php api/cli/migrate.php` al actualizar.

### Añadido

- **Panel «Muestra»** por formulario (`/forms/{id}/sample`, requiere `can_view`): barras
  hecho/objetivo por celda `equipo × valor`, total y % por equipo, resaltado de déficit
  y sobre-muestra, sección **«fuera de plan»** (equipos o valores presentes en los datos
  sin objetivo definido, que se cuentan pero no tienen meta) y **proyección de cierre**
  por equipo (ritmo medio desde el primer envío → fecha estimada, etiquetada como
  estimación). Etiquetas legibles del `select_one`, no códigos. Respeta el scoping por
  filas (un jefe de equipo ve el suyo) y el ocultado de columnas.
- **Editor del plan** en los ajustes del formulario (`SamplePlanEditor`): elige el campo
  de muestreo principal (+ dos secundarios opcionales) y el denominador, y rellena la
  matriz **equipo × valor → objetivo**. Para no rellenar celda a celda: un **total por
  equipo** con reparto **uniforme** o **proporcional a lo recibido**, más ajuste fino por
  celda. Se admite un **plan parcial**; guardar sin ningún objetivo pide confirmación (no se
  monitorea) y el panel avisa cuando el plan está vacío. **Línea de cobertura** viva
  (celdas/equipos/total) y **aviso al cambiar el campo de muestreo** (los objetivos del campo
  anterior se descartan del plan vigente y quedan en el histórico). El eje de equipo reutiliza
  el «Campo de equipo» del desglose de estadísticas.
- **Denominador de «hecho»** configurable (`forms.sample_denominator`): *solo aprobados*
  (por defecto) o *aprobados y pendientes* (excluye «en espera» y rechazados).
- **Histórico del plan**: cada guardado archiva un snapshot completo en
  `sample_target_history` en vez de sobrescribir sin rastro, para que una renegociación a
  mitad de campaña no borre lo planificado antes.
- Backend `lib/Sample::compute` (gemelo de `lib/Stats`), endpoints
  `GET /forms/{id}/sample` y `GET|PUT /admin/forms/{id}/sample-plan`, con `SampleTest`
  (lib) y `SamplePlanHttpTest` (integración: permisos, guards y snapshots).

### Restricciones de tipo de campo

- El **campo de muestreo** (principal y secundarios) debe ser de **opción única**
  (`select_one`): sus valores son un conjunto cerrado que forma las columnas del plan y cada
  fila cae en un solo valor. Se valida en el editor y en el backend (422). Los tres campos de
  muestreo deben ser **distintos** entre sí (selectores mutuamente excluyentes + guard en API).
- El **campo de equipo/encuestador** ahora excluye `select_multiple` (un eje de agrupación es
  monovaluado), pero sigue admitiendo texto/metadatos monovaluados; validado en UI y backend.

### Esquema

- Nuevas columnas en `forms`: `sample_field`, `sample_field2`, `sample_field3`,
  `sample_denominator` (default `approved`). Nuevas tablas `sample_targets` (plan vigente,
  única por `form_id, team_value, sample_value`) y `sample_target_history` (snapshots).
  Registradas en `SchemaCheck`; **ejecuta `php api/cli/migrate.php` al actualizar**.
  El plan de muestra queda vacío por defecto (feature desactivada hasta configurarla).

## [1.31.1] - 2026-07-17

Pulido móvil tras una pasada de verificación a 375 px. Sin cambio de esquema.

### Corregido

- **iOS Safari ya no hace zoom al enfocar un campo en el móvil**: los controles de
  formulario usan `text-sm` (14px) y iOS amplía automáticamente al enfocar cualquier
  input < 16px (la página salta). En pantallas de teléfono (`max-width: 640px`) los
  `input`/`select`/`textarea` pasan a 16px; en escritorio se conserva el `text-sm`.
  Regla global en `style.css` (no toca componente por componente).

> **Nota.** La verificación a 375 px del shell y de las superficies nuevas de este ciclo
> (Notificaciones, Configuración → Sincronización, perfil → push, tarjeta de la portada,
> tabla de envíos) no encontró más problemas: el drawer, el colapso de la barra en
> «Acciones», el apilado de rejillas y el scroll lateral **dentro** de la tabla (sin
> desbordar la página) ya funcionaban.

## [1.31.0] - 2026-07-17

Aviso «hay una versión nueva» en la PWA: el usuario decide cuándo cargar el código
recién desplegado, en vez de que el service worker lo cambie en silencio. Sin cambio
de esquema.

### Cambiado

- **La PWA pasa de actualización silenciosa a modo AVISO** (`registerType: 'prompt'`):
  al desplegar un build nuevo, el service worker nuevo queda **en espera** y aparece un
  toast discreto *«Hay una versión nueva de KoboManager»* con **«Recargar»** y **«Ahora
  no»**. «Recargar» aplica el relevo (mensaje `SKIP_WAITING` al SW) y recarga con el
  código nuevo; «Ahora no» oculta el aviso y el SW sigue en espera (se volverá a ofrecer).
  Antes (`autoUpdate` + `skipWaiting` incondicional) el código podía cambiar bajo los pies
  del usuario a mitad de una tarea. Componente nuevo `PwaUpdateToast.vue` (usa
  `virtual:pwa-register/vue`), montado en `App.vue`; el SW (`src/sw.js`) ya no se activa
  solo, sino al recibir `SKIP_WAITING`. Solo afecta al build de producción (el SW está
  desactivado en desarrollo).

## [1.30.1] - 2026-07-17

Robustez de la búsqueda de envíos tras verificar (con medición) que no había un
problema de rendimiento real. Sin cambio de esquema.

### Corregido

- **Búsqueda de la tabla de envíos: se cancela la petición en vuelo** al lanzar otra
  (`AbortController` en `SubmissionsView`). Con el debounce de 300 ms bastaba en la
  práctica, pero si dos cargas se solapaban una respuesta lenta anterior podía **pisar**
  el resultado de una búsqueda más nueva; ahora la más reciente siempre gana y una
  cancelada no toca el estado ni apaga el spinner de la nueva.

> **Nota de la verificación.** Medida contra datos reales (1000 envíos/formulario), la
> búsqueda usa el índice FULLTEXT (`MATCH … AGAINST`, 4–20 ms) y no necesita optimización
> a los volúmenes típicos de Kobo; un FULLTEXT por-formulario no es posible en InnoDB. La
> única mejora incremental pendiente (calidad de resultados, no velocidad) —limpiar el
> ruido de metadatos de `search_text`— queda anotada en el ROADMAP.

## [1.30.0] - 2026-07-17

Notificaciones casi inmediatas, Fase 2 del hito del ROADMAP: los avisos de envíos
nuevos llegan también como **notificación del sistema (Web Push)** sobre la PWA, con
opt-in por dispositivo y la misma frecuencia, scoping y guardarraíles que el email.

> **Nota de actualización (esquema).** Tabla nueva `push_subscriptions` (suscripciones
> push por dispositivo). Ejecuta `php api/cli/migrate.php` tras desplegar (la crea; sin
> ella, `GET /push/subscriptions` y el perfil fallan). Instalaciones nuevas no necesitan
> nada. Para ACTIVAR push además hace falta generar claves VAPID
> (`php api/cli/vapid_keys.php` → 3 constantes en `api/config.php`) y servir por HTTPS;
> sin claves, la app funciona igual y la opción simplemente no aparece.

### Añadido

- **Web Push** (perfil → «Notificaciones push»): cada usuario activa el aviso **por
  dispositivo**; al llegar envíos nuevos, el mismo `Notifier` del cron manda — además
  del email — una notificación del sistema con el recuento agrupado y enlace a la app
  (payload cifrado extremo a extremo; nunca contenido del envío). Lista de dispositivos
  suscritos con baja individual; suscripciones caducadas (404/410 del push service) se
  podan solas y los fallos repetidos también. La marca de agua del aviso avanza si
  **cualquier** canal entregó. Funciona con el navegador cerrado en Android; en
  iPhone/iPad requiere la PWA instalada (iOS 16.4+). En demo, las suscripciones son
  tabla efímera (se vacían en cada reset y no viajan en la semilla).
- **`lib/WebPush` sin dependencias**: implementación propia del protocolo — cifrado del
  payload según **RFC 8291** (ECDH P-256 + HKDF-SHA256 + AES-128-GCM, `aes128gcm`),
  blindada con el **vector de prueba oficial** del RFC, y autenticación **VAPID
  (RFC 8292)** con JWT ES256 vía OpenSSL. Decisión de diseño frente a
  `minishlink/web-push`: el runtime sigue **sin composer/`vendor/`** y el modelo de
  despliegue no cambia. CLI nuevo `api/cli/vapid_keys.php` genera el par de claves.
- **Endpoints `GET/POST/DELETE /push/subscriptions`** (alta/baja por dispositivo, upsert
  por endpoint, solo lo propio) y `push_public_key` en el `GET /config` público (la
  `applicationServerKey` que el navegador necesita; no es secreta). Handlers `push` y
  `notificationclick` en el service worker (tocar el aviso enfoca/abre la app).

## [1.29.0] - 2026-07-17

Notificaciones casi inmediatas de envíos nuevos, Fase 1 del hito del ROADMAP: la
preferencia de aviso por email pasa de binaria («resumen diario sí/no») a **frecuencia
por formulario**, y el cron de sincronización envía avisos agrupados tras cada pasada.

> **Nota de actualización (esquema).** `notification_config` gana dos columnas:
> `frequency VARCHAR(12) NULL` (off|daily|hourly|every_sync; NULL = hereda el default
> global) y `last_notified_at DATETIME NULL` (marca de agua UTC de los avisos casi
> inmediatos). Ejecuta `php api/cli/migrate.php` tras desplegar: añade las columnas y
> **traduce** la preferencia vieja (`daily_summary` 1 → `'daily'`, 0 → `'off'`; la
> columna vieja queda sin uso, no se borra). Sin migración, `GET /notifications` y los
> crons fallan con «Unknown column». Instalaciones nuevas no necesitan nada.

### Añadido

- **Frecuencia de aviso por formulario** (Notificaciones): `sin avisos | diaria | cada
  hora | cada sincronización`. Las dos últimas son los avisos «casi inmediatos»: al
  terminar cada pasada del cron de sync, `lib/Notifier` envía **un email agrupado por
  usuario** («N envíos nuevos en {formulario}», localizado es|en por `users.locale`) con
  lo acumulado desde su marca de agua. Guardarraíles: el conteo aplica el **scoping por
  filas** del usuario; **throttle** anti-inundación (máx. un email/hora en «cada hora»;
  «cada sincronización» va a la cadencia del cron); el aviso es solo **recuento +
  enlace** (nunca contenido del envío); un fallo de envío no avanza la marca (reagrupa
  en la siguiente pasada); al activar una frecuencia viva la marca se ancla a «ahora»
  (no se avisa del histórico). Solo el cron envía: las sync manuales o al iniciar
  sesión no disparan emails. Última corrida visible en Auditoría (cron `notifier`).
- **Frecuencia por defecto global** (Configuración → Sincronización,
  `notifications_default_frequency`): sustituye al binario «notificaciones por
  defecto»; se aplica a los formularios donde el usuario no eligió otra cosa. El valor
  del ajuste anterior se respeta (activado → «diaria») sin tocar la BD.
- **Horario de silencio** global opcional (Configuración → Sincronización): tramo
  `HH:MM`–`HH:MM` en `APP_TIMEZONE` (puede cruzar la medianoche) en el que los avisos
  casi inmediatos no se envían; lo acumulado sale agrupado al terminar. No afecta al
  resumen diario (su hora la fija el crontab).

### Cambiado

- `GET/PUT /notifications` habla en frecuencias (`frequencies: {form_id: freq}`) en vez
  del flag binario; guardar conserva la marca de agua salvo al entrar en una frecuencia
  viva. `GET/PUT /admin/settings` cambia `notifications_default_on` por
  `notifications_default_frequency` + `notifications_quiet`.
- El resumen diario (`cron/daily_summary.php`) selecciona por frecuencia efectiva
  `'daily'` (`COALESCE(nc.frequency, default)`); mismo contenido y hora que antes.

## [1.28.0] - 2026-07-16

Frescura de la caché sin depender del cron ni de que el usuario recuerde actualizar,
y salida ordenada de la guardia anti-vaciado del sync. Sin cambio de esquema.

### Añadido

- **Vaciado confirmado cuando Kobo devuelve 0 envíos**: los barridos de bajas del sync
  tienen desde siempre una *guardia anti-vaciado* (una lista viva VACÍA con caché poblada
  se trata como fallo aguas arriba, no como borrado masivo — nada se borra de oficio).
  Ahora el sync **manual** («Actualizar»/«Resync», en Mis formularios y en
  admin→Formularios) detecta ese caso y levanta un modal: *«KoboToolbox ya no tiene
  envíos… hay N en caché»*, con la opción de mantenerlos o **«Vaciar y sincronizar»**. Al
  confirmar se repite el sync con `confirm_wipe`, que **re-verifica contra Kobo en esa
  misma pasada** antes de vaciar (si mientras tanto llegaron envíos vivos, hace el barrido
  normal) y queda auditado (`cache_wipe`). Los comentarios y el historial de revisión se
  conservan. El **cron nunca confirma**: sin humano, la guardia sigue mandando. Puede
  confirmar quien pudo lanzar ese sync (admin siempre; viewer solo con la acción
  habilitada).
- **Sincronizar al iniciar sesión** (ajuste global `sync_on_login`, Configuración →
  Sincronización, OFF por defecto): tras entrar, la app pone al día **en
  segundo plano** los formularios visibles del usuario que lleven >10 minutos sin
  sincronizar (`POST /forms/sync-stale`), sin bloquear el login. Red de seguridad contra
  datos obsoletos pensada para instalaciones **sin cron** (con cron cada 15 min aporta
  poco). El umbral evita tormentas con logins seguidos, el candado por formulario absorbe
  solapes con el cron, los errores por formulario no abortan la pasada (auditada como
  `sync_stale`) y en modo demo queda bloqueado como toda la sync manual.

## [1.27.1] - 2026-07-14

Correcciones tras las primeras pruebas de 1.27.0 con enlaces compartidos reales.

> **Nota de actualización.** Sin cambio de esquema de BD, pero los esquemas de formulario
> cacheados se re-normalizan solos en el siguiente sync (o al instante con «Sincronizar
> formularios»). Si habías **ocultado columnas** de preguntas *score/rating* o *rank* en
> Permisos o en enlaces compartidos, ejecuta después **una vez**
> `php api/cli/fix_field_filters.php` (admite `--dry-run`): renombra en los filtros
> guardados las claves viejas (`carrt`) a la ruta real del payload (`Q6/carrt`) — con la
> clave vieja el ocultado no surtía efecto.

### Corregido

- **Preguntas *score* («Matriz de valoración/Rating» del builder de Kobo) y *rank***: el
  parser del esquema no las entendía — registraba el grupo como si fuera una pregunta y
  sus filas por el nombre hoja pelado (`carrt`), sin la lista de opciones compartida.
  Ahora `begin_score`/`begin_rank` son grupos y cada fila se registra en su **ruta real
  del payload** (`Q6/carrt`) como `select_one` con la lista compartida
  (`kobo--score-choices`/`kobo--rank-items`) y etiqueta compuesta *«{pregunta} · {fila}»*.
  Efectos: entran en **Estadísticas → por pregunta**, el detalle y el export muestran
  etiqueta y valor legibles («MALA» en vez de «3»), la búsqueda indexa sus etiquetas, el
  grupo ya no contamina el ranking de no-respuesta y — lo importante — **ocultarlas por
  columna vuelve a surtir efecto**: con la clave vieja el ocultado no casaba con el
  payload y el valor se exponía igualmente (también en enlaces públicos). CLI nuevo
  `fix_field_filters.php` para renombrar las claves en filtros ya guardados.

- **El alcance por estado de un enlace ahora se anuncia en la vista pública**: los
  metadatos exponen `status_scope` y, en enlaces «solo aprobados», la lista dice
  *«N envío(s) aprobado(s)»* y una línea bajo el sello de frescura aclara *«Este
  enlace muestra únicamente los envíos aprobados»* (ES/EN).
- **La tarjeta «Total» de las estadísticas públicas respeta el alcance por estado**
  del enlace. En la vista interna ese total es a propósito el conjunto completo
  (las tarjetas de cabecera son el selector de estado), pero en la pública no hay
  selector y mostraba el total sin acotar junto a paneles ya acotados — además de
  revelar cuántos envíos sin aprobar existen en un enlace «solo aprobados».

## [1.27.0] - 2026-07-13

Dos mejoras de visibilidad del estado de revisión.

> **Nota de actualización (esquema).** Tras subir el código, ejecuta **una vez**
> `php api/cli/migrate.php`: añade `share_links.expose_review_summary` (opt-in del
> resumen de revisión por enlace; `DEFAULT 0` = los enlaces existentes no cambian).
> Sin shell, el `ALTER`:
> `ALTER TABLE share_links ADD COLUMN expose_review_summary TINYINT(1) NOT NULL DEFAULT 0 AFTER expose_attachments`.

### Añadido

- **Toggle de alcance en Control de calidad**: la página permite alternar al vuelo entre
  *«Pendientes/En espera»* y *«Todos»* sin tocar el ajuste global `qc_scope`. El backend
  acepta `?scope=all|pending_hold` en `forms/{id}/quality` y en su export (que sigue
  reflejando exactamente lo que se ve en pantalla); el parámetro sustituye el global
  **solo para esa petición**, un valor no reconocido cae al global y no se persiste nada.
  Disponible para cualquiera con `can_view`; el toggle arranca reflejando el ajuste
  global. Sin cambio de esquema.
- **Resumen de revisión en enlaces compartidos** (`expose_review_summary`, opt-in por
  enlace, OFF por defecto — la vista pública sigue ocultando la revisión salvo decisión
  explícita del admin): pestaña *«Revisión»* en el enlace público con los **recuentos
  agregados** por equipo/encuestador y estado (pendiente / en espera / aprobado /
  rechazado), sin ningún dato de los envíos — pensado para que un coordinador de campo
  siga el progreso de la revisión sin cuenta.
  - Endpoint público nuevo `GET /public/share/{token}/review-summary`: reutiliza
    `Quality::compute` con el alcance por filas del enlace (filtro de filas Y equipos,
    vía el parámetro `$extraScope` nuevo, espejo del de `Stats::compute`) y devuelve
    **solo** `review_summary`; micro-caché en disco como el de `share_stats`.
  - Solo disponible si el formulario tiene campo de equipo o encuestador (sin ellos no
    hay agrupación): se fuerza a OFF al crear el enlace y se re-comprueba en vivo en el
    endpoint. El alcance por estado del enlace (`stats_status = 'approved'`) **no**
    recorta el resumen: mostrar el progreso completo es el propósito del opt-in.
  - Casilla «Resumen de revisión» en el editor de enlaces (simple y en lote), visible
    solo con campo de equipo/encuestador configurado.

## [1.26.0] - 2026-07-10

Contrapartida del marcado en lote «en espera»: aprobar en lote las encuestas
**admisibles** pendientes, con más cuidado porque aprobar es terminal.

### Añadido

- **Aprobar en lote los envíos admisibles pendientes**: atajo simétrico al «marcar en
  espera las N no admitidas», para aprobar de una vez las encuestas **pendientes** que
  pasan **todos** los umbrales automáticos de control de calidad (sin ninguna bandera).
  - **Ajuste global** *«Aprobar en lote los admisibles»* (`qc_admit_batch`, Ajustes →
    Tablas): `En la tabla de envíos` (por defecto) · `En Control de calidad, con
    guardarraíles` · `En ambos sitios` · `En ninguno`. Gobierna **solo** este atajo; la
    revisión en lote genérica de la tabla no cambia. Sin cambio de esquema.
  - **Tabla de envíos**: filtro *«Solo admisibles»* combinable con el estado, para que el
    revisor los seleccione y apruebe.
  - **Control de calidad**: botón *«Aprobar las N admisibles»* con confirmación que deja
    claro que solo pasaron los umbrales automáticos (**no** una verificación).
  - **Guardarraíles** (todos en el servidor): solo **pendientes** (nunca en espera /
    aprobado / rechazado); si el **Índice de riesgo** está activo, se **excluyen los
    encuestadores de alto riesgo** (índice ≥ corte de sospecha); respeta `can_validate`,
    el scoping por filas/columnas y el alcance `qc_scope`. La aprobación reutiliza el
    endpoint de revisión en lote existente (`forms/{id}/review`).
  - **Banderas derivadas, no persistidas**: el conjunto admisible se calcula al vuelo con
    el mismo motor de la página de Control de calidad (`Quality::admissiblePendingUids`,
    fuente de verdad única para la tabla y el botón). Las banderas dependen de umbrales
    editables y de señales relativas al cohorte, así que persistirlas sería una vista
    materializada con recálculo en cada sync/edición; se deja derivado a propósito.

## [1.25.1] - 2026-07-09

Retoques de UX tras la revisión previa al release.

### Añadido

- **Índice de riesgo — pista de datos en los ajustes**: al pulsar «Sugerir» (que
  analiza los datos reales del formulario), la sección *Índice de riesgo* muestra la
  **mediana de encuestas por encuestador** y cuántos encuestadores hay, para fijar el
  mínimo con criterio. No rellena ningún campo (es un umbral de juicio, no una magnitud
  física). `GET forms/<id>/quality/suggest` devuelve además `enumerator_median` y
  `enumerators`.
- **Sembrador de demo** (`api/cli/seed_demo.php`): nuevas opciones `--comments PCT` (da a
  ese % de las revisiones un comentario de ejemplo acorde al estado, para poblar el panel
  de Comentarios) y `--risk N` (activa el Índice de riesgo fijando `forms.risk_min_n`). Así
  una demo recién sembrada muestra las features que la landing anuncia sin retoques a mano.

### Cambiado

- **Control de calidad**: la **tendencia semanal** de no admitidas se muestra ahora
  tras «Estado de revisión por equipo/encuestador», junto al marcado en lote y al
  drill-down, de modo que todo lo relativo a las **no admitidas** queda agrupado (antes
  el resumen de estado de revisión —que cuenta todos los envíos— partía ese bloque).
- **Ajustes del formulario**: el enlace superior de vuelta lleva ahora **al formulario
  concreto** (`/forms/<id>/submissions`) en vez de a la lista de formularios.

### Corregido

- **Ajustes del formulario**: al guardar desde el final de la página, la confirmación
  «Ajustes guardados» quedaba fuera de vista (solo salía arriba). Ahora también se
  muestra **junto al botón «Guardar»**.

## [1.25.0] - 2026-07-09

Panel de comentarios de revisión por formulario.

### Añadido

- **Panel de comentarios de revisión** (`forms/<id>/comments`, `GET
  forms/<id>/comments`, `can_view`). Reúne en una sola página los comentarios que ya
  viven en `submission_reviews` —hechos en la app o importados de Kobo— **agrupados
  por equipo → encuestador** (los mismos campos del desglose de estadísticas), con
  **fecha, estado de revisión, autor, texto y enlace directo al envío**. Resuelve el
  no poder saber qué envíos tienen comentario sin abrirlos uno a uno. Filtros por
  **estado** y por **texto** del comentario. Reutiliza `lib/Comments` (gemelo de
  `lib/Quality`): respeta el **scoping por filas/columnas** igual que el resto y solo
  muestra comentarios de envíos visibles del formulario; los enlaces públicos nunca
  lo exponen (vista interna). Sin cambios de esquema. Entrada desde la cabecera de
  Control de calidad.

## [1.24.0] - 2026-07-09

Export del drill-down de infracciones del Control de calidad para llevar a la
reunión con el equipo de campo.

### Añadido

- **Export del drill-down de infracciones** (`GET forms/<id>/quality/export`).
  Descarga la lista de envíos marcados por el Control de calidad —una **fila por
  infracción**— con **equipo, encuestador, UID, enviado, inicio, fin, duración (s),
  hueco (s), banderas y estado de revisión**. Ofrece **CSV** (UTF-8 con BOM,
  neutraliza la inyección de fórmulas) y **.xlsx** nativo (duración y hueco como
  celdas numéricas), igual que el export de envíos. Reutiliza `lib/Quality` tal
  cual, así que respeta el **scoping por filas/columnas**, el **alcance por estado**
  (`qc_scope`) y el **gating del campo de equipo/encuestador** exactamente como la
  página; requiere `can_view`. Botón «Exportar» en la cabecera de Control de calidad
  (solo cuando hay infracciones) con un modal de elección de formato. Cabeceras y
  valores (banderas, estado) en el idioma del usuario.

## [1.23.0] - 2026-07-08

Índice de riesgo por encuestador y equipo (detección heurística de fabricación,
«curbstoning») y resumen de estado de revisión por equipo/encuestador.

> **Nota de actualización (esquema).** Tras subir el código, ejecuta **una vez**
> `php api/cli/migrate.php`: añade `forms.risk_min_n` (interruptor opt-in del índice
> de riesgo; `NULL` = índice desactivado, que es el comportamiento por defecto para
> los formularios existentes). Sin shell, el `ALTER`:
> `ALTER TABLE forms ADD COLUMN risk_min_n INT UNSIGNED NULL DEFAULT NULL AFTER qc_dup_min_answers`.

### Añadido

- **Índice de riesgo por encuestador y equipo** (`forms/<id>/risk`). Detección
  **heurística** de fabricación de encuestas que agrega señales **relativas a los
  pares** en un índice que **prioriza a quién hacer back-check** (re-entrevista de
  verificación). Es **opt-in por formulario** (`forms.risk_min_n`, el N mínimo de
  encuestas por encuestador/equipo para puntuar; vacío = desactivado) y **nunca un
  score opaco**: cada encuestador se despliega en sus componentes con el **valor real,
  la mediana del equipo y una frase en lenguaje llano** de qué significa y su posible
  explicación inocente. Aviso metodológico destacado: **es una señal para priorizar
  verificaciones, no una prueba**, y necesita volumen. Señales de la Fase 1 (sobre la
  caché actual, sin datos extra): **percentmatch** (similitud de respuestas entre los
  envíos del mismo encuestador — la señal principal; muestreada y acotada), tasa de
  **saltos/«no sabe»**, **straight-lining** (baja varianza), **distribución vs. los
  pares** y **vs. el pool de equipos**, **Benford**/preferencia de dígitos en
  numéricos, **productividad** (entrevistas/día) y **agrupación GPS** si hay geo. Cada
  métrica se z-scorea de forma **robusta** (mediana/MAD) frente a los compañeros de
  equipo; el índice de equipo NO es la media de sus miembros, sino «cuántos superan el
  umbral de sospecha + el peor» más su distribución frente al resto de equipos. Las
  métricas se auto-activan según los datos disponibles.
- **Control de calidad: resumen de estado de revisión por equipo → encuestador**
  (nº y %). Cuenta **todos** los envíos por estado (pendiente/en espera/aprobado/
  rechazado) y **no depende del alcance**: un encuestador cuyos envíos ya se aprobaron
  o rechazaron **sigue apareciendo** (a diferencia de la lista de infracciones, que
  solo muestra el alcance por estado configurado).

## [1.22.0] - 2026-07-07

Añadidos de análisis en Estadísticas y Control de calidad.

> **Nota de actualización (esquema).** Tras subir el código, ejecuta **una vez**
> `php api/cli/migrate.php`: añade `forms.qc_dup_min_answers` (sensibilidad de
> duplicados del control de calidad). El `DEFAULT 2` deja la señal con la
> sensibilidad de fábrica en las filas existentes. Sin shell, el `ALTER`:
> `ALTER TABLE forms ADD COLUMN qc_dup_min_answers INT UNSIGNED NULL DEFAULT 2 AFTER qc_min_gap`.
> (El resto de columnas que usan estas mejoras —derivados materializados, estado de
> revisión— llegaron en 1.21.0.)

### Añadido

- **Estadísticas: filtro por rango de fechas.** Selector de periodo (Todo / 7 / 30 /
  90 días / personalizado con fechas «desde–hasta», inclusive, en días naturales UTC)
  que acota todas las métricas —series, por pregunta, equipos, duración, hora/día,
  adjuntos, geo—. El encabezado (total y desglose por estado) y la tendencia 7/30 d
  (que tiene su propia ventana relativa a hoy) siguen siendo globales, igual que con
  el filtro de estado. Un chip muestra el rango aplicado y permite limpiarlo.
- **Estadísticas: preguntas numéricas.** Sección nueva para las preguntas
  `integer`/`decimal`/`range` visibles: media, mediana, mínimo, máximo, nº de
  respuestas e histograma de 8 tramos por pregunta. Los valores no numéricos o
  vacíos no cuentan; respeta permisos por columna.
- **Estadísticas: ranking de no-respuesta.** «Preguntas más saltadas»: top 10 de
  preguntas con más envíos sin respuesta, con barra y % sobre la base filtrada
  (el esquema normalizado ya excluye notas y `calculate`).
- **Control de calidad: señal de duplicados.** Bandera nueva `Duplicadas`: otro envío
  del formulario —de cualquier encuestador— con exactamente las mismas respuestas.
  Compara solo CONTENIDO (excluye los campos de equipo/encuestador, que identifican
  pero no son contenido). Su **sensibilidad es configurable por formulario**
  (`forms.qc_dup_min_answers`): nº mínimo de respuestas de contenido para que un envío
  participe —por defecto 2, para que un envío casi vacío no genere ruido; menor = más
  sensible; 0/vacío = señal desactivada—, junto a los umbrales de duración/
  consecutividad en los ajustes del formulario. La respuesta del control de calidad
  ecoa el valor vigente en `thresholds.dup_min_answers`.
- **Control de calidad: señal «GPS clavado».** Bandera nueva cuando el MISMO punto
  exacto se repite en ≥3 envíos del mismo encuestador (relleno desde un sitio fijo).
  Solo participa lo que tiene coordenadas: sin datos geo la señal queda inactiva (la
  tarjeta ni aparece) y los envíos sin punto nunca forman grupo.
- **Control de calidad: tendencia.** Gráfico «% de encuestas no admitidas por semana»
  (semanas ISO, días UTC) sobre TODO lo recibido: la física de las banderas no depende
  del alcance por estado, así que aprobar un envío no lo borra de la historia — el
  gráfico responde si la calidad mejora tras hablar con el equipo.
- **Control de calidad: sugerencia automática de umbrales.** Botón «Sugerir» en los
  ajustes del formulario que propone la duración mínima/máxima admisible desde los
  percentiles p5/p95 de las duraciones reales (endpoint `GET /forms/{id}/quality/suggest`,
  admin o permiso «Ajustes»; respeta el alcance por filas; con menos de 10 encuestas
  con tiempos no sugiere nada). Solo rellena el formulario: guardar sigue siendo
  decisión del usuario.
- **Estadísticas: tope del desglose por equipo configurable.** Ajuste global
  «Desglose de equipos en estadísticas» (`stats_team_cap`): los 20 primeros, los 50
  primeros (por defecto) o todos los equipos —y encuestadores dentro de cada uno—; el
  resto se agrupa en «otros». Un tope evita un gráfico ilegible con cientos de barras.
  No afecta al Control de calidad, que ya los lista todos.
- **Tabla de envíos: exportar con modal, y a Excel (.xlsx).** El botón «Exportar CSV»
  pasa a «Exportar» y abre un modal para elegir el **alcance** (todos los envíos / solo
  aprobados) y el **formato**: **Excel (.xlsx)** —predeterminado— o CSV. El `.xlsx` se
  genera con un escritor propio y **sin dependencias** (streaming, memoria O(1 fila)) y
  resuelve el clásico «el CSV no separa en columnas»: son columnas reales, sin
  ambigüedad de separador (el CSV es estándar con «,», que Excel en configuración
  regional europea —que espera «;»— puede no separar). El contador bajo el título
  muestra **«N / total»** cuando hay filtro, búsqueda o estado activos, para dimensionar
  el subconjunto sobre el total en alcance.
- **Tabla de envíos: orden por estado de revisión.** Nuevas opciones de orden por
  REVISIÓN (pendientes primero / rechazados primero), posibles ahora que el estado
  está desnormalizado e indexado.
- **Enlace «Ver envíos» configurable.** Ajuste global (`show_view_submissions_link`,
  activado por defecto) para ocultar el enlace «Ver envíos» de las tarjetas de «Mis
  formularios» (la tarjeta entera ya abre los envíos al pulsarla).

### Notas

- El drill-down de infracciones ahora puede listar envíos sin tiempos (duplicados o
  GPS clavado sin `start`/`end`); sus columnas de tiempo muestran «—».
- Las secciones nuevas de Estadísticas llegan también a los enlaces públicos con
  estadísticas expuestas (mismo alcance por filas/columnas del enlace, como
  «Por pregunta»).
- ROADMAP: queda anotado como extensión diferida el export CSV del drill-down de
  infracciones; «straight-lining» y «velocidad imposible entre puntos» permanecen
  como ideas (las variantes fuertes —duplicados exactos y GPS clavado— son las
  entregadas aquí).

## [1.21.0] - 2026-07-07

Barrido integral de código previo a los añadidos: seguridad, robustez del sync,
rendimiento a escala (columnas materializadas + lecturas en streaming) y pulido
del frontend. **Trae cambios de esquema** (ver nota).

> **Nota de actualización (esquema).** Tras subir el código, ejecuta **una vez**
> `php api/cli/migrate.php`: añade las columnas nuevas de `submissions_cache`
> (`review_status`, `kobo_id`, `duration_s`, `att_count`, `has_geo`, con sus índices),
> `audit_log.edit_new_uid` (generada, con índices) y `forms.submission_count`, **y las
> rellena** desde los datos existentes (backfill SQL + recálculo PHP). Sin shell no
> basta con los `ALTER`: usa `php api/cli/doctor.php` para ver las sentencias y ejecuta
> también los backfills que imprime; las columnas derivadas del payload requieren
> `migrate.php` (o un resync completo de cada formulario).

### Seguridad

- **El proxy autenticado de adjuntos aplica el scoping por filas**: comprobaba
  `can_view` y los campos ocultos, pero no `RowScope` — un viewer con filtro de filas
  podía descargar adjuntos de envíos fuera de su alcance conociendo los uids (de alta
  entropía, no enumerables). Misma guarda 404 que el detalle, con test de regresión.
- **Login sin oráculo de tiempo**: con un email inexistente o inactivo se verifica
  contra un hash bcrypt señuelo, para que la latencia no delate si la cuenta existe.
- **Caché de share_stats no localizable**: el archivo temporal usaba un nombre
  predecible; ahora va con HMAC de `JWT_SECRET` y permisos 0600 (hosting compartido).

### Corregido

- **El barrido de bajas del sync ya no puede vaciar la caché**: un 2xx de Kobo con
  cuerpo no-JSON (proxy caído, portal cautivo) se trataba como «cero resultados» y el
  reconcile borraba todos los envíos cacheados; ahora es error (`KOBO_BAD_RESPONSE`),
  los barridos se niegan a borrar con lista viva vacía y caché poblada, y alcanzar el
  tope de paginación aborta la corrida en vez de truncar en silencio.
- **`migrate.php`/`doctor.php` saben de TABLAS**: `contact_messages` (1.3.0) no la
  creaba nadie al actualizar desde ≤1.2.x (500 en el formulario de contacto y
  `/admin/messages`). `SchemaCheck` gana `TABLE_CHECKS` con el CREATE canónico
  vigilado por test, y la nota de 1.3.0 ya no apunta a un SQL borrado.
- **El cron de sync aísla cada cuenta y formulario**: un token ilegible (rotación a
  medias) o un error no-Kobo ya no tumban la corrida entera ni dejan `/health` sin
  registro.
- **Revisión sin duplicados en el historial**: la revisión (individual y en lote) toma
  el mismo lock por formulario que el sync mientras dura el push + escritura local; el
  pull de validación ya no puede colarse en medio e insertar una revisión sintética
  duplicada.
- **Ediciones sin guardar protegidas**: navegar a otro envío (Anterior/Siguiente,
  Volver, atrás del navegador) con una edición a medias pide confirmación antes de
  descartar los cambios.
- **Cambiar de formulario por historial recarga la tabla** (antes podían quedar las
  filas del formulario anterior bajo la URL nueva).
- **El filtro «En espera» funciona en la exportación CSV** (se ignoraba en silencio:
  faltaba `on_hold` en la lista y en las etiquetas del CSV).
- **Zonas horarias residuales**: el resumen diario calcula el día natural en UTC
  (coincide con el gráfico «por día», sea cual sea la TZ del servidor) y la tendencia
  7/30 d compara contra `UTC_TIMESTAMP()` en vez del `NOW()` de la sesión de MySQL.

### Rendimiento

- **Estado de revisión desnormalizado** (`submissions_cache.review_status`, indexado):
  el JOIN «última revisión» —que materializaba todo el log de revisiones en cada
  lectura filtrada, sin acotar por formulario— desaparece de la lista, el export, las
  estadísticas, el control de calidad, los enlaces públicos y el propio sync. Única
  vía de escritura: `ValidationStatus::recordReview` (log + columna, siempre de
  acuerdo).
- **Columnas materializadas del payload** (`kobo_id`, `duration_s`, `att_count`,
  `has_geo`, calculadas por el sync): ordenar por duración/adjuntos/geo y el chequeo
  «¿hay mapa?» dejan de parsear el JSON de todo el formulario en cada vista; el
  barrido de bajas del cron lee la columna indexada.
- **Stats, control de calidad, mapas (interno y público) y export en streaming**
  (`DB::stream`, consulta sin búfer): la memoria por petición deja de crecer con el
  tamaño del formulario (antes, OOM hacia los 20-50k envíos, también en los endpoints
  públicos de enlaces). El export además decodifica cada fila una sola vez, y los
  mapas prefiltran `has_geo = 1` en SQL.
- **Primer sync de un formulario grande**: los envíos se traen y persisten página a
  página (generador) con un prepared statement reutilizado y una transacción por
  página — ni todo el histórico en RAM ni un commit por fila.
- **Proxy de adjuntos en streaming**: el archivo se reenvía según llega de Kobo (un
  vídeo de cientos de MB ya no se carga entero —dos veces— en memoria); los errores se
  siguen resolviendo antes de emitir cabeceras.
- **Historial de edición por índice**: `audit_log.edit_new_uid` (columna generada
  desde `detail.new_uid`, indexada por formulario) — cada eslabón del linaje era un
  escaneo completo del log con `JSON_EXTRACT` por fila.
- **Contador de envíos cacheado** (`forms.submission_count`, refrescado por cada
  sync): el listado de formularios ya no hace un COUNT por formulario en cada carga.
- **Retención y podas**: retención configurable del registro de auditoría (Ajustes →
  Seguridad; 0 = conservar para siempre) con purga oportunista, y poda global de
  `login_attempts` (las IPs que solo fallaban nunca se limpiaban). Índices nuevos de
  `audit_log` para sus filtros reales.

### Frontend

- **Leaflet y Chart.js bajo demanda** (`defineAsyncComponent`): el detalle de envío ya
  no descarga el mapa sin geodatos y un enlace público de solo lista no baja
  mapa+gráficos (~114 KB gz menos). El catálogo EN sale del bundle principal y se
  carga al cambiar de idioma, sin flash de claves.
- **Scroll lock** del documento mientras haya modales o drawers abiertos (contador en
  `dialogA11y`, cubre diálogos anidados).
- **Vista pública alineada con la interna**: celdas con recorte + título, búsqueda con
  debounce (Enter inmediato) y `Skeleton` en vez de «Cargando…».
- **Accesibilidad/i18n**: los `aria-label` de abrir/cerrar menú (públicos y del panel)
  pasan al catálogo (`nav.openMenu`/`nav.closeMenu`); el hero de la landing declara
  `width`/`height` (sin salto de layout) y `fetchpriority="high"`.

## [1.20.0] - 2026-07-06

Escaparate y documentación del tramo 1.14–1.19. Sin cambios de esquema.

> **Nota de actualización acumulada.** Si actualizas desde **1.13.0 o anterior**, este
> tramo trae DOS cambios de esquema (los umbrales del control de calidad en `forms`,
> 1.14.0, y `user_form_permissions.can_settings`, 1.15.0). Basta ejecutar **una vez**
> `php api/cli/migrate.php` tras subir el código — aplica idempotentemente todo lo que
> falte, de ambas versiones. Sin shell, aplica a mano los `ALTER` de las notas de
> 1.14.0 y 1.15.0.

### Añadido

- **La portada promociona el control de calidad**: tarjeta destacada nueva en «Y mucho
  más» («detecta encuestas apresuradas o fabricadas: cortas o largas de más, solapadas
  o con huecos sospechosos entre consecutivas del mismo encuestador; umbrales por
  formulario y "En espera" de un clic»), y la sección pasa a cuadrícula 2×2. La tarjeta
  «Seguimiento por equipo» aclara que los campos de equipo/encuestador los eliges tú, y
  un chip nuevo anuncia la copia de seguridad desde la app (de 1.13.0).

### Cambiado

- **Barrido de documentación del tramo 1.14–1.19**: README (control de calidad y
  permiso «Ajustes» en *Features*, desglose por equipo, backup in-app en *Operation*),
  ARCHITECTURE (ajustes `stats_default_scope`/`qc_scope`/`pct_format` en la lista de
  settings, el token `success` en theming, `migrate.php` con `KM_CONFIG`, denominador
  único del control de calidad), CONTRIBUTING (convención real del CHANGELOG — ya no
  existe «Sin publicar» — y defaults de settings en código), DEMO.md (el control de
  calidad queda habilitado en la demo; su lote «En espera» es local allí), ROADMAP (el
  hito de control de calidad quedó pulido hasta 1.19.0) y la guía in-app (los umbrales
  y el desglose también los configura quien tenga el permiso «Ajustes» del formulario).

## [1.19.0] - 2026-07-06

Formato configurable de porcentajes y rediseño de la tabla del Control de calidad.
Sin cambios de esquema (ajuste en la tabla `settings`).

### Añadido

- **«Valores porcentuales»** (Configuración → Tablas y vistas): cómo mostrar los
  porcentajes de la app — **«Redondeado a entero»** (por defecto) o **«Dos cifras
  decimales»**, estas últimas con el separador del idioma («28,36 %» en español,
  «28.36 %» en inglés). Aplica al Control de calidad y a TODAS las Estadísticas
  (desglose por equipo, por enumerador, tendencia 7/30 d, completitud, resúmenes de
  adjuntos/geo y las etiquetas «valor (p %)» de los gráficos), también en los enlaces
  públicos. El backend deja de pre-redondear a 1 decimal (los % viajan con 4; la
  presentación la decide este ajuste), y el ajuste viaja en `GET /config` (como el
  congelado de columnas) y se cachea en el navegador para pintar bien desde el primer
  render. Con el valor por defecto, las Estadísticas pasan de «81.3 %» a «81 %».

### Cambiado

- **Tabla del Control de calidad rediseñada**: cada encuestador es ahora un bloque
  con su PROPIO encabezado (Encuestador / Envíos / Cortas / …) repetido — con muchas
  infractoras del encuestador anterior, la fila siguiente quedaba «en el aire» — y
  TODOS los valores de ambas tablas (encuestadores e infractoras) quedan alineados a
  la izquierda, incluida la duración y el hueco.

## [1.18.0] - 2026-07-06

Último lote de pulido del Control de calidad. Sin cambios de esquema.

### Añadido

- **«No admitidas / total recibido» en el control de calidad, con denominador único**:
  la cabecera de cada equipo muestra «{no admitidas} / {total recibido} no admitidas»
  y su % — siempre las no admitidas sobre TODAS las encuestas recibidas (del
  formulario, del equipo o del encuestador), para que la fracción y el porcentaje
  cuadren a la vista. La columna «Envíos» de cada encuestador pasa a ser su total
  recibido, su columna «No admitidas» lleva el mismo %, y la tarjeta superior
  «No admitidas» lo calcula sobre el nuevo `received` del backend (todos los envíos
  en el alcance por filas del usuario, sin filtrar por estado de revisión).
- **Enlaces cruzados Estadísticas ⇄ Control de calidad**: en ambas páginas, en la
  línea del enlace «volver» pero al extremo derecho, un enlace lleva a la otra.

### Cambiado

- **`0` en los umbrales equivale a vacío** (comprobación desactivada; se guarda
  `NULL`): antes el `PATCH` lo rechazaba. Las notas de los tres campos quedan
  unificadas — «Vacío o 0 = sin comprobación» / «sin tope», y la de consecutividad
  aclara además que la solapada se marca igual con el hueco desactivado.

## [1.17.0] - 2026-07-06

Pulido de la página de Control de calidad (solo frontend; sin cambios de esquema ni de API).

### Añadido

- **Tasa de no admitidas** como métrica comparativa: porcentaje junto a la tarjeta
  «No admitidas» (sobre las evaluadas), en la cabecera de cada equipo (sobre sus
  encuestas) y en la columna «No admitidas» de cada encuestador. Las comparativas de
  fondo (duración mediana, completitud, volumen por equipo/encuestador) siguen en el
  desglose de Estadísticas, que ya las cubre — aquí solo se añade la métrica propia
  del control de calidad.
- **Retorno con memoria de origen**: el enlace «Ver» de una encuesta infractora abre
  el detalle con `?from=quality`, y allí el enlace «volver» pasa a ser **«← Volver a
  control de calidad»** (los botones Anterior/Siguiente conservan el origen). Desde
  la tabla de envíos todo sigue igual.

### Cambiado

- **El banner del lote explica la resta** que confundía («No admitidas 133» vs botón
  con 35): ahora dice «De las {total} no admitidas, {held} ya están “En espera”» y el
  botón pasa a «Marcar en espera las {n} restantes». Si ninguna está en espera, se
  mantienen los textos originales.

## [1.16.0] - 2026-07-06

Alcance del control de calidad por estado de revisión, para que sus recuentos no
contradigan a las estadísticas. Sin cambios de esquema (ajuste en la tabla `settings`).

### Añadido

- **«Control de calidad: alcance»** (Configuración → Tablas y vistas, debajo del
  alcance por defecto de las estadísticas): decide sobre qué subconjunto de envíos se
  reportan infractores — **«Únicamente pendientes o en espera»** (por defecto: los
  aprobados/rechazados ya pasaron revisión humana y no deben re-marcarse) o **«Todos
  los envíos»**. La página de Control de calidad muestra el alcance activo junto a
  los umbrales (con enlace directo al ajuste para admins), y sus tarjetas
  («evaluadas», «no admitidas», banderas) cuentan SOLO el subconjunto en alcance.

### Cambiado

- La **cadena de consecutividad se calcula siempre sobre todos los envíos** del
  encuestador, esté cual esté el alcance: una pendiente que solapa con una aprobada
  se marca igual (contra el fin real de la aprobada); la aprobada simplemente no se
  lista. Encuestadores/equipos sin envíos en alcance desaparecen de la tabla.

## [1.15.0] - 2026-07-06

> **Nota de actualización (esquema).** Añade una columna a `user_form_permissions`.
> Instalación nueva: nada que hacer (`db/001_schema.sql` ya lo incluye). Sobre una BD
> existente, ejecuta `php api/cli/migrate.php` **o** aplica:
>
> ```sql
> ALTER TABLE user_form_permissions ADD COLUMN can_settings TINYINT(1) DEFAULT 0 AFTER can_validate;
> ```

### Añadido

- **Permiso «Ajustes» por formulario** (`can_settings`, checkbox nuevo en
  /admin/permissions): un usuario no-admin puede editar los ajustes de ESE formulario
  — desglose por equipo y umbrales del control de calidad — sin ser administrador.
  `admin/forms/{id}` (`GET`/`PATCH`) lo acepta; **borrar el formulario sigue siendo
  solo-admin**. Como con editar/validar, marcarlo implica poder ver el formulario
  (la UI y el guardado lo fuerzan). El selector de campos que ve un usuario con
  «Ajustes» respeta sus columnas ocultas (usa el endpoint de campos no-admin).
- **Acceso directo «Ajustes» en la tarjeta del formulario** (Mis formularios), para
  admins y usuarios con el permiso; el enlace «Ajustar umbrales» de la página de
  Control de calidad también aparece para ellos.

### Cambiado

- La ruta `/admin/forms/{id}/settings` deja de exigir rol admin en el router: el
  gate real es la API (403 sin permiso); el enlace «volver» apunta a Mis formularios
  para no-admins.

## [1.14.0] - 2026-07-06

> **Nota de actualización (esquema).** Primera versión con cambio de esquema desde 1.8.0:
> añade los tres umbrales del control de calidad a `forms`. Instalación nueva: nada que
> hacer (`db/001_schema.sql` ya lo incluye). Sobre una BD existente, ejecuta
> `php api/cli/migrate.php` **o** aplica:
>
> ```sql
> ALTER TABLE forms ADD COLUMN qc_min_duration INT UNSIGNED NULL DEFAULT 4 AFTER stats_enumerator_field,
>   ADD COLUMN qc_max_duration INT UNSIGNED NULL DEFAULT NULL AFTER qc_min_duration,
>   ADD COLUMN qc_min_gap INT UNSIGNED NULL DEFAULT 4 AFTER qc_max_duration;
> ```

### Añadido

- **Control de calidad por equipo/encuestador** (`forms/{id}/quality`, permiso de
  lectura): página propia que marca las encuestas fuera de los umbrales admisibles del
  formulario con cuatro banderas — **corta** y **larga** (duración `end − start`, las
  mismas claves meta del orden por duración) y, por consecutividad del MISMO
  encuestador, **hueco corto** (entre el fin de una encuesta y el inicio de la
  siguiente) y **solapada** (hueco negativo, señal de fabricación; se marca SIEMPRE,
  sin umbral configurable). Tabla por equipo → encuestador (el mismo par de campos del
  desglose de estadísticas; sin campo de equipo, un único grupo) con drill-down a cada
  encuesta infractora (inicio/fin en `APP_TIMEZONE`, duración, hueco, banderas, estado
  de revisión y enlace al detalle). Los envíos sin marcas de tiempo válidas cuentan
  aparte («no evaluables») y nunca marcan. Respeta el scoping por filas y el ocultado
  de columnas igual que las estadísticas. Accesos: botón junto al desglose «Por equipo
  → encuestador» de Estadísticas (solo vista interna; los enlaces públicos no lo
  exponen) y en las acciones de la tabla de envíos.
- **Umbrales por formulario** (Ajustes del formulario, junto a los campos de equipo;
  solo admin): «Duración menor admisible» (por defecto 4 min), «Duración mayor
  admisible» (vacío = sin tope) y «Consecutividad menor admisible» (por defecto 4 min).
  Minutos enteros (1–10080) o vacío = comprobación desactivada; la BD es la fuente de
  verdad (columnas de `forms`). El `PATCH` de `admin/forms/{id}` pasa a ser parcial
  (clave ausente = no tocar) y valida que el tope no sea menor que el mínimo.
- **«Marcar en espera las N no admitidas»**: botón de lote sobre el flujo de revisión
  en lote EXISTENTE (atribución = el admin que pulsa, push a Kobo normal, troceado de
  1000 en 1000, sin cambios en `submission_reviews`). Excluye las que ya están «En
  espera» y exige permiso de validación sobre un formulario no archivado.
- El análisis vive en `lib/Quality` (solo lectura, testeado con PHPUnit); la cadena de
  consecutividad ordena por `start` y compara contra el **máximo `end`** visto (una
  encuesta englobada por otra no esconde el solape de la tercera).

### Cambiado

- `cli/migrate.php` respeta `KM_CONFIG` (como el front controller y el instalador),
  para poder migrar también una BD alternativa (p. ej. la de tests).
- Guía in-app: nueva entrada «Control de calidad» en la sección de revisión.

## [1.13.0] - 2026-07-06

Copia de seguridad y restauración de la BD desde la app (Configuración → Base de
datos). Sin cambios de esquema ni constantes nuevas.

### Añadido

- **Copia de seguridad descargable** (`GET /admin/db/export`, solo admin, auditado):
  instantánea SQL solo-datos generada por la app y emitida en streaming, con dos
  alcances — **completa** (las tablas de la semilla de la demo + auditoría + mensajes
  de contacto) y **solo configuración** (portable entre instancias, sin ids). Nunca
  viajan las sesiones, los intentos de login ni los tokens de recuperación (restaurar
  un backup no debe revalidar un token viejo), ni la telemetría de crons.
- **Restauración por subida de archivo** (`POST /admin/db/import`, multipart): el
  alcance se lee de la cabecera del propio archivo y el contenido se valida contra la
  lista blanca de ese alcance ANTES de tocar la BD; la restauración es una única
  transacción (fallo a mitad → rollback) y, en alcance completo, purga las sesiones de
  usuarios que no viajen en el backup (la propia incluida — avisado en la confirmación
  en dos pasos de la UI). Mensajes claros cuando el archivo supera
  `upload_max_filesize`/`post_max_size`.
- **Motor común `lib/DbSnapshot`**: el volcado/restore de la semilla de la demo,
  extraído y compartido con el backup (`DemoSeed` y `DbBackup` son clientes finos).
  Cada consumidor valida su propia cabecera: una semilla no se acepta como backup ni
  viceversa. Ambos endpoints en la denylist de la demo (el export entregaría hashes y
  el token cifrado a cualquier visitante).

### Cambiado

- **La pestaña «Base de datos» de Configuración pasa a ser fija** (antes solo existía
  con `DEMO_SEED_PATH`): aloja Copia de seguridad, Restaurar y — condicional — la
  semilla de la demo. DEPLOY §11 documenta la vía sin shell y los límites de subida.
- El servidor de la API en desarrollo (`npm run dev`) sube sus límites de subida a
  64 MB para poder probar restauraciones.

## [1.12.0] - 2026-07-06

### Añadido

- **Ordenar la tabla de envíos clicando las cabeceras**: todas las columnas son
  ordenables — la fija «Enviado», las calculadas (duración, adjuntos, ubicación) y
  cualquier **columna de datos del formulario** (nuevo `sort=field:<clave>` en la API).
  Primer clic ascendente, segundo descendente, con indicador ▲/▼ y `aria-sort`. El
  orden es **global** (SQL sobre toda la tabla, no sobre la página): las preguntas
  numéricas (`integer`/`decimal`/`range`) ordenan por valor con `CAST` («9» antes que
  «10»), los vacíos van **siempre al final**, y en los `select` se ordena por el valor
  almacenado (código), no por la etiqueta mostrada. Un viewer no puede ordenar por una
  columna que tiene oculta (el orden revelaría sus valores): la API cae al orden por
  fecha sin romper la tabla, igual que ante una vista guardada que referencie un campo
  eliminado. La elección persiste por formulario como el resto de la vista, y el
  desplegable de «Vista» refleja la columna activa.

## [1.11.0] - 2026-07-03

### Cambiado

- **Ajustes organizados en pestañas temáticas**: General / Tablas y vistas /
  Sincronización / Compartir / Seguridad, más una pestaña **Base de datos** que aloja
  la semilla de la demo (y está pensada para futuras herramientas de export/import de
  la BD); mientras ese sea su único contenido, solo existe si `DEMO_SEED_PATH` está
  configurada. La página había crecido a 17 secciones en un único scroll. Pestañas
  accesibles (tablist con navegación por flechas), **deep-link `?tab=`** (enlazable y
  sobrevive a recargas), fila con scroll horizontal en móvil, y botón «Guardar» único:
  el estado es compartido, así que los cambios sin guardar sobreviven al cambio de
  pestaña. Solo frontend — el endpoint de ajustes no cambia.

## [1.10.0] - 2026-07-03

Semilla y reset de la demo gestionados por la app: desaparece todo el SQL manual del
runbook (`mysqldump`, TRUNCATEs de privacidad y cron SQL). Sin cambios de esquema.

### Añadido

- **Ajustes → «Semilla de la demo»** (`POST /admin/demo/seed`, solo admin): genera en
  `DEMO_SEED_PATH` (constante nueva, opcional) una instantánea SQL **solo-datos** de la
  instancia con escritura atómica e instantánea consistente (`lib/DemoSeed`). Las tablas
  privadas (sesiones, IPs, intentos de login, tokens de recuperación, auditoría,
  mensajes de contacto) y la telemetría de crons **nunca** se incluyen — la privacidad
  deja de ser un paso manual. La acción queda en la auditoría y está **bloqueada con la
  demo encendida** (coherente con el bucle de mantenimiento de DEMO.md).
- **Cron `api/cron/demo_reset.php`**: restaura la semilla en **una transacción InnoDB**
  (los visitantes ven el estado anterior hasta el commit; un fallo a mitad hace rollback
  y la demo nunca queda a medias). Se programa **cada minuto** y se auto-regula con
  `DEMO_RESET_MINUTES` (que deja de ser informativo: gobierna el ciclo real, así el
  diálogo de bienvenida nunca miente); `--force` restaura al momento. El reset vacía el
  rastro de visitantes (auditoría, intentos, rate-limit, tokens), **conserva las
  sesiones vivas** (ya no desloguea a los visitantes a mitad de clic) y **conserva los
  mensajes de contacto** (los mensajes reales sobreviven a los ciclos hasta que el
  operador los lea). Guardas: se niega con `DEMO_MODE` apagado (un crontab olvidado ya
  no puede machacar una instancia real), valida la cabecera y el contenido de la
  semilla (solo INSERT en tablas de semilla) y registra cada ejecución para `/health`.
- **`lib/SqlScript`**: el splitter de sentencias SQL del instalador, extraído a una lib
  compartida (la usan `cli/install.php` y `lib/DemoSeed`).

### Cambiado

- **DEMO.md / DEPLOY §13**: el runbook de la demo ya no contiene SQL manual; la semilla
  se genera desde la app y el cron de reset es un script PHP como los de sync. La
  semilla generada es SQL plano portable (MySQL 5.7+/MariaDB): desaparece también la
  advertencia de la línea «sandbox» de `mysqldump` de MariaDB.

## [1.9.0] - 2026-07-02

Lote de robustez y pulido de la revisión general de julio (sin cambios de esquema).

### Añadido

- **Estados vacíos orientativos** (componente `EmptyState`). Las tablas clave dejan de
  mostrar una línea gris al estar vacías y dicen qué hacer a continuación: **Cuentas
  Kobo** (CTA «Nueva cuenta» + enlace a «¿Dónde está mi API token?»), **Formularios**
  (distingue «sin cuentas conectadas» → ir a Cuentas de «sin formularios» → botón
  Sincronizar), **Mis formularios** (viewer: pedir acceso; admin: atajo a Formularios) y
  **Compartir** (qué es un enlace público + CTA).
- **Dashboard: tarjeta «Primeros pasos»** para el administrador de una instancia recién
  instalada (sin ninguna cuenta Kobo): los 3 pasos de arranque numerados —conectar
  cuenta, sincronizar formularios, crear usuarios— con acceso directo y enlace a
  «Acerca de Kobo».
- **La tabla de envíos recuerda la vista**: el orden y el filtro de revisión (por
  formulario) y el tamaño de página (global) persisten en el navegador, igual que ya
  hacían el filtro avanzado y las columnas visibles. Navegar al detalle y volver ya no
  resetea nada.

### Cambiado

- **Estadísticas públicas de enlaces compartidos con micro-caché** (TTL 5 min, un
  archivo por enlace; cada sincronización la invalida sola): un enlace popular ya no
  recalcula todas las métricas en cada visita anónima. La vista interna autenticada
  sigue calculando en vivo.
- **Modo demo: la bandeja de mensajes de contacto pasa a solo lectura** (se bloquean
  marcar/archivar/borrar): la demo pública puede recibir mensajes reales de visitantes
  interesados y un anónimo no debe poder borrarlos antes de que el operador los lea.
- **Notificaciones: descripción precisa del resumen diario** — qué contiene (recuento
  del día anterior por formulario), cuándo llega (una vez al día, solo si hubo envíos,
  respetando el filtro por filas) y a qué dirección se envía.
- **Accesibilidad**: el selector de columnas y el menú «Acciones» (móvil) de la tabla de
  envíos se comportan como diálogos (Escape cierra, focus trap, el foco vuelve al botón
  de origen); `aria-label` en los botones solo-icono («×» de limpiar filtros, ‹/› de la
  paginación de mensajes); las imágenes de la galería de adjuntos cargan en diferido
  (`loading="lazy"`).

### Corregido

- **Sincronizaciones solapadas del mismo formulario** (un cron retrasado + el siguiente,
  o el cron + un «Actualizar» manual) podían duplicar revisiones sintéticas de Kobo en el
  historial: `SubmissionSync::syncForm` adquiere ahora un lock por formulario (`GET_LOCK`
  de MySQL, sin espera) y el segundo proceso se retira limpiamente (código nuevo
  `SYNC_IN_PROGRESS`, 409; el cron lo registra como `[SKIP]`, no como error).
- **Roles sin traducir**: «admin»/«viewer» aparecían en crudo en el alta/edición de
  usuarios, la tabla de usuarios y el «Sesión iniciada como…» del Dashboard; ahora usan
  `common.roleAdmin`/`roleViewer` (es/en).

## [1.8.0] - 2026-07-01

### Añadido

- **Creación de enlaces en lote (Compartir).** Un botón «Crear enlaces (lote)» abre un modal
  gemelo al de un enlace nuevo con un **campo distintivo** (obligatorio) y un **prefijo de nombre
  interno** (opcional). Elegido un campo de **opción única (`select_one`)**, se crea **un enlace por
  cada valor** marcado: cada uno queda fijado a `campo = valor` en su filtro de filas (combinado en
  **Y** con el filtro base, del que se excluye el campo distintivo), y su nombre interno es
  `prefijo + etiqueta` (p. ej. «ODS Pinar del Río»). La checklist de valores muestra el **nº de
  envíos** de cada opción; tope de **50 enlaces** por acción; todo se crea en una sola transacción.
  Reutiliza el motor de scoping (`RowScope`) y los ajustes del enlace simple (qué expone, columnas,
  equipos, estado, contraseña, caducidad).

## [1.7.2] - 2026-06-29

### Añadido

- **Versión en el footer de las páginas públicas.** El pie muestra «KoboManager v{x.y.z} —
  …» con la versión del build (inyectada por Vite desde `package.json` como `__APP_VERSION__`),
  para reconocer de un vistazo si una instancia está desactualizada frente a la última de GitHub.

## [1.7.1] - 2026-06-29

### Añadido

- **Red de seguridad para actualizaciones de esquema.** Como las actualizaciones de BD se
  aplican a mano (sin migraciones por archivo, por diseño), desplegar código nuevo sobre una
  BD sin migrar fallaba con un 500 críptico (le pasó a una instancia real al subir a 1.7.0).
  Ahora:
  - `php api/cli/migrate.php` aplica de forma **idempotente** solo las columnas que el código
    espera y faltan (nunca borra ni reescribe datos) — pensado para correrlo en cada deploy;
    `php api/cli/doctor.php` informa lo mismo sin tocar nada (código de salida 1 si hay desfase).
  - Un **banner solo-admin «Base de datos desactualizada»** avisa en la app (con las columnas
    que faltan y qué ejecutar) en vez de esperar a un error opaco. Fuente única declarativa
    `lib/SchemaCheck`.

### Cambiado

- **El Service Worker ya no enmascara los errores 5xx de la API como fallo de red:** el error
  real llega a la app (antes, sobre una BD sin migrar, un 500 se veía como un `no-response` /
  `ERR_FAILED` opaco). Sin conexión o por timeout se sigue sirviendo lo último visto.
- **Los errores de columna/tabla inexistente** (SQLSTATE 42S22/42S02, típicos de un deploy sin
  migrar) devuelven un código claro `DB_SCHEMA_OUTDATED` y dejan de filtrar el SQL crudo al cliente.

## [1.7.0] - 2026-06-25

> **Nota de actualización (esquema).** Esta versión sincroniza el estado de validación
> con Kobo y cambia tres cosas del esquema: `submission_reviews.user_id` pasa a admitir
> `NULL`, se añade `submission_reviews.source` (`'app'`/`'kobo'`) y `submissions_cache.kobo_validation_seen`.
> Instalación nueva: nada que hacer (`db/001_schema.sql` ya lo incluye). Sobre una BD
> existente, recrea desde `db/*.sql` **o** aplica:
>
> ```sql
> ALTER TABLE submission_reviews MODIFY user_id INT UNSIGNED NULL,
>   ADD COLUMN source ENUM('app','kobo') NOT NULL DEFAULT 'app' AFTER user_id;
> ALTER TABLE submissions_cache ADD COLUMN kobo_validation_seen VARCHAR(40) NULL AFTER json_payload;
> ```
>
> También añade el **alcance fijo de los enlaces compartidos** (dos columnas en `share_links`):
>
> ```sql
> ALTER TABLE share_links ADD COLUMN team_filter JSON NULL AFTER field_filter,
>   ADD COLUMN stats_status VARCHAR(16) NULL AFTER team_filter;
> ```

### Añadido

- **Estadísticas filtrables por estado de revisión.** En `forms/{id}/stats` las tarjetas
  del encabezado (Total, Pendientes, Aprobados, En espera, Rechazados) son ahora botones:
  al pulsar una, **todas** las métricas (series por día/mes, tendencia 7/30 d, por pregunta,
  duración, actividad por hora/día, adjuntos, geo y desglose por equipo) se recalculan solo
  para ese subconjunto. El encabezado sigue mostrando siempre el recuento completo para poder
  cambiar de filtro. Backend: `Stats::compute` acepta un filtro de estado que restringe el
  conjunto manteniendo `total`/`by_status` completos y exponiendo `base` (nº del subconjunto)
  como denominador; el endpoint lo lee de `?status=`.
- **Ajuste global «Estadísticas: alcance por defecto»** (Configuración): decide qué
  subconjunto se muestra al abrir las estadísticas, entre **Todos los envíos** y
  **Solo aprobados** (por defecto: aprobados, lo más habitual). El usuario puede cambiarlo
  en la propia página con un clic.
- **Alcance fijo de los enlaces compartidos (al crear en `/admin/shares`).** Dos restricciones
  que se aplican a **todo** el enlace (lista, mapa, detalle, adjuntos y estadísticas):
  - **Por estado de revisión**: «Todos» o «Solo aprobados». Un enlace «de lo validado» nunca
    revela envíos no aprobados (en ninguna vista) y sigue sin exponer el flujo de revisión interno.
  - **Por equipo(s)**: se eligen los equipos incluidos (valores del campo de equipo del formulario);
    viajan como una condición de fila combinada en AND con el filtro de filas existente.
  Detrás: el filtro por estado se desacopla de la exposición del desglose interno; helpers
  compartidos `RowScope::teamRule` y `ValidationStatus::latestFilterSql`; dos columnas nuevas
  en `share_links` (`team_filter`, `stats_status`).
- **Filtrado de estadísticas por equipo.** En el desglose «Por equipo → encuestador» cada
  equipo lleva ahora un interruptor (toggle, activados por defecto): al desactivar uno, sus envíos se
  **restan** de todas las métricas agregadas (series, tendencia, por pregunta, duración,
  actividad, adjuntos/geo). Las barras de equipo se mantienen completas y con su cuota
  estable, para poder marcar/desmarcar; un enlace «Mostrar todos» revierte. Compone con el
  filtro por estado de revisión. Reutiliza el motor `RowScope` (misma semántica SQL≡PHP).

### Cambiado

- **Estadísticas: las tarjetas «Attachments» y «Geographic coverage» solo se muestran si el
  subconjunto presentado tiene alguno.** Un formulario sin adjuntos o sin ubicación ya no
  genera una tarjeta con un gráfico vacío.

- **Sincronización bidireccional del estado de validación con Kobo.** El flujo de
  revisión interno (pendiente / aprobado / en espera / rechazado) deja de estar
  desacoplado: ahora se sincroniza con el campo nativo `_validation_status` de Kobo
  en ambos sentidos.
  - **Push** (al aprobar/rechazar/poner en espera, individual o en lote): se escribe en
    Kobo de forma **bloqueante** (como la edición). Si Kobo lo rechaza, la revisión no se
    guarda y ambos lados quedan idénticos. Requiere que el token tenga permiso *Validate
    Submissions* sobre el formulario.
  - **Pull** (en cada sync): un cambio hecho directamente en Kobo se refleja en el
    historial interno como una entrada de origen **«Kobo»**. En conflicto, **gana Kobo**.
  - En **modo demo** el push se omite (la revisión sigue siendo local; la cuenta Kobo real
    no se toca).
  - Si la cuenta de Kobo no tiene el permiso *Validate Submissions* sobre el formulario
    (típico en formularios **compartidos**), el push falla con un error claro
    (`KOBO_VALIDATE_FORBIDDEN`) en vez de confundirse con «token inválido»; en los
    formularios propios el permiso es automático.
- **Orden configurable de «Mis formularios»** — nuevo ajuste global (Admin → Ajustes)
  para ordenar las tarjetas: cuenta + nombre (por defecto), nombre, últimos sincronizados
  primero, o añadidos más recientes primero.

### Cambiado

- **La edición de envíos solo permite respuestas a preguntas.** Antes el backend solo
  vetaba los campos `_…`; ahora se bloquean también los metadatos que no son preguntas
  (`meta/…`, `formhub/…`, `__version__`, `start`/`end`/`today`/`deviceid`/`calculate`…),
  tanto en la validación del backend como en la interfaz de edición (siguen visibles en
  modo lectura, pero ya no se ofrecen para editar).

### Corregido

- **«Mis formularios»: el indicador de progreso de «Actualizar»/«Resync».** Al pulsar uno,
  ambos botones mostraban su estado «…ando», dando la impresión de que se lanzaban los dos;
  ahora solo el botón pulsado muestra su progreso (el comportamiento de sincronización no
  cambia: siguen siendo dos operaciones distintas, incremental y completa).

## [1.6.0] - 2026-06-24

> **Nota de actualización (esquema).** Esta versión añade dos columnas a `forms`
> (`stats_team_field`, `stats_enumerator_field`). Instalación nueva: nada que hacer
> (el `db/001_schema.sql` ya las incluye). Sobre una BD existente, recrea desde
> `db/*.sql`. No hay migraciones incrementales por diseño.

### Añadido

- **Estadísticas «por equipo → encuestador»** — desglose de dos niveles de las
  estadísticas. Por formulario, un admin designa el campo del envío que identifica
  el **equipo** y, opcionalmente, el del **encuestador** (si no, se usa el usuario
  Kobo `_submitted_by`), desde una pantalla de **ajustes por formulario** nueva
  (acceso «Ajustes» en Admin → Formularios). La página de Estadísticas muestra una
  sección plegable por equipo con, para cada equipo y cada encuestador dentro de él:
  **volumen** (nº y %), **duración** mediana, **completitud** media, **última
  actividad** y la **mezcla de estado de revisión** (aprobado/rechazado/en
  espera/pendiente). Reutiliza la pasada única en alcance de `lib/Stats`
  (`RowScope` + `FieldScope` + `Derived`), de modo que un jefe de equipo con filtro
  por filas ve solo su equipo, y un campo de equipo oculto por columnas desactiva el
  desglose para ese usuario. Disponible también en los enlaces públicos con
  `expose_stats` (con volumen y calidad, pero **sin** la mezcla de revisión,
  coherente con omitir el estado de revisión interno).

### Cambiado

- **Portada: jerarquía de features** — «Seguimiento por equipo» y «Permisos por
  columna» pasan de chip a **tarjeta destacada** en la sección «Y mucho más» (junto
  a los enlaces públicos), en un grid responsive de tres columnas. La Guía in-app
  documenta el desglose por equipo → encuestador.

## [1.5.0] - 2026-06-24

> **Nota de actualización (esquema).** Esta versión añade una columna a `share_links`.
> Instalación nueva: nada que hacer (el `db/001_schema.sql` ya la incluye). Si actualizas
> desde 1.4.x sobre una BD existente, aplica una vez:
> `ALTER TABLE share_links ADD COLUMN expose_stats TINYINT(1) NOT NULL DEFAULT 0 AFTER expose_map;`
> (o recrea la BD desde `db/*.sql`). No hay migraciones incrementales por diseño.

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

### Arreglado

- **Instalador CLI**: detecta «ya instalado» consultando `information_schema` **antes**
  de exigir los `db/*.sql`, de modo que ya no aborta cuando la BD tiene las 15 tablas
  pero la carpeta `db/` no está en el servidor (esquema aplicado a mano por SSH/phpMyAdmin).

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
  confirmación. La tabla gana la columna `status` (`new`/`read`/`archived`; el DDL canónico
  vive hoy en `db/001_schema.sql` tras la consolidación, y `php api/cli/migrate.php` crea la
  tabla si falta). Nueva card «Mensajes» en el Dashboard admin con contador de
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
