# Roadmap — KoboManager

Estado vivo de lo que falta por hacer e ideas para más adelante. Lo ya entregado se
registra en [`CHANGELOG.md`](./CHANGELOG.md).

> Todas las fases del plan inicial (0–7), los hitos hacia la primera versión pública
> (**M1–M5** + **P1–P4**), los hitos nº1–nº2 del roadmap 1.x (permisos por columna y
> scoping multi-condición), el bloque de portada/landing y las **prioridades acordadas
> de jun-2026** (reorganización i18n, bandeja de mensajes de contacto, modo oscuro +
> skeletons, columnas de solo lectura y filtros avanzados) están **entregados** — ver
> `CHANGELOG.md`. Este documento recoge solo lo pendiente.

> **Orden de prioridad acordado (jul-2026).** Partiendo de que los usuarios reales manejan
> datos sensibles (instancia real = denuncias de DDHH): **(1) seguridad y privacidad**
> (abajo, lo primero), **(2) monitorización de muestra por equipo** (**ENTREGADA en 1.32.0**,
> ver `CHANGELOG.md`; el enlace público de solo lectura del panel queda como 2ª iteración),
> **(3)** paneles/dashboards y escalabilidad como apuestas
> posteriores. La **cadena de aprobación multi-nivel** se DESCARTA por ahora (ver «Frentes
> mayores»): con un revisor profesional o un doble-check ligero basta para el tamaño típico
> de una ONG. La estrategia de **adopción y servicio** (instalar + formar + supervisión
> técnica; la organización asume la responsabilidad y el hosting) vive en
> `my.docs/MONETIZE.md`, no aquí.

---

## Seguridad y privacidad — PRIORIDAD (jul-2026)

> Los usuarios reales de KoboManager manejan datos que en el contexto equivocado ponen a
> personas en riesgo. Antes de crecer en adopción, la postura de seguridad debe estar a la
> altura de ese modelo de amenaza. Este bloque va **por delante** del resto del roadmap.

- [ ] **2FA (segundo factor)** *(subido desde «Ampliaciones futuras»)* — una sola contraseña
      protegiendo el acceso a datos sensibles es poco. TOTP (app autenticadora) como primer
      método; la tabla `user_sessions` ya está pensada para soportarlo. Decidir: obligatorio
      por instancia o por usuario/rol; códigos de recuperación; reset por admin.
- [ ] **Cifrado en reposo de los datos SENSIBLES marcados por formulario** — hoy el token de
      Kobo se cifra (TokenVault), pero los envíos en `submissions_cache.json_payload` están
      en claro. Diseño acordado (jul-2026): **no cifrar todo** (no todos los formularios
      tienen datos sensibles y el cifrado total rompe búsqueda/orden/stats), sino permitir
      **marcar por formulario qué campos son sensibles** (como ya se marca el campo de
      equipo/encuestador o los umbrales QC) y cifrar **solo esos** en reposo, descifrándolos
      al vuelo para quien tenga permiso de verlos. Tensiones de diseño a resolver:
      - Un campo cifrado **no puede** entrar en `search_text` (FULLTEXT), ni ordenarse, ni
        filtrarse por valor en SQL, ni agregarse en Estadísticas → definir qué se degrada
        (probablemente: un campo sensible queda fuera de búsqueda/orden/stats por diseño).
      - Clave de cifrado: derivada de `CONFIG_TOKEN_KEY` o clave aparte; qué pasa en rotación
        (`rotate_token_key.php` ya existe para el token — extender el patrón).
      - Interacción con FieldScope (oculto) y con los enlaces públicos (un campo sensible
        nunca debería exponerse en un enlace, ni siquiera cifrado→descifrado).
      - Backfill al marcar un campo como sensible (cifrar lo ya cacheado) y al desmarcarlo.
- [ ] **Borrado seguro, minimización y retención de envíos** — hoy la retención existe para
      el `audit_log` (`audit_retention_days`); extender esa idea a los datos: ¿cuánto vive un
      envío en caché?, ¿purga real (no solo marcado)?, ¿retención configurable por formulario?
      Relevante para minimizar la superficie ante una incautación del servidor.
