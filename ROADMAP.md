# Roadmap — KoboManager

Estado vivo de lo que falta por hacer e ideas para más adelante. Lo ya entregado se
registra en [`CHANGELOG.md`](./CHANGELOG.md).

> Todas las fases del plan de implementación inicial (0–7) están completadas. Este
> documento recoge lo que queda pendiente de validar y las ampliaciones futuras.

---

## Prioridad: camino a la primera versión pública (v1)

Lista priorizada acordada (se hace **en este orden**, no en el lugar que ocupa cada idea
más abajo). Cada hito = **una sesión propia** (verificación contra datos reales + commits
por hito + actualización de CHANGELOG/ROADMAP/memoria). Tras M5 se hace un repaso de
fortalecimiento y se etiqueta **1.0.0**. Lo no listado aquí queda para futuras versiones.

- [x] **M1 · Compartir — enlaces de solo lectura** *(hecho; ver `CHANGELOG`)*. Token
      impredecible en la URL + contraseña opcional (política global). Expone lista / detalle
      / mapa (adjuntos se difieren). Caducidad opcional + revocación inmediata. Reutiliza
      `RowScope`. Tabla `share_links` (`db/008_*.sql`), `lib/ShareLink.php`, endpoints
      `v1/public/`, vista pública sin shell. *(El rate-limit de los GET públicos a nivel de app
      se hizo en M5: `ShareLink::throttle()` 240 req/60 s por IP sobre tabla `rate_hits`.)*
- [x] **M2 · Productividad de datos** *(hecho; ver `CHANGELOG`)*: **revisión en lote**
      (aprobar/rechazar varios envíos, `POST /forms/{id}/review`) + **exportación CSV**
      (UTF-8 con BOM, `GET /forms/{id}/export`; XLSX nativo diferido). Respetan permisos y
      scoping por filas.
- [x] **M3 · Observabilidad/ops** *(hecho; ver `CHANGELOG`)*: **visor de `audit_log`**
      (`/admin/audit`, paginado + filtros) + **`/health` ampliado** (secciones `cron`/`sync`
      solo para admin; los crons registran su ejecución con `Settings::recordCronRun`).
- [ ] **Bloque prioritario (antes de M4).** Cuatro mejoras de producto acordadas; se hacen
      **antes de M4** y en este orden (esfuerzo/valor). Son funcionalidad, no la infra de
      rendimiento/seguridad de M4; la única salvedad es la seguridad del proxy público de
      adjuntos (P4), que entronca con M4b/M5 (ver nota).
  - [x] **P1 · Auditoría propia (autoservicio)** *(hecho; ver `CHANGELOG`)*. Checkbox global en
        *Configuración* `audit_self_view_enabled` (**off por defecto**) que habilita a cualquier
        usuario ver **su propio** registro de actividad. Endpoint `GET /audit/me` que **fuerza
        `user_id = usuario actual`** (reutiliza paginación/filtros del visor admin vía
        `Audit::query()`, sin filtro por usuario ni columna «usuario»; acciones del desplegable
        limitadas al propio usuario); entrada de menú «Mi actividad» solo si está activo; 403 si
        el flag está off. Cubre en parte el pendiente «historial de edición visible» de más
        abajo. *(Coste bajo.)*
  - [x] **P2 · Valores «calculados» por envío** *(hecho; ver `CHANGELOG`)*. `lib/Derived.php`
        computa métricas derivadas del payload, reutilizado en detalle, tabla y CSV: acápite
        **«Resumen»** en el detalle (lista completa) + tres columnas opcionales en la tabla
        (duración, tiene adjuntos, tiene geo) integradas en el selector de columnas (grupo
        «Calculadas»). Métricas: duración (`end − start`), nº de adjuntos por tipo, tiene
        geolocalización, % de completitud, retraso de subida, hora/día, enviado por, versión,
        estado de validación Kobo, nº de notas/etiquetas y velocidad. «—» cuando falta
        `start`/`end`; `FormSchema::normalize` registra los campos meta para localizarlos.
        **Pendiente 2.ª fase:** ordenar la tabla por una columna calculada. *(Coste medio.)*
  - [x] **P3 · Estadísticas enriquecidas** *(hecho; ver `CHANGELOG`)*. `stats.php` se amplía en
        una sola pasada (respeta scoping): **distribución por pregunta** (`select_one`, con
        etiquetas y % por opción), **por enumerador** (`_submitted_by`), **duración**
        (media/mediana + histograma, vía `Derived`), **actividad por hora/día**, **adjuntos**
        (% y por tipo), **cobertura geo** y **frescura** (último envío). **Diferido a 2.ª
        fase:** distribución de `select_multiple`; **agregación semana/mes + acumulado** y
        **tendencia** (7/30 días vs periodo anterior). *(Coste medio-alto.)*
  - [x] **P4 · Adjuntos en enlaces compartidos** *(hecho; ver `CHANGELOG`)*. Columna
        `expose_attachments` en `share_links` + **proxy público**
        (`GET /public/share/{token}/submissions/{uid}/attachments/{attId}`, `share_attachment.php`)
        guardado por `ShareLink::requireAccess(token,'attachments')` (+ ticket vía `X-Share-Ticket`
        o `?k=` si hay contraseña), que valida alcance de filas y pertenencia del adjunto; el token
        de Kobo **nunca** sale al navegador. **Solo activable con contraseña** y bajo la política
        global `share_attachments_policy` (`off` | `require_password`, por defecto `off`), que se
        valida al crear **y actúa como *kill-switch* en vivo**. Galería **agrupada por tipo**
        (Imágenes / Audio / Vídeo / Documentos·PDF / Otros) en componente reutilizable
        `AttachmentsGallery.vue` + helper `lib/Attachments.php`, usada en la vista pública y en el
        detalle autenticado. **Nota de seguridad → M4b/M5:** el **rate-limit de los GET públicos**
        sigue pendiente (hoy solo el `unlock` se limita por IP).
