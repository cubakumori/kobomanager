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

## Ampliaciones futuras (del plan original)

- [ ] **Versión de escritorio** con Tauri (envuelve el mismo frontend Vue).
- [ ] **Webhooks de Kobo** para sincronización en cuasi-tiempo-real (en vez de cron cada 15 min).
- [ ] **Notificaciones por otros canales** (Telegram, Slack, WhatsApp).
- [ ] **Exportación de datos** (CSV, Excel) desde la app.
- [ ] **Permiso `can_delete`** — añadir vía migración cuando exista la funcionalidad de borrado de envíos.
- [ ] **Permisos más granulares** (por grupo de formularios, por período de tiempo).
- [ ] **Internacionalización** (i18n con Vue I18n).
- [ ] **2FA** — la tabla `user_sessions` ya está preparada para soportarlo.

---

*Cuando una tarea se complete, muévela a `CHANGELOG.md` con su fecha.*
