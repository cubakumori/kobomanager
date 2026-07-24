# FAQ — Detectar fabricación y priorizar *back-checks* (QC + Índice de riesgo)

> Documentación pública del proyecto (carpeta FAQs/). Es la **guía práctica** que une las dos herramientas de
> KoboManager para vigilar la calidad del trabajo de campo: el **Control de calidad**
> (banderas por envío) y el **Índice de riesgo** (señales por encuestador relativas a los
> pares). Los dos FAQ hermanos —`Solapamiento.md` y `IndiceRiesgo.md`— explican
> **una señal cada uno en profundidad**; este responde a la pregunta operativa: *«tengo
> banderas y un índice, ¿qué hago con esto?»*. Pensado para el **supervisor de campo** en
> entornos de **baja conectividad y cortes de electricidad**. Refleja el comportamiento
> **a fecha de v1.50.x**.

---

## 1. En una frase

Ninguna bandera ni ningún índice **prueban** fraude: son señales para **priorizar a quién
re-entrevistar** (*back-check*). El trabajo es convertir «muchas señales» en «una lista
corta de personas a verificar», separando el **ruido del apagón** de la **concurrencia o
fabricación real**.

---

## 2. Las dos herramientas, en una frase cada una

- **Control de calidad** (`forms/<id>/quality`): **física por envío**. Marca cada encuesta
  que se sale de los umbrales admisibles del formulario, con **banderas** concretas (§3) y
  acceso a cada envío infractor. Depende del **reloj del dispositivo** para las banderas de
  tiempo. Ver `Solapamiento.md` para el detalle del solape.
- **Índice de riesgo** (`forms/<id>/risk`): **estadística por encuestador, relativa a los
  pares**. Agrega señales que **no dependen del reloj** (parecido entre respuestas, Benford,
  GPS, distribución…) y las compara con los compañeros de equipo. Opt-in por formulario
  (`risk_min_n`). Ver `IndiceRiesgo.md`.

La clave: en tu entorno, **el reloj miente a menudo** (apagones, teléfonos sin hora), así
que el índice suele ser más fiable que las banderas de tiempo. El QC te da el **detalle por
envío**; el índice te da la **prioridad por persona**.

---

## 3. Todas las banderas del QC de un vistazo

| Bandera (UI) | Qué es | ¿Ruido con apagones? | ¿Entra en el Índice de riesgo? |
|---|---|---|---|
| **Corta** | Duración < umbral mínimo | Señal (una encuesta demasiado rápida) | **Sí** (tasa `qc_flag_rate`) |
| **Larga** | Duración > umbral máximo | **Ruido** (un formulario colgado la dispara solo) | No |
| **Hueco corto** | Poco tiempo entre el fin de una y el inicio de la siguiente | **Ruido** (relojes y pausas) | No |
| **Solapada** | Se **entrelaza** con la inmediatamente anterior → concurrencia real | Señal (una persona en dos sitios a la vez) | **Sí** |
| **Solape con registro largo** | Cae dentro de la ventana de un formulario dejado abierto | **Ruido** (es la cascada del apagón; ver `Solapamiento.md`) | No |
| **Duplicada** | Otro envío del formulario tiene **exactamente** las mismas respuestas | Señal (copiar-pegar / relleno en lote) | **Sí** |
| **GPS clavado** | El mismo punto exacto en ≥3 envíos del mismo encuestador | Señal (relleno desde un sitio) | No — el índice ya lo mira con `gps_cluster` |

Las tres que cuentan para la tasa de banderas del índice (`qc_flag_rate`, peso 0.6) son
**corta + solapada + duplicada**: se eligieron precisamente porque son las que **no se
disparan solas con un apagón**. Larga, hueco corto y solape-con-registro-largo quedan fuera
por ruidosas; GPS clavado queda fuera porque el índice ya tiene su propia señal geográfica.

---

## 4. La regla de oro

**Un flag aislado casi nunca significa nada; el patrón sí.** Un solo solape, una sola
encuesta larga, un hueco corto suelto → casi siempre ruido de campo (batería, pausa, reloj
mal). Lo que importa es la **concentración en una misma persona** y la **coincidencia de
varias señales**:

- Muchas **cortas + duplicadas + GPS clavado** en un mismo encuestador → sospecha fuerte de
  relleno/fabricación.
- Un **índice de riesgo alto** (varias señales por encima de sus pares) apuntando a la misma
  persona → confírmalo.

Nunca actúes sobre un envío suelto como si fuera un veredicto: es una invitación a mirar.

---

## 5. El circuito de trabajo (de las señales al *back-check*)

1. **Configura una vez** (admin o permiso «Ajustes» del formulario):
   - Umbrales del QC. En baja conectividad, deja **«duración máxima» muy holgada o
     desactivada** (los apagones la disparan sola) y no te obsesiones con «hueco corto».
   - Activa el **Índice de riesgo** poniendo `risk_min_n` (mínimo de encuestas por persona
     para puntuar). Si el encuestador es texto libre, activa la **normalización** del eje
     para que cada persona alcance su volumen real.