- [x] **M4 · Rendimiento y seguridad** *(hecho; ver `CHANGELOG`)*:
  - [x] **M4a · Índices/búsqueda** en `submissions_cache` *(hecho; ver `CHANGELOG`)*. Columna
        `search_text` (proyección de valores, sin claves ni metadatos `_*`) poblada por la app
        + índice `FULLTEXT`; búsqueda por `MATCH … AGAINST` con prefijo (fallback `LIKE` para
        términos < 3 car.), centralizada en `lib/SubmissionSearch`. Backfill
        `cli/rebuild_search_text.php`. *(2.ª fase posible: incluir etiquetas resueltas del
        esquema en el texto buscable.)*
  - [x] **M4b · Seguridad/operación** *(hecho; ver `CHANGELOG`)*: **sesión deslizante / refresh**
        (renueva con la actividad manteniendo el `jti`, con **tope absoluto** desde el login →
        acota una cookie robada; sin cambios de esquema) + **«cerrar las demás sesiones»**
        (autoservicio, `GET/DELETE /profile/sessions`, sección en *Mi perfil*); **rotación de
        `CONFIG_TOKEN_KEY`** (`TokenVault` con clave explícita + `cli/rotate_token_key.php`
        transaccional, procedimiento en `DEPLOY §12`) y **copias de seguridad** documentadas
        (`DEPLOY §11`).
- [~] **M5 · Repaso y fortalecimiento** → tag **1.0.0** (primera pública). **Fortalecimiento
      HECHO y etiquetado `0.4.0`** (ver `CHANGELOG`): auditoría de seguridad del backend (sin
      hallazgos críticos/altos) + cabeceras de seguridad + neutralización CSV + rate-limit de
      enlaces públicos + defensa en profundidad (SSRF en `getAttachment`, `alg` JWT, nginx en
      DEPLOY). **Pendiente para 1.0.0:** la revisión manual exhaustiva del usuario; tras ella se
      etiqueta `1.0.0`.

---

## Pendiente de validar con datos reales

Implementado y probado con datos de ejemplo + manejo de errores, pero aún sin verificar
contra una cuenta KoboToolbox real:

- [x] **Sincronización real de formularios** (`getAssets`) con un token válido. *(verificado)*
- [x] **Sincronización real de envíos** → `submissions_cache` *(verificado: 1000 envíos de un formulario real)*.
- [ ] **Edición real de un envío** (escritura en Kobo vía `PATCH .../data/bulk/` y refresco de caché).
  - Confirmar el formato de campos con jerarquía de grupo (`grupo/campo`).
- [ ] **Envío real de email** con Resend (clave `RESEND_API_KEY` + dominio verificado).

## Del análisis de la Comunidad de Kobo (ideas a copiar)

