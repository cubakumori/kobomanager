# Roadmap — KoboManager

Estado vivo de lo que falta por hacer e ideas para más adelante. Lo ya entregado se
registra en [`CHANGELOG.md`](./CHANGELOG.md).

> Todas las fases del plan inicial (0–7) y los hitos hacia la primera versión pública
> (**M1–M5** + bloque **P1–P4**) están **entregados y etiquetados** — ver `CHANGELOG.md`.
> Este documento recoge lo que queda pendiente y las ampliaciones futuras.

---

## Prioridad: roadmap 1.x

Candidatas priorizadas por **(demanda real en el foro de KoboToolbox × encaje con la
arquitectura actual × valor para el público objetivo)** — organizaciones que gestionan
datos sensibles y necesitan gobierno de acceso, no solo recolección. Cada hito = **una
sesión propia** (acordar el modelo antes de codificar + verificación contra datos reales
+ commits por hito + actualización de `CHANGELOG`/`ROADMAP`/memoria).

- [x] **Permisos a nivel de columna / ocultar campos sensibles** *(prioridad nº 1)* —
      **ENTREGADO** (ver `CHANGELOG.md`). Lista de ocultar (denylist) por
      (usuario, formulario) en `user_form_permissions.field_filter` y por enlace en
      `share_links.field_filter`; gemelo de `RowScope` (`lib/FieldScope`), aplicado en
      tabla/detalle/stats/CSV/adjuntos/geo/búsqueda. Pedido explícito en el foro
      ([column-level-permission/48743](https://community.kobotoolbox.org/t/column-level-permission/48743)).
      Encaja como módulo «premium» (ver `my.docs/MONETIZE.md`).
      2.ª fase: **columnas de solo-lectura** (ver pero no editar) y ocultar columnas en
      las **estadísticas agregadas derivadas** que no dependen de un único campo.
- [x] **Scoping por filas multi-condición (AND/OR + operadores)** *(prioridad nº 2)* —
      **ENTREGADO** (ver `CHANGELOG.md`). `RowScope` pasa a **grupos a 2 niveles** con
      conectores Y/O (raíz y por grupo) y operadores por condición: `in`, `nin` (≠),
      `lt/lte/gt/gte` (rango num/fechas), `empty`/`not_empty` y, para `select_multiple`,
      `has_any`/`has_all`/`has_none`. Paridad SQL≡PHP, fail-closed y retrocompat con el
      formato `{conditions:[...]}`. Editor reutilizable `RowFilterEditor.vue` en Permisos
      y Enlaces. Kobo **no lo soporta** — ventaja competitiva
      ([condition-based-row-level-permissions/55372](https://community.kobotoolbox.org/t/condition-based-row-level-permissions/55372),
      el staff lo confirma jul-2024).
- [x] **Mejoras del flujo de revisión** (sobre el estado «En espera» ya entregado) —
      **ENTREGADO** (ver `CHANGELOG.md`). **Estado inicial automático** al sincronizar un
      envío nuevo (global + override por formulario; fila de sistema; no escribe a Kobo;
      [auto on-hold/54994](https://community.kobotoolbox.org/t/can-we-set-an-on-hold-validation-automatically-when-users-submit-data/54994))
      y **estados de validación personalizables** (catálogo global `review_statuses`:
      crear estados propios, renombrar/recolorear los integrados, desactivar; flag
      `is_open` abierto/resuelto para las estadísticas;
      [customizing-validation-statuses/15808](https://community.kobotoolbox.org/t/customizing-validation-statuses/15808)).
- [ ] **Cadena de aprobación multi-nivel por roles** *(fase futura del flujo de revisión)*.
      Flujo por **etapas ordenadas**, cada una a cargo de un rol distinto
      (p. ej. solicitante → revisor → aprobador), de modo que un envío solo avanza cuando la
      etapa anterior lo despacha. Añade sobre lo ya entregado: definición de la cadena
      (¿global o por formulario?), **gating por rol/capacidad por etapa** (hoy solo hay un
      `can_validate` booleano por formulario), **posición actual** del envío + **transiciones
      válidas** comprobadas en backend (un revisor no puede aprobar del todo), qué hace el
      **rechazo** (terminal vs rebote a etapa anterior/al remitente), **colas** «lo que espera
      por mí» y notificaciones opcionales. Se apoya en `submission_reviews` (historia),
      `review_statuses` (estados) y el audit log. Esfuerzo **mayor** que nº1–nº3 (toca el
      modelo de permisos + máquina de estados + UI nueva); acotar v1 (cadena lineal por
      formulario, rechazo = rebote, sin notificaciones).
      **Nota de prioridad**: para una primera versión, la validación **plana por formulario**
      (un usuario con `can_validate` valida ese formulario) se considera **suficiente**; esto
      queda como ampliación, no como pendiente bloqueante.
      Pedido en el foro y **no soportado por Kobo**, el propio staff lo admite
      ([approval-workflow/25499](https://community.kobotoolbox.org/t/approval-workflow-using-kobo-post-submission/25499)).
- [ ] **Dashboards / paneles compartibles** *(mayor esfuerzo, 1.2+)*. Ampliar las
      estadísticas enriquecidas a paneles configurables y embebibles/compartibles. Demanda
      recurrente ([open-source-online-dashboards/17702](https://community.kobotoolbox.org/t/open-source-online-dashboards/17702)).

---

## Pendiente de validar con datos reales

Implementado y probado con datos de ejemplo + manejo de errores, pero aún sin verificar
contra una cuenta KoboToolbox real:

- [ ] **Edición real de un envío** (escritura en Kobo vía `PATCH .../data/bulk/` y refresco
      de caché). Confirmar el formato de campos con jerarquía de grupo (`grupo/campo`).
- [ ] **Envío real de email** con Resend. **Bloqueado por operación, no por código**: la
      cadena funciona extremo a extremo, pero Resend devuelve 403 «domain not verified»
      hasta verificar el dominio del remitente (ver `DEPLOY §8`).

---

## Mejoras de 2.ª fase (diferidas dentro de funcionalidad ya entregada)

- [ ] **Ordenar la tabla por una columna calculada** (de P2 · valores derivados).
- [ ] **Estadísticas**: ~~distribución de `select_multiple`~~ (HECHO; ver CHANGELOG);
      pendiente **agregación semana/mes + acumulado** y **tendencia** (7/30 días vs
      periodo anterior) (de P3).
- [ ] **Búsqueda con etiquetas**: incluir las etiquetas resueltas del esquema en
      `search_text`, no solo los valores crudos (de M4a).
- [ ] **Historial de edición por envío** en la UI **para admins** (ya se guarda en
      `audit_log`; «Mi actividad» de P1 cubre la vista del propio usuario).

---

## Optimización y UX

- [ ] **Modo oscuro** y mejores estados de carga/vacío (skeletons).
- [ ] **Filtros avanzados** adicionales en la tabla de envíos.
- [ ] **Organización de los catálogos i18n** *(candidata, a discutir)*. Hoy todo vive en
      `src/i18n/{es,en}.json` (cada clave en ambos + check de paridad). Valorar separar por
      *namespace* (p. ej. `guide`) en ficheros propios y/o **cargar de forma diferida** el
      catálogo de la Guía por ruta (vue-i18n `setLocaleMessage` + import dinámico). Solo
      merece la pena con un 3.er idioma, si la Guía crece a documentación larga, o si el
      peso de `/guide` importa; entonces adaptar el script de paridad al conjunto fusionado.

---

## Operación y mantenimiento

- [ ] **Transporte de correo alternativo (SMTP)**: hoy el envío es solo vía Resend (API
      HTTP, `lib/Mailer.php`). Ofrecer SMTP para quien prefiera su propio servidor — choca
      con la filosofía «sin dependencias»; valorar abstraer un `MailTransport` con
      back-ends `resend`|`smtp`.
- [ ] **Tests de integración de endpoints** (HTTP): login, CSRF y recuperación de
      contraseña extremo a extremo (la lógica vive en los scripts de `v1/`, hoy solo
      cubiertos a mano).
- [ ] **CI** (lint + build + PHPUnit). *(Docker queda fuera por ahora: no se usa en el proyecto.)*

---

## Ampliaciones futuras (del plan original)

- [ ] **Versión de escritorio** con Tauri (envuelve el mismo frontend Vue).
- [ ] **Webhooks de Kobo** para sincronización en cuasi-tiempo-real (en vez de cron cada 15 min).
- [ ] **Notificaciones por otros canales** (Telegram, Slack, WhatsApp).
- [ ] **Permiso `can_delete`** cuando exista la funcionalidad de borrado de envíos.
- [ ] **Permisos por período de tiempo** (acceso a envíos de un rango de fechas).
- [ ] **2FA** — la tabla `user_sessions` ya está preparada para soportarlo.
- [ ] **Exportación XLSX nativa** (diferida por la filosofía «sin dependencias»; hoy hay CSV).

---

*Cuando una tarea se complete, muévela a `CHANGELOG.md` con su fecha.*