- [ ] **Auditoría de seguridad formal antes de crecer en adopción** — el repo es público
      (AGPL); conviene una revisión de terceros antes de que más organizaciones sensibles
      confíen en él. Vías posibles: programas de auditoría para OSS (p. ej. OSTIF /
      fondos que financian auditorías de software libre), revisión por pares de otra
      persona con perfil de seguridad, o un pentest acotado de pago. Complementa la
      revisión de seguridad interna ya planificada.

---

## Control de calidad por equipo/encuestador — extensiones diferidas

> El hito base está **entregado en 1.14.0 y pulido hasta 1.19.0** (ver CHANGELOG):
> umbrales por formulario (duración mín/máx y consecutividad mínima), página
> `forms/<id>/quality` con las cuatro banderas (corta, larga, hueco corto, solapada) y
> drill-down, el botón «Marcar en espera las N no admitidas» sobre el flujo de revisión
> en lote existente, alcance por estado de revisión configurable (`qc_scope`), tasa de
> no admitidas con denominador único (n / total recibido) y formato de porcentajes
> configurable (`pct_format`, aplicado también a Estadísticas). En **1.26.0** se añadió su
> contrapartida, **«Aprobar las N admisibles pendientes»** (los pendientes sin ninguna
> bandera): ajuste global `qc_admit_batch` (tabla | QC | ambos | ninguno) que gobierna solo
> ese atajo, filtro «solo admisibles» en la tabla, guardarraíles server-side (solo
> pendientes; exclusión de encuestadores de alto riesgo con el Índice de riesgo activo), y
> banderas **derivadas, no persistidas** (`Quality::admissiblePendingUids`, fuente de verdad
> única para tabla y QC — ver la nota del «marcado on-hold automático» abajo). En
> **1.27.0**: **toggle transitorio del alcance** en la propia página (y su export):
> `?scope=all|pending_hold` sustituye el ajuste global `qc_scope` solo para esa petición.
> Quedan estas extensiones, para cuando haya demanda:

- [ ] **Marcado on-hold totalmente automático al sincronizar** *(el checkbox original)*:
      exige atribución nueva en `submission_reviews` (p. ej. `source='auto'`, cambio de
      ENUM → SchemaCheck + nota de upgrade) y decidir si empuja a Kobo o es solo local,
      y si re-evalúa retroactivamente al cambiar los umbrales.
      *Nota de diseño (jul-2026):* el atajo «aprobar admisibles» (1.26.0) mantiene las
      banderas **derivadas, no persistidas** a propósito — los umbrales son editables y
      señales como duplicados/«GPS clavado» son relativas al cohorte (un sync nuevo puede
      voltear la bandera de otro envío), así que persistirlas sería una vista materializada
      con recálculo en cada sync + edición de umbrales. Este hito (histórico por naturaleza,
      `source='auto'`) es el único donde persistir empezaría a valer la pena.
- [ ] **Horario admisible de trabajo** (franja horaria por formulario, evaluada en
      `APP_TIMEZONE`): encuestas iniciadas de madrugada como bandera propia.
- [ ] **Velocidad imposible entre puntos geo consecutivos** del mismo encuestador
      (distancia/tiempo entre encuestas seguidas) — diseño pesado y delicado (GPS
      ruidoso → falsos positivos). La variante simple, «GPS clavado» (mismo punto
      exacto en ≥3 envíos del mismo encuestador), quedó **entregada en 1.22.0**.
- [ ] **Straight-lining** (misma opción elegida en todas las select_one de un envío):
      señal dudosa con cuestionarios cortos y umbral difícil. La variante fuerte,
      **duplicados exactos de respuestas** (a nivel de formulario, solo contenido),
      quedó **entregada en 1.22.0**.
- [ ] **Comentarios generales / por grupo / por miembro** *(Fase 2 del panel de
      comentarios; la Fase 1 —panel de los comentarios de revisión existentes por
      envío— se entregó en 1.25.0)*: comentarios **desligados de un envío** (una nota
      para todo un equipo o un encuestador). Es un tipo de dato nuevo: tabla
      `form_comments` (`scope_type: general|team|enumerator`, `scope_value`, autor,
      cuerpo, fecha) → SchemaCheck + nota de upgrade, UI de escritura, permisos de
      escritura (¿`can_validate`?) y decisiones de edición/borrado. Hito propio cuando
      haya demanda.