Necesidades recurrentes en el foro que reforzarían el hueco que cubre la app:

- [x] **Scoping por filas**: que un viewer vea solo ciertos envíos (p. ej. los de
      determinados enumeradores o por valor de un campo), no solo por formulario.
      *(hecho: filtro campo+valores con Y por (usuario, formulario); ver `CHANGELOG`)*.
- [x] **Enlaces de solo lectura compartibles** (públicos o con token), útil porque Kobo
      está retirando su «compartir sin login». *(hecho en M1; ver `CHANGELOG`)*.
- [ ] **Historial de edición visible** por envío en la UI (ya se guarda en `audit_log`).
      *(Se solapa con **P1** del bloque prioritario: la auditoría propia ya da al usuario su
      actividad; queda pendiente la vista por-envío para admins.)*
- [ ] Exportación CSV/Excel y notificaciones por otros canales (ya listadas abajo).

## Optimización y mejora (otras ideas)

### UX

- [x] **Revisión en lote** (aprobar/rechazar varios envíos a la vez). *(hecho en M2)*.
- [x] **Visor de `audit_log`** en el panel admin (quién hizo qué y cuándo). *(hecho en M3)*.
- [ ] Columnas configurables y filtros avanzados en la tabla de envíos. *(Se solapa con **P2**:
      las columnas «calculadas» se añadirán al selector de columnas ya existente.)*
- [ ] Modo oscuro y mejores estados de carga/vacío (skeletons).
- [ ] **Organización de los catálogos i18n** *(candidata, a discutir en su momento)*. Hoy
      todo vive en `src/i18n/{es,en}.json` (convención: cada clave en ambos; check de
      paridad). Valorar separar por *namespace* (p. ej. `guide`) en ficheros propios y/o
      **cargar de forma diferida** el catálogo de la Guía por ruta (vue-i18n
      `setLocaleMessage` + import dinámico), para no empaquetar su prosa en el bundle
      principal. Solo merece la pena si entra un 3.er idioma, si la Guía crece a
      documentación larga, o si el peso de `/guide` importa; entonces adaptar el script de
      paridad para comparar el conjunto fusionado. Reevaluar en M5.

### Seguridad y sesiones

- [ ] **Sesión deslizante / refresh** y opción «cerrar todas mis sesiones» (autoservicio).
- [ ] Rotación documentada de `CONFIG_TOKEN_KEY` (re-cifrado de tokens) y copias de seguridad.

### Operación y mantenimiento

- [ ] **Transporte de correo alternativo (SMTP)**: hoy el envío es solo vía Resend (API HTTP,
      `lib/Mailer.php`). Ofrecer SMTP como alternativa para quien prefiera su propio servidor.
      Implica un cliente SMTP (PHPMailer o SMTP por sockets) — choca con la filosofía «sin
      dependencias»; valorar abstraer un `MailTransport` con back-ends `resend`|`smtp`.
- [ ] **Tests de integración de endpoints** (HTTP): login, CSRF y recuperación de contraseña
      extremo a extremo (la lógica vive en los scripts de `v1/`, hoy solo cubiertos a mano).
- [ ] **CI** (lint + build + PHPUnit). *(Docker queda fuera por ahora: no se usa en el proyecto.)*
- [ ] **Índices/búsqueda**: columnas generadas o full-text para acelerar la búsqueda en
      `submissions_cache` cuando crezca.
- [x] `/health` ampliado: última ejecución de cada cron y estado de sincronización. *(hecho en M3)*.

## Ampliaciones futuras (del plan original)

- [ ] **Versión de escritorio** con Tauri (envuelve el mismo frontend Vue).
- [ ] **Webhooks de Kobo** para sincronización en cuasi-tiempo-real (en vez de cron cada 15 min).
- [ ] **Notificaciones por otros canales** (Telegram, Slack, WhatsApp).
- [x] **Exportación de datos** CSV desde la app *(hecho en M2; Excel/XLSX nativo diferido)*.
- [ ] **Permiso `can_delete`** — añadir vía migración cuando exista la funcionalidad de borrado de envíos.
- [ ] **Permisos más granulares** (por grupo de formularios, por período de tiempo).
- [ ] **2FA** — la tabla `user_sessions` ya está preparada para soportarlo.

---

*Cuando una tarea se complete, muévela a `CHANGELOG.md` con su fecha.*
