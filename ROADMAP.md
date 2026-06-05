# Roadmap — KoboManager

Estado vivo de lo que falta por hacer e ideas para más adelante. Lo ya entregado se
registra en [`CHANGELOG.md`](./CHANGELOG.md).

> Todas las fases del plan de implementación inicial (0–7) están completadas. Este
> documento recoge lo que queda pendiente de validar y las ampliaciones futuras.

---

## Pendiente de validar con datos reales

Implementado y probado con datos de ejemplo + manejo de errores, pero aún sin verificar
contra una cuenta KoboToolbox real:

- [x] **Sincronización real de formularios** (`getAssets`) con un token válido. *(verificado)*
- [x] **Sincronización real de envíos** → `submissions_cache` *(verificado: 1000 envíos de un formulario real)*.
- [ ] **Edición real de un envío** (escritura en Kobo vía `PATCH .../data/bulk/` y refresco de caché).
  - Confirmar el formato de campos con jerarquía de grupo (`grupo/campo`).
- [ ] **Envío real de email** con Resend (clave `RESEND_API_KEY` + dominio verificado).

## Mejoras a corto plazo (ideas propias)

- [ ] Paginación/orden configurable en la tabla de envíos (hoy: búsqueda + paginado básico).
- [ ] Filtro de envíos por estado de revisión (pending/approved/rejected) en `SubmissionsView`.
- [ ] Al re-sincronizar con un estado más restrictivo, desactivar los formularios que
      dejan de cumplirlo (hoy se quedan como estaban).
- [ ] Acción de **reseteo de contraseña** visible para el propio usuario en su perfil.
- [ ] Indicador global en el panel admin del estado de sincronización de todas las cuentas.
- [ ] Cierre de sesión remoto desde el admin (la tabla `user_sessions` ya lo permite).
- [ ] Tests automatizados del backend (PHPUnit) para auth, permisos y rate limiting.

## Del análisis de la Comunidad de Kobo (ideas a copiar)

Necesidades recurrentes en el foro que reforzarían el hueco que cubre la app:

- [ ] **Scoping por filas**: que un viewer vea solo ciertos envíos (p. ej. los de
      determinados enumeradores o por valor de un campo), no solo por formulario.
- [ ] **Enlaces de solo lectura compartibles** (públicos o con token), útil porque Kobo
      está retirando su «compartir sin login».
- [ ] **Historial de edición visible** por envío en la UI (ya se guarda en `audit_log`).
- [ ] Exportación CSV/Excel y notificaciones por otros canales (ya listadas abajo).

## Optimización y mejora (otras ideas)

### Datos de Kobo (alta prioridad para uso real)

- [ ] **Vista de mapa** (PRÓXIMA TAREA) para preguntas tipo geopoint/geoshape.

### UX

- [ ] **Revisión en lote** (aprobar/rechazar varios envíos a la vez).
- [ ] **Visor de `audit_log`** en el panel admin (quién hizo qué y cuándo).
- [ ] Columnas configurables y filtros avanzados en la tabla de envíos.
- [ ] Modo oscuro y mejores estados de carga/vacío (skeletons).
- [ ] Accesibilidad de modales/drawers: cerrar con ESC, *focus trap* y `aria` (gestión del foco al abrir/cerrar).

### Seguridad y sesiones

- [ ] **Recuperar contraseña** por email (flujo «olvidé mi contraseña» con token temporal).
- [ ] **Sesión deslizante / refresh** y opción «cerrar todas mis sesiones».
- [ ] **Protección CSRF** en peticiones que modifican (verificar `Origin`/token; la cookie
      es `SameSite=Lax`, conviene reforzar).
- [ ] Rotación documentada de `CONFIG_TOKEN_KEY` (re-cifrado de tokens) y copias de seguridad.

### Operación y mantenimiento

- [ ] **Docker / docker-compose** (paridad dev↔prod) y **CI** (lint + build).
- [ ] **Índices/búsqueda**: columnas generadas o full-text para acelerar la búsqueda en
      `submissions_cache` cuando crezca.
- [ ] `/health` ampliado: última ejecución de cada cron y estado de sincronización.

## Ampliaciones futuras (del plan original)

- [ ] **Versión de escritorio** con Tauri (envuelve el mismo frontend Vue).
- [ ] **Webhooks de Kobo** para sincronización en cuasi-tiempo-real (en vez de cron cada 15 min).
- [ ] **Notificaciones por otros canales** (Telegram, Slack, WhatsApp).
- [ ] **Exportación de datos** (CSV, Excel) desde la app.
- [ ] **Permiso `can_delete`** — añadir vía migración cuando exista la funcionalidad de borrado de envíos.
- [ ] **Permisos más granulares** (por grupo de formularios, por período de tiempo).
- [ ] **2FA** — la tabla `user_sessions` ya está preparada para soportarlo.

---

*Cuando una tarea se complete, muévela a `CHANGELOG.md` con su fecha.*