- [ ] **Índice de riesgo — Fase 2 e histórico** *(la Fase 1 —percentmatch, señales
      relativas a pares, índice explicado por encuestador y equipo, opt-in
      `forms.risk_min_n`— se entregó en 1.23.0)*:
      - **Fase 2 (lift mayor):** sincronizar el **`audit` de Kobo** (tiempo por pregunta).
        NO viene en el JSON del envío: es un `audit.csv` **adjunto por envío**, y solo
        existe si el formulario añadió la pregunta tipo `audit` en su XLSForm. Requiere
        descargar y parsear ese attachment por envío (sync más pesado + almacenamiento);
        condicionado a que el formulario lo recoja.
      - **Histórico semanal persistido:** solo aportaría para lo *mutable* (estado de
        revisión y z relativos a pares); lo *físico* (banderas QC, percentmatch, Benford,
        GPS) ya da un histórico fiel calculado en vivo (`by_week[]` de QC). Un subsistema de
        snapshots (tabla + cron + retención + UI de series) es un frente propio; encaja con
        la Fase 2.
- [ ] **Control de calidad en enlaces compartibles** (`expose_quality`): que un
      enlace de solo lectura pueda exponer la página de QC, para que un **líder de
      equipo de encuestación** verifique los errores de su gente sin cuenta en la app.
      Decisión de diseño: es información sensible (señales de fabricación, nombres de
      encuestadores), pero el admin ya tiene mitigaciones —ponerlo o no, con
      contraseña/caducidad, y ofuscar el valor de los campos que identifican personas—,
      así que la responsabilidad es suya. Exige columna `expose_quality` + endpoint
      público (variante de `quality.php` que herede el fila/columna del enlace y
      **ofusque/omita** los identificadores según su config) + vista pública + i18n.
      **Diferido a demanda real** una vez el repo sea público; si alguien lo pide, se
      implementa. *El subconjunto menos sensible ya se entregó en **1.27.0***: el
      **resumen de revisión** por equipo/encuestador en enlaces (`expose_review_summary`,
      opt-in por enlace, solo recuentos agregados por estado) — esta extensión queda
      para las **banderas de calidad** completas (umbrales + drill-down de infracciones).

---

## Notificaciones casi inmediatas de envíos nuevos — acelerador diferido

> El hito está **entregado** (ver CHANGELOG): **Fase 1** en **1.29.0** (frecuencia de aviso
> por email por usuario/formulario: `off | diario | cada hora | cada sync`, con marca de
> agua, throttle, horario de silencio y scoping por filas) y **Fase 2** en **1.30.0** (Web
> Push sobre la PWA: opt-in por dispositivo, `lib/WebPush` sin dependencias —RFC 8291/8292—,
> claves VAPID en config). Como KoboManager solo ve envíos nuevos al sincronizar (cron cada
> 15 min), «inmediato» = «al siguiente sync». Queda solo el acelerador opcional:

- [ ] **Webhook de los REST Services de KoboToolbox** *(acelerador opcional; solo si
      alguien necesita segundos de latencia)*: Kobo hace POST por cada envío a un endpoint
      con token secreto en la URL + mini-upsert en caché — baja la latencia por debajo del
      ciclo del cron y de paso mantiene la caché fresca sin esperar al sync. Coste:
      configuración por formulario en Kobo y un endpoint público extra que asegurar. **No
      construido a propósito** (solo a demanda). *(Idea aparcada, solo si se pide: canal
      Telegram vía bot por instancia — ver también «Ampliaciones futuras».)*

---

## Frentes mayores (supeditados a demanda real)

