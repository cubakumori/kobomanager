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
- **Flujo de revisión — decisión de diseño (mantener simple)**: se conservan los **4 estados
      fijos** (pendiente / en espera / aprobado / rechazado) y la validación **plana por
      formulario** (un usuario con `can_validate` valida ese formulario). Se exploraron y
      **descartaron** por baja utilidad / posible confusión para el caso actual: el **estado
      inicial automático**
      ([54994](https://community.kobotoolbox.org/t/can-we-set-an-on-hold-validation-automatically-when-users-submit-data/54994))
      y los **estados de validación personalizables**
      ([15808](https://community.kobotoolbox.org/t/customizing-validation-statuses/15808)).
      Quedan como ideas reabribles si aparece una necesidad real.
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
- [ ] **Dashboards / paneles compartibles** *(mayor esfuerzo; versión futura)*. Dar el salto
      de la página fija de **Estadísticas** (un informe predefinido por formulario) a **paneles
      configurables y publicables**:
      - **Configurable**: el usuario elige qué indicadores/gráficos ve y cómo (qué preguntas,
        qué agregación, qué filtros de fila), montando su propio panel con *widgets* en vez de
        la vista fija actual.
      - **Multi-fuente**: combinar varios formularios/indicadores en una sola vista (KPIs de la
        organización, no de un único formulario).
      - **Compartible/embebible**: igual que los enlaces de solo lectura de envíos, pero para un
        *panel agregado* — un enlace público con contraseña/caducidad/revocación que muestra
        solo gráficos, **sin exponer envíos individuales ni el token de Kobo**. Útil para un
        donante o una web institucional.

      Encaja con la arquitectura (reutiliza `forms/stats.php`, `ShareLink`/`RowScope`/`FieldScope`,
      Chart.js y la vista pública sin shell), pero es el **mayor esfuerzo de UI** del roadmap
      (editor de paneles + persistencia de configuraciones + render público) → su propio hito,
      con modelo acordado primero. Demanda recurrente
      ([open-source-online-dashboards/17702](https://community.kobotoolbox.org/t/open-source-online-dashboards/17702)).

---

## Pendiente de validar con datos reales

Implementado y probado con datos de ejemplo + manejo de errores, pero aún sin verificar
contra una cuenta KoboToolbox real:

- [x] **Edición real de un envío** (escritura en Kobo vía `PATCH .../data/bulk/` y refresco
      de caché) — VERIFICADO contra Kobo real (form 43): campo en grupo (`grupo/campo`),
      `select_one` y `select_multiple` se escriben en Kobo y la caché + `search_text` se
      refrescan sin resync. Hallazgo clave: una edición crea una versión NUEVA con un
      `_uuid` distinto (el `_id` numérico se conserva); el backend migra el `submission_uid`
      de caché y arrastra el historial de revisiones, y detecta fallos por-envío del
      endpoint bulk (HTTP 200 con `failures>0`).
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
- [x] **Tests de integración de endpoints** (HTTP) — suite `api/tests/http/` que arranca
      la API en un `php -S` efímero (config aislada vía `KM_CONFIG`, BD `kobomanager_test`)
      y prueba extremo a extremo: login/JWT/logout/rate-limit, CSRF, recuperación de
      contraseña, revisión individual+lote, lectura con permisos+RowScope+FieldScope,
      export CSV y edición (vía stub local de Kobo). 27 tests HTTP (suite total 150).
- [x] **CI** (lint + build + PHPUnit) sin Docker — `.github/workflows/ci.yml` con tres
      jobs; MariaDB en el runner vía `ankane/setup-mariadb` (binarios, no contenedores).

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
