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
      `v1/public/`, vista pública sin shell. *(Pendiente de M5: rate-limit de los GET
      públicos a nivel de app — hoy solo el `unlock` de contraseña se limita por IP; los GET
      se apoyan en el token + revocación/caducidad y se recomienda throttling en el proxy.)*
- [x] **M2 · Productividad de datos** *(hecho; ver `CHANGELOG`)*: **revisión en lote**
      (aprobar/rechazar varios envíos, `POST /forms/{id}/review`) + **exportación CSV**
      (UTF-8 con BOM, `GET /forms/{id}/export`; XLSX nativo diferido). Respetan permisos y
      scoping por filas.
- [ ] **M3 · Observabilidad/ops**: **visor de `audit_log`** (admin, paginado y con filtros)
      + **`/health` ampliado** (última ejecución de cada cron y estado de sincronización).
- [ ] **M4 · Rendimiento y seguridad** *(puede partirse)*:
  - [ ] **M4a · Índices/búsqueda** en `submissions_cache` (columnas generadas o FULLTEXT;
        hoy la búsqueda es `LIKE` sobre el JSON completo).
  - [ ] **M4b · Seguridad/operación**: **sesión deslizante / refresh** + **«cerrar todas mis
        sesiones»** (autoservicio); **rotación documentada de `CONFIG_TOKEN_KEY`** (re-cifrado
        de tokens vía CLI) y **copias de seguridad**.
- [ ] **M5 · Repaso y fortalecimiento** → tag **1.0.0** (primera pública). Posibles releases
      intermedias (`0.4.0`/`0.5.0`) por hito si se desea.

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
- [ ] Exportación CSV/Excel y notificaciones por otros canales (ya listadas abajo).

## Optimización y mejora (otras ideas)

### UX

- [x] **Revisión en lote** (aprobar/rechazar varios envíos a la vez). *(hecho en M2)*.
- [ ] **Visor de `audit_log`** en el panel admin (quién hizo qué y cuándo).
- [ ] Columnas configurables y filtros avanzados en la tabla de envíos.
- [ ] Modo oscuro y mejores estados de carga/vacío (skeletons).

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
- [ ] `/health` ampliado: última ejecución de cada cron y estado de sincronización.

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