2. **Lee por patrón, no por envío** (§4). En el QC, mira los **conteos por encuestador**
   (cuántas cortas, cuántas solapadas reales, cuántas duplicadas), no la fila suelta.

3. **Cruza con el Índice de riesgo.** El QC te dice *qué* envíos chirrían; el índice te dice
   *quién* chirría **más que sus compañeros**. La persona que aparece en ambos es tu
   prioridad nº 1 de *back-check*.

4. **Aparca lo dudoso** («poner en espera»). Es un cajón temporal para revisar con calma, no
   un rechazo:
   - Botón global **«Marcar en espera las N restantes»** → solo toca las **pendientes** no
     admitidas.
   - Por equipo: **«Poner en espera»** (solo pendientes) y **«Rechazar»** (que además
     incluye las que ya estaban en espera de ese equipo). El flujo pensado es: aparcar →
     revisar → despachar el lote.

5. **Haz el *back-check*.** La re-entrevista de verificación es el **único veredicto real**.
   El índice y las banderas solo te dijeron **por dónde empezar**. Prioriza a quien destaque
   en índice **y** en patrón de banderas contables.

6. **Resuelve:**
   - Lo que el *back-check* confirma como malo → **rechazar**.
   - El resto → **aprobar los admisibles en lote**: el atajo aprueba las **pendientes sin
     ninguna bandera**, y —si el índice está activo— **excluye a los encuestadores de alto
     riesgo** (índice ≥ 2.0, `SUSPICION_Z`), para no aprobar en masa a quien aún no has
     verificado. El admin decide dónde aparece ese atajo (tabla de envíos, QC, ambos o
     ninguno) en Configuración → Paneles.

---

## 6. Qué es ruido y qué es señal en tu entorno

Regla rápida para baja conectividad / apagones:

- **Sospecha del reloj antes que del encuestador.** `end` antes de `start`, duraciones
  enormes, solapes que engullen a varias → casi siempre un formulario colgado, no fraude.
- **Apóyate en lo que no depende del reloj:** respuestas duplicadas, GPS clavado y, sobre
  todo, el **Índice de riesgo** (percentmatch, Benford, distribución…). Son inmunes a un
  reloj mal puesto.
- **La tasa relativa a pares cancela el ruido sistémico.** Si a *todo el equipo* se le
  cuelgan formularios por los cortes, eso no destaca a nadie; el índice solo señala a quien
  se desvía de sus compañeros.

---

## 7. Un detalle que confunde: los números cambian según el «alcance»

Tanto el QC como el Índice de riesgo (y la Muestra) tienen un **alcance por estado de
revisión**: por defecto miran solo **pendientes / en espera** (lo aprobado o rechazado ya
pasó por revisión humana). Por eso, si ya revisaste casi todo, verás pocos o ningún envío.
Cada página tiene un **chip para cambiar el alcance a «Todos»** por-petición (se recuerda por
dispositivo), sin tocar el ajuste global. Si el índice dice «aún no hay encuestadores con
suficientes envíos para puntuar», casi siempre es esto: cambia a «Todos». Detalle en
`IndiceRiesgo.md` §7.

---

## 8. Preguntas rápidas

**Si una encuesta tiene una bandera, ¿está mal?**
No. Es un candidato a mirar. La decisión es del *back-check*, nunca de la bandera.

**¿Por qué el índice no marca a alguien con muchas banderas?**
Porque el índice es **relativo a los pares**: si a todo su equipo le pasa lo mismo (p. ej.
apagones), nadie destaca. Y solo cuentan las banderas **contables** (corta, solapada,
duplicada), no las ruidosas.

**¿Puedo aprobar en lote sin miedo?**
El atajo solo toca **pendientes sin ninguna bandera** y, con el índice activo, **excluye a
los de alto riesgo**. Aun así, aprobar es definitivo: úsalo para el grueso limpio, no como
sustituto del *back-check* de los señalados.

**¿Y si comparten código de encuestador varias personas?**
Sus encuestas *son* genuinamente simultáneas, pero el sistema las ve como «una persona en
dos sitios». Normaliza/aliasa el eje encuestador para no fragmentar a la misma persona, y
ten en cuenta que un solape entre códigos compartidos puede ser legítimo.

---

## 9. Referencias cruzadas

- `Solapamiento.md` — la bandera «Solapada» y «Solape con registro largo» en detalle
  (cómo se calcula el hueco, la cascada del apagón, la clasificación).
- `IndiceRiesgo.md` — cada señal del índice, el z robusto vs pares, los gates de
  volumen y el caso «no hay a quién puntuar».
- `CHANGELOG.md` — historia de las banderas (1.14.0 base; duplicados/GPS en 1.22.0;
  clasificación de solapes en 1.49.0; `qc_flag_rate` en 1.50.0).
