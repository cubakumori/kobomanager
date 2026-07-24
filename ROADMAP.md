# Roadmap — KoboManager

Lo que queda por hacer e ideas para más adelante. Todo lo ya entregado se registra en
[`CHANGELOG.md`](./CHANGELOG.md), con su versión y fecha.

> **Contexto de prioridad.** Los usuarios reales de KoboManager manejan datos que, en el
> contexto equivocado, ponen a personas en riesgo (denuncias de DDHH). Por eso el frente de
> **seguridad y privacidad** va primero. Los «frentes mayores» (dashboards, cadena de
> aprobación) quedan supeditados a demanda real. Al completar una tarea, se mueve a
> `CHANGELOG.md`.

---

## Seguridad y privacidad

- [ ] **Cifrado en reposo de los campos marcados como sensibles** *(prioritario; empezar por
      la sesión de diseño)*. Hoy el token de Kobo se cifra, pero los envíos
      (`submissions_cache.json_payload`) están en claro. Modelo acordado: **no** cifrar todo,
      sino **marcar por formulario qué campos son sensibles** y cifrar solo esos, descifrando
      al vuelo para quien tenga permiso. Tensiones a resolver en el diseño:
      - Un campo cifrado no puede entrar en búsqueda FULLTEXT, ni ordenarse/filtrarse en SQL,
        ni agregarse en Estadísticas → decidir qué se degrada (probablemente queda fuera de
        búsqueda/orden/stats).
      - Clave de cifrado y rotación (extender el patrón del token).
      - Interacción con FieldScope y con los enlaces públicos (un campo sensible nunca debe
        exponerse en un enlace).
      - Backfill al marcar/desmarcar un campo como sensible.
- [ ] **Auditoría de seguridad externa** antes de crecer en adopción. Vías: programas de
      auditoría para software libre (p. ej. OTF/OSTIF), revisión por pares, o un pentest
      acotado de pago. Complementa lo ya documentado en `SECURITY.md`.

---

## Control de calidad

- [ ] **Comentarios generales por equipo** *(próximo acordado)*. Notas de QC **desligadas de
      un envío**: un icono en el bloque de cada equipo abre un modal para dejar una nota
      asociada al equipo y la fecha (p. ej. «puestas en espera las no admitidas de hoy»),
      visible también en la página de Comentarios. Tabla nueva `form_comments`
      (`scope_type: general|team|enumerator`); escribir = `can_validate`. V1 puede quedarse en
      el alcance «equipo» y dejar `general`/`enumerator` para después.
- [ ] **Marcado «en espera» automático al sincronizar** *(el checkbox original)*. Exige
      atribución nueva en `submission_reviews` (`source='auto'`) y decidir si empuja a Kobo o
      es solo local, y si re-evalúa al cambiar los umbrales. Es el único caso donde
      persistir las banderas (hoy derivadas, no persistidas) empezaría a valer la pena.
- [ ] **Horario admisible de trabajo**: franja horaria por formulario (en `APP_TIMEZONE`);
      encuestas iniciadas de madrugada como bandera propia.
- [ ] **Velocidad imposible entre puntos geo consecutivos** del mismo encuestador. Diseño
      delicado (GPS ruidoso → falsos positivos); la variante simple «GPS clavado» ya existe.
- [ ] **Straight-lining** (misma opción en todas las select_one de un envío). Señal dudosa
      con cuestionarios cortos; la variante fuerte (duplicados exactos) ya existe.
- [ ] **Control de calidad en enlaces compartibles** (`expose_quality`): que un enlace de
      solo lectura pueda exponer la página de QC (para un líder de equipo sin cuenta),
      ofuscando/omitiendo los identificadores de personas según la config del enlace. El
      subconjunto menos sensible (resumen de revisión) ya existe. Diferido a demanda real.

---

## Índice de riesgo — Fase 2

- [ ] **`audit` de Kobo (tiempo por pregunta)**: no viene en el JSON del envío, es un
      `audit.csv` adjunto por envío y solo existe si el formulario añadió la pregunta tipo
      `audit`. Requiere descargar y parsear ese adjunto (sync más pesado + almacenamiento).
- [ ] **Histórico semanal persistido** de lo *mutable* (estado de revisión y z relativos a
      pares); lo físico ya tiene histórico en vivo. Subsistema propio (tabla + cron +
      retención + UI de series); encaja con la Fase 2.

---

## Muestra por equipo

- [ ] **Panel de muestra: selector de orden + más estadísticas en «Resumen»**
      *(acordado; próxima sesión)*. Solo frontend (`SamplePanel.vue`); el backend ya envía
      todos los equipos y sus proyecciones.
      - **Selector de orden** en la barra superior (junto al selector de vista y «Agrupar
        equipos»), recordado **por dispositivo** y aplicado a **todos** los modos (no solo
        «Resumen»). Los **«fuera de plan» siempre al final**. Claves: **% de cumplimiento
        ascendente** (más atrasados primero), objetivo (el actual, por defecto), hecho,
        backlog (pendientes + en espera) y alfabético. Con «Agrupar equipos», ordena
        **dentro de cada grupo**. Es un `computed` sobre `displayTeams`.
      - **Más estadísticas en «Resumen»** (aprovechando el espacio bajo el doughnut, sin
        duplicar la tarjeta de cabecera): (1) **proyección agregada** «N de M equipos
        proyectan cumplir el objetivo al ritmo actual» (usa `teams[].projection`); (2)
        **reparto por estado de avance** (completos ≥100 % · en progreso · atrasados <50 % ·
        sin empezar), quizá barra apilada con la paleta de cumplimiento; (3) **mejor y peor
        equipo** por % de cumplimiento; (4) **% global** como número grande junto al
        doughnut; (5) opcional, **celdas del plan cubiertas** (recorre `teams[].cells`).
        Priorizar (1) y (2).