> **Decisión (jul-2026):** ninguno de los dos se aborda ahora.
> - La **cadena de aprobación multi-nivel** se **DESCARTA por ahora**: para el tamaño típico
>   de una ONG (pocos empleados), el flujo actual —4 estados fijos + `can_validate` por
>   formulario— basta si hay **una persona profesional** responsable de la revisión. Si en
>   algún momento hiciera falta más control, la alternativa **ligera** preferida no es la
>   cadena por roles sino un **doble-check** (dos revisores deben aprobar) o una **revisión
>   más profunda de los «en espera»** por el mismo revisor — mucho menos coste que una
>   máquina de estados con roles por etapa. Se reabre solo con demanda real.
> - Los **dashboards** siguen como apuesta de crecimiento posterior (mayor esfuerzo de UI;
>   su valor depende de que ya haya varias organizaciones con datos que presentar).

- [ ] **Cadena de aprobación multi-nivel por roles** *(DESCARTADA por ahora — ver decisión
      arriba; se documenta por si reaparece la necesidad)*.
      Flujo por **etapas ordenadas**, cada una a cargo de un rol distinto
      (p. ej. solicitante → revisor → aprobador), de modo que un envío solo avanza cuando la
      etapa anterior lo despacha. Añade sobre lo ya entregado: definición de la cadena
      (¿global o por formulario?), **gating por rol/capacidad por etapa** (hoy solo hay un
      `can_validate` booleano por formulario), **posición actual** del envío + **transiciones
      válidas** comprobadas en backend (un revisor no puede aprobar del todo), qué hace el
      **rechazo** (terminal vs rebote a etapa anterior/al remitente), **colas** «lo que espera
      por mí» y notificaciones opcionales. Se apoya en `submission_reviews` (historia) y el
      audit log. Esfuerzo **mayor** (toca el modelo de permisos + máquina de estados + UI
      nueva); acotar v1 (cadena lineal por formulario, rechazo = rebote, sin notificaciones).
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

      > **Avance parcial (jun-2026, entregado):** los enlaces públicos ya pueden exponer
      > el **panel de Estadísticas** (`expose_stats`), con scoping de fila/columna y **sin**
      > el estado de revisión interno; un enlace con *solo* estadísticas muestra gráficos
      > sin exponer envíos individuales — cubre la viñeta «compartible/embebible» para un
      > **único formulario** con el informe **fijo**. Queda pendiente lo grande: panel
      > **configurable** (elegir indicadores/widgets) y **multi-fuente** (varios formularios).

### Decisión de diseño: flujo de revisión simple