- [ ] **Extensiones del meta-equipo**: (a) diccionario explícito equipo→grupo (evita el cubo
      «Sin agrupar»); (b) el test de dependencia funcional como CHEQUEO propio del eslabón
      `encuestador → equipo` en Ajustes.
- [ ] **Objetivos por celda para los campos secundarios** (sexo, raza…): hoy solo muestran
      distribución observada; planificar también su cuota. Se gobernará con un ajuste global
      «Objetivos por equipo» que nace **junto con** la funcionalidad (no antes). Los campos
      secundarios con objetivo pasarían a mostrar cumplimiento (paleta completa por %).

---

## Estadísticas y UX

- [ ] **Filtrado avanzado de estadísticas por cualquier campo**: reutilizar el filtro
      avanzado de la lista de envíos (RowScope multi-condición) sobre la página de
      estadísticas. `Stats::compute` ya admite un `$scope` arbitrario; el grueso es UX.
- [ ] **Agregación semanal** en «Envíos por día/mes» (escalón intermedio).
- [ ] **Búsqueda global (Cmd/Ctrl+K)** que cruce formularios, usuarios, cuentas y enlaces.
- [ ] **Navegación por teclado en tablas** (flechas, Enter para abrir, Espacio/Shift+clic).
- [ ] **Pasada de accesibilidad en formularios** (asociar `label`/`id`, validación inline,
      campos requeridos).
- [ ] **Acciones en lote en las vistas de admin** (Usuarios/Formularios/Cuentas): cambiar rol,
      activar/desactivar o archivar en lote, reutilizando el patrón de la tabla de envíos.
- [ ] **Cabeceras ordenables en la tabla pública** de enlaces compartidos (heredar el orden
      por columna de la tabla interna, respetando el `field_filter` del enlace).
- [ ] **Enlaces en lote sobre más tipos de campo**: hoy solo `select_one`; extender a texto/
      metadatos (valores distintos presentes) y a `select_multiple` (con `has_any`).
- [ ] **Limpiar el ruido de metadatos de `search_text`**: añadir una denylist de nombres de
      metadatos (start/end/today/uuid…) al índice FULLTEXT. Calidad de resultados, no
      velocidad; no urgente.

---

## Operación y mantenimiento

- [ ] **Transporte de correo alternativo (SMTP)** además de Resend (abstraer un
      `MailTransport` con back-ends `resend`|`smtp`).
- [ ] **Circuit-breaker hacia Kobo en el cron de sync**: tras N fallos consecutivos por
      cuenta, saltarse el resto de la corrida o esperar exponencialmente.
- [ ] **Registro de errores de API consultable** por el admin (tabla + vista), si la
      auditoría y los logs del servidor no bastan.
- [ ] **Instalador web** (estilo WordPress, con `install.lock`) como alternativa al CLI.
      Beneficio limitado: lo duro (dominio, vhost, HTTPS, BD) exige acceso al servidor igual.
- [ ] **Webhook de los REST Services de KoboToolbox** *(acelerador opcional)*: Kobo hace POST
      por cada envío → baja la latencia por debajo del cron de 15 min. Solo a demanda.

---

## Frentes mayores (solo con demanda real)

- [ ] **Dashboards / paneles compartibles**: dar el salto de la página fija de Estadísticas a
      paneles **configurables** (el usuario elige indicadores/widgets), **multi-fuente**
      (varios formularios) y **compartibles/embebibles** (enlace público con solo gráficos).
      El caso «único formulario, informe fijo» ya se comparte hoy (`expose_stats`); queda lo
      configurable y multi-fuente. Es el mayor esfuerzo de UI del roadmap.
      ([demanda en el foro](https://community.kobotoolbox.org/t/open-source-online-dashboards/17702))
- [ ] **Cadena de aprobación multi-nivel por roles** *(descartada por ahora)*. Para el tamaño
      típico de una ONG, los 4 estados fijos + `can_validate` bastan con un revisor
      responsable; si hiciera falta más, la alternativa ligera preferida es un **doble-check**
      (dos revisores), no una máquina de estados por roles. Se reabre solo con demanda real.
      ([pedido en el foro](https://community.kobotoolbox.org/t/approval-workflow-using-kobo-post-submission/25499))
- [ ] **Vigilar riesgos de escala** *(MEDIR antes de actuar)*: (a) backend más allá de ~200k
      envíos/formulario (`Stats::compute` en bucle PHP + `JSON_EXTRACT` sin índice →
      columnas generadas/agregación SQL); (b) peso del bundle/precache (carga diferida de
      Chart.js/Leaflet y del catálogo i18n de la Guía).

---

## Ideas reabribles

- [ ] **«Organizaciones que usan KoboManager»**: escaparate en la landing o en `/apoyar`
      (con su permiso), para cuando haya varias.
- [ ] **Export/import de usuarios y permisos entre instancias**: clonar el equipo re-mapeando
      ids por claves naturales (usuario→email, formulario→`kobo_asset_uid`).
- [ ] **Versión de escritorio** con Tauri (envuelve el mismo frontend Vue).
- [ ] **Notificaciones por otros canales** (Telegram, Slack, WhatsApp).
- [ ] **Permiso `can_delete`** cuando exista el borrado de envíos.
- [ ] **Permisos por período de tiempo** (acceso a envíos de un rango de fechas).
- [ ] **Estado inicial automático** y **estados de validación personalizables**: explorados y
      descartados por baja utilidad para el caso actual; reabribles si aparece la necesidad.