Se conservan los **4 estados fijos** (pendiente / en espera / aprobado / rechazado) y la
validación **plana por formulario** (`can_validate`). Se exploraron y **descartaron** por
baja utilidad / posible confusión para el caso actual: el **estado inicial automático**
([54994](https://community.kobotoolbox.org/t/can-we-set-an-on-hold-validation-automatically-when-users-submit-data/54994))
y los **estados de validación personalizables**
([15808](https://community.kobotoolbox.org/t/customizing-validation-statuses/15808)).
Quedan como ideas reabribles si aparece una necesidad real.

---

## Muestra por equipo — pendientes (2ª iteración)

> El núcleo se **entregó en 1.32.0** (panel, editor de la matriz, denominador, histórico y
> guards de tipo de campo) — ver `CHANGELOG.md`. Aquí quedan solo las extensiones pendientes.

- [ ] **Enlace público de solo lectura del panel de muestra** — un coordinador sigue el avance
      sin cuenta, como el resumen de revisión de los enlaces compartidos. Encaja con la
      micro-caché en disco de `share_stats` y con el alcance fijo por equipo del enlace.
- [ ] **Objetivos por celda para los campos secundarios** — hoy los secundarios (sexo, raza…)
      solo muestran distribución observada; una etapa 2 podría planificar también su cuota.
- [x] **Permiso «Muestra» jerárquico + página propia del plan** — **entregado en 1.34.0**
      (decisión de la conversación jul-2026, ver `CHANGELOG.md`): `can_sample` implica
      «Ajustes» (normalizado en servidor y espejado en la UI de Permisos), el editor del
      plan vive en `/admin/forms/{id}/sample-plan`, Ajustes muestra el botón «Configurar
      la muestra» (deshabilitado sin permiso o sin campo de equipo) y avisa si cambiar el
      campo de equipo desalinearía un plan vigente. Migración: quien tenía «Ajustes»
      hereda «Muestra».
- [x] **Selector de tipo de vista en el panel de muestra** — **entregado en 1.33.0** (ver
      `CHANGELOG.md`): seis modos (lineal / tabla / mapa de calor / barras / semáforo /
      resumen-doughnut), preferencia por dispositivo en `localStorage`, frontend-only en
      `SampleView.vue`. «Atrasado» del semáforo y el doughnut de resumen se calculan sobre
      las celdas con objetivo con el hecho acotado a su meta (la sobre-muestra de un equipo
      no distorsiona a los demás).
- [ ] **Normalización del eje de miembro/equipo cuando es texto libre** *(de la conversación
      jul-2026)* — un campo de miembro de **texto libre** (p. ej. iniciales) sufre variantes de
      la misma persona («ABC» / «abc» / «Abc», o espacios), y hoy la clave de agrupación es el
      **string crudo** (`(string) $valor` en `Stats`/`Quality`/`Risk`/`Sample`), así que se
      parte en varios cubos en TODAS las vistas de desglose por miembro/equipo. Diseño acordado
      = **ajuste por formulario con tres modos**:
      1. **«Mantener tal cual»** — comportamiento actual, sensible a mayúsculas/espacios.
      2. **«Normalizar al agrupar» (POR DEFECTO)** — `trim` + colapsar espacios internos +
         `mb_strtolower` para la CLAVE; etiqueta mostrada = la **grafía original más frecuente**
         entre las filas fusionadas (si escriben «ABC» la mayoría, se ve «ABC»).
      3. **«Normalizar + alias»** — además, una **tabla de alias por formulario** que mapea
         variantes/erratas a un canónico («A.B.C» → «ABC»). Capa más pesada (almacenamiento +
         UI de gestión + mantenimiento manual); puede construirse **después** de los dos primeros.
      Aplicar vía **helper compartido en las cuatro vistas** (Estadísticas, Control de calidad,
      Índice de riesgo y Muestra) para que el mismo miembro cuente igual en todas. Matices:
      (a) el ajuste **solo tiene efecto en campos de texto/metadatos** — un `select_one` ya tiene
      códigos canónicos, ahí es no-op y la UI no debería ofrecerlo; (b) **un solo ajuste por
      formulario** cubre ambos ejes (miembro y equipo); (c) **no muta datos** (normaliza en
      lectura, reversible); (d) al cambiar el default a «Normalizar» en formularios existentes se
      fusionan variantes de golpe al actualizar — casi siempre deseable, inocuo para
      `_submitted_by` y `select_one`, pero mencionarlo en el CHANGELOG cuando toque.

---

## Publicación (en torno a hacer público el repo)

> **Estado (jun-2026): el repositorio YA es PÚBLICO** y la demo está viva en
> `kobomanager.org` (modo demo integrado, datos sintéticos vía `api/cli/seed_demo.php`,
> cron de reset verificado; runbook en [`DEMO.md`](DEMO.md)). El disclaimer de no
> afiliación, el dominio propio, `SECURITY.md`, el release «deploy-ready» y el instalador
> CLI también están entregados (ver `CHANGELOG.md`). Lo que queda abajo es pulido posterior.

Sin pendientes en esta sección.

---

## Ideas reabribles (post-publicación)

- [ ] **«Organizaciones que usan KoboManager»** — acápite/escaparate en la landing o en
      `/apoyar` con las organizaciones que lo usan (con su permiso). Para cuando haya varias.
- [ ] **Export/import PARTICULAR de usuarios y permisos entre instancias** *(extensión
      del export/import de la BD entregado en 1.13.0)*: clonar el equipo (usuarios +
      permisos por formulario) a otra
      instancia exige re-mapear ids por claves naturales (usuario→email,
      formulario→`kobo_asset_uid`) — diseño propio, no un recorte del backup general.
      Para cuando haya demanda real.

---

## Optimización y UX

- [ ] **Limpiar el ruido de metadatos de `search_text`** *(de la verificación de la
      búsqueda, jul-2026; incremental)* — `SubmissionSearch::textFor` salta las claves con
      prefijo `_`, pero recoge campos estándar de Kobo que NO lo llevan (`start`, `end`,
      `today`, `formhub/uuid`, `meta/instanceID`, `__version__`…), así que el índice
      FULLTEXT acaba con UUIDs, timestamps ISO y URLs. Efecto: un fragmento que aparezca
      dentro de un UUID puede dar un falso positivo, y el índice pesa de más. Añadir una
      **denylist de nombres de metadatos** (además del prefijo `_`) y re-poblar con
      `php api/cli/rebuild_search_text.php`. *No urgente:* medido, la búsqueda va sobrada a
      escala Kobo (FULLTEXT 4–20 ms a 1000 filas/formulario) y el FULLTEXT por-formulario
      **no es posible** en InnoDB (error 1283); esto es calidad de resultados, no velocidad.
- [ ] **Cabeceras ordenables también en la tabla pública de enlaces compartidos**
      *(extensión de 1.12.0)*: la tabla interna ya ordena por cualquier columna clicando
      la cabecera (`sort=field:<clave>`); la vista pública `/s/<token>` podría heredar el
      mismo mecanismo (respetando el `field_filter` del enlace, como ya hace la interna
      con FieldScope).
- [ ] **Creación de enlaces en lote sobre más tipos de campo** *(extensión de lo ya
      entregado)* — la creación en lote de enlaces de solo lectura (un enlace por valor de
      un campo distintivo) hoy admite solo campos de **opción única (`select_one`)**, cuyos
      valores son un conjunto acotado y conocido del esquema. Extensiones posibles según
      demanda: (a) **campos de texto / metadatos** (p. ej. `_submitted_by`), enumerando los
      **valores distintos presentes en los datos** (`scope-fields ?values=` ya los da) con un
      tope claro; (b) **`select_multiple`**, donde «un enlace por valor» dejaría de ser una
      partición (un envío con varios valores caería en varios enlaces) y usaría el operador
      `has_any` en vez de `in`. Ambos son incrementales sobre el endpoint actual
      (`admin/shares_bulk.php`) y el modal de `SharesView.vue`.
- [ ] **Agregación semanal explícita en Estadísticas** *(pendiente menor)* — hoy «Envíos por
      día/mes» elige día↔mes automáticamente según el tramo; valorar el escalón intermedio
      por semana.
- [ ] **Filtrado avanzado de estadísticas por cualquier campo** *(mayor esfuerzo; mucho
      más adelante)* — hoy las estadísticas se pueden acotar por estado de revisión
      (tarjetas del encabezado) y por equipo (checkboxes del desglose por equipo). El salto
      siguiente sería reutilizar el **filtro avanzado** que ya tiene la lista de envíos
      (`RowFilterEditor.vue` + `?filter=` con RowScope multi-condición AND/OR) directamente
      sobre la página de estadísticas: acotar por cualquier pregunta o combinación (p. ej.
      «mujeres, de La Habana, desempleadas»). `Stats::compute` ya admite un `$scope`
      arbitrario, así que el grueso es UX. El filtro por equipo de hoy sería un caso
      particular de este mecanismo general.
- [ ] **Carga diferida del catálogo i18n de la Guía** — cargar `guide.json` bajo demanda por
      ruta (vue-i18n `setLocaleMessage` + import dinámico). Solo merece la pena con un
      3.er idioma o si la Guía crece a documentación larga; adaptar entonces el check de
      paridad.
- [ ] **Acciones en lote en las vistas de admin** *(de la revisión de jul-2026)* — la
      revisión de envíos ya tiene selección múltiple, pero Usuarios / Formularios /
      Cuentas no: cambiar el rol o activar/desactivar a varios usuarios, o archivar
      varios formularios, exige ir uno a uno. Reutilizar el patrón de selección +
      `confirmDialog` de la tabla de envíos. Útil sobre todo en instancias grandes;
      esperar a demanda real.
- [ ] **Búsqueda global (Cmd/Ctrl+K)** *(de la revisión de jul-2026)* — un buscador en el
      shell que cruce formularios, usuarios, cuentas y enlaces (y quizá envíos por
      formulario), con resultados en un popover. Esfuerzo alto; hoy cada tabla tiene su
      búsqueda propia.
- [ ] **Navegación por teclado en tablas** *(de la revisión de jul-2026)* — flechas para
      moverse entre filas, Enter para abrir el detalle, Espacio para seleccionar,
      Shift+clic para rangos. Para usuarios intensivos; esfuerzo alto.
- [ ] **Pasada transversal de accesibilidad en formularios** *(de la revisión de
      jul-2026)* — asociar `label`/`id` (via `useId()`) en todos los formularios de las
      vistas (hoy los labels envuelven al input sin `for`); revisar validación inline y
      marcado de campos requeridos. Los diálogos, popovers y botones-icono ya están
      cubiertos.
- [ ] **Vigilar dos riesgos técnicos de escala** *(de la conversación jul-2026; MEDIR
      antes de actuar, no optimizar a ciegas)*:
      - **Backend — más allá de ~200k envíos por formulario**: `Stats::compute` recorre en
        un bucle PHP toda la caché del formulario, y los filtros por valor usan
        `JSON_EXTRACT` sin índice. Palancas si aparecen instancias así (medir con slow query
        log primero): columnas generadas/índices MySQL para los `JSON_EXTRACT` más usados, y
        pasar `Stats::compute` a agregación SQL (o cachear también la vista interna; la
        pública ya se cachea). A los volúmenes típicos de Kobo no hace falta.
      - **Frontend — peso del bundle/precache** (~1.16 MB precache): Chart.js, Leaflet y un
        `guide.json` que crece pesan. Si molesta el arranque en redes lentas, palancas:
        carga diferida por ruta de Chart.js/Leaflet (solo en Estadísticas/Mapa) y del
        catálogo i18n de la Guía (ya anotado arriba). Medir con el reporte de tamaño del
        build antes de trocear.

---

## Operación y mantenimiento

> El **instalador CLI** (`php api/cli/install.php`) y el **release «deploy-ready»**
> (`npm run package`) están entregados (ver `CHANGELOG.md` y DEPLOY §§3–4). Una variante
> **web** del instalador (estilo WordPress, con autodeshabilitado e `install.lock`) sigue
> como idea futura, aunque su beneficio es limitado: lo duro (dominio, vhost, HTTPS, subir
> archivos, crear BD+usuario MySQL, `config.php`) exige igualmente acceso al servidor.

- [ ] **Transporte de correo alternativo (SMTP)**: hoy el envío es solo vía Resend (API
      HTTP, `lib/Mailer.php`). Ofrecer SMTP para quien prefiera su propio servidor — choca
      con la filosofía «sin dependencias»; valorar abstraer un `MailTransport` con
      back-ends `resend`|`smtp`.
- [ ] **Circuit-breaker hacia Kobo en el cron de sync** *(de la revisión de jul-2026)* —
      si Kobo está degradado (timeouts en cadena), el cron reintenta formulario a
      formulario cada 15 min sin enfriamiento. Contar fallos consecutivos por cuenta y
      saltarse el resto de la corrida (o esperar exponencialmente) tras N fallos.
- [ ] **Registro de errores de API consultable** *(de la revisión de jul-2026)* — los
      errores de sync quedan en `forms.last_sync_error` y los cron en `/health`, pero no
      hay un historial de errores de endpoints consultable por el admin (tabla
      `error_log` + vista). Valorar si la auditoría + logs del servidor no bastan.

---

## Ampliaciones futuras (del plan original)

- [ ] **Versión de escritorio** con Tauri (envuelve el mismo frontend Vue).
- [ ] **Webhooks de Kobo** para sincronización en cuasi-tiempo-real (en vez de cron cada 15 min).
- [ ] **Notificaciones por otros canales** (Telegram, Slack, WhatsApp).
- [ ] **Permiso `can_delete`** cuando exista la funcionalidad de borrado de envíos.
- [ ] **Permisos por período de tiempo** (acceso a envíos de un rango de fechas).
- *(2FA subido a la sección «Seguridad y privacidad — PRIORIDAD» al inicio del roadmap.)*

---

*Cuando una tarea se complete, muévela a `CHANGELOG.md` con su fecha.*
