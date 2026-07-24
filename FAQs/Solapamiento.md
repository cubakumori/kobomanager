# FAQ — La bandera «Solapada» del Control de calidad

> Documentación pública del proyecto (carpeta FAQs/). Explica en detalle qué significan las banderas de solape
> de la página de Control de calidad — **«solapada»** (`overlap`) y, desde v1.49.0,
> **«solape con registro largo»** (`overlap_long`) —, cómo se calculan, por qué antes
> aparecían en cascada y cómo interpretarlas, con énfasis en entornos de **baja
> conectividad y cortes de electricidad**. Refleja el comportamiento **a fecha de
> v1.50.0** (v1.49.0 entregó la clasificación de solapes y v1.50.0 llevó la señal limpia
> al Índice de riesgo — las dos mejoras acordadas en el ROADMAP, ver §10). Para el
> **flujo de trabajo** que une el Control de calidad y el Índice de riesgo (de las
> señales al *back-check*), ver `DeteccionFabricacion.md`.

---

## 1. En una frase

Una encuesta tiene solape cuando, para un mismo **encuestador** (dentro de un mismo
equipo), **empieza antes de que haya terminado otra encuesta anterior suya**. Desde
v1.49.0 el Control de calidad distingue **dos banderas** (de sus siete):

- **«Solapada»** (`overlap`, rojo): se entrelaza parcialmente con la **inmediatamente
  anterior** → concurrencia real, una persona en dos sitios a la vez. Señal —no
  prueba— de posible fabricación o de código de encuestador compartido.
- **«Solape con registro largo»** (`overlap_long`, apagada): cae dentro de la ventana de
  un formulario dejado abierto (batería/apagón/pausa) cuyo `end` tardío la engulle.
  Habitualmente **benigno**; el drill-down enlaza al registro largo culpable («dentro de
  … – …»). El culpable, además, muestra «engulle a N» en su propia fila — **solo si él
  mismo está en la lista** (es decir, si tiene alguna bandera propia, típicamente
  «larga»; sin tope de duración configurado, el registro largo no se lista, aunque las
  engullidas siguen enlazándolo).

---

## 2. ¿De dónde salen las horas? `start` y `end`

KoboManager no cronometra nada: usa dos metadatos que el propio formulario XLSForm graba en
cada envío:

- **`start`** = instante en que se **abrió** el formulario en el dispositivo.
- **`end`** = instante en que se **guardó por última vez**.

La **duración** que ves es simplemente `end − start`. Esto es clave para entender todo lo
demás: **`end` no es «cuándo terminó la entrevista», sino «cuándo se guardó por última
vez»**. Si el formulario se dejó abierto, se pausó, se reabrió o se editó más tarde, el
`end` se mueve hacia adelante y la duración se infla, sin que eso refleje tiempo real de
entrevista.

Ambas marcas vienen del **reloj del dispositivo**. Si ese reloj está mal, las horas están
mal (ver §7).

---

## 3. Cómo se calcula, exactamente

El cálculo vive en `api/lib/Quality.php`. Por cada **(equipo, encuestador)**:

1. Se toman sus envíos **con tiempos válidos** y se ordenan por **inicio ascendente**.
2. Se recorre la lista llevando `prevEndMax` = **el mayor `end` visto hasta ese momento**
   (el máximo de *todos* los anteriores) y el fin de la **inmediatamente anterior**.
3. Para cada envío: **`hueco = inicio − prevEndMax`** y **`huecoPrev = inicio − fin de la
   inmediatamente anterior`**.
   - `hueco < 0` → hay solape, y se **clasifica** (v1.49.0):
     - `huecoPrev ≥ 0` (la anterior ya había terminado) → **`overlap_long`**: el
       negativo viene solo del `end` tardío de un registro más viejo (la «cascada» de §5).
     - `huecoPrev < 0` pero la anterior la **engulle** (terminó después de ella) →
       también **`overlap_long`**: esa anterior es el propio registro largo.
     - `huecoPrev < 0` con solape **parcial** (se entrelazan) → **`overlap`**
       (solapada): concurrencia real.
   - `hueco ≥ 0` y `< qc_min_gap` → **`short_gap`** (hueco corto).

Dos consecuencias que conviene tener muy presentes:

- **El solape no tiene umbral.** Cualquier hueco negativo, aunque sea de **2 segundos**,
  marca una de las dos banderas. (El «hueco corto», en cambio, sí tiene umbral
  configurable por formulario, `qc_min_gap`.)
- **El hueco mostrado se mide contra el `end` MÁXIMO, no contra el anterior.** Antes de
  v1.49.0 esto causaba la «cascada» de §5 tiñendo todo como `overlap`; hoy la cascada
  sigue existiendo (el hueco mostrado es el mismo) pero queda **clasificada** como
  `overlap_long`, con enlace al registro largo culpable.

> Nota: la cadena de consecutividad se construye **siempre** sobre todos los envíos del
> encuestador con tiempos, con independencia del estado de revisión (la física del hueco no
> depende de si algo está aprobado o pendiente). El alcance por estado de revisión solo
> decide **qué se reporta** en la vista, no cómo se calcula el hueco.

---

## 4. ¿Qué significa un hueco negativo, en la práctica?

Que la encuesta **empezó antes de que otra encuesta anterior del mismo encuestador se
hubiera guardado (`end`)**. Es decir: dos encuestas de la misma persona **coexisten en el
tiempo**. Como una persona no puede conducir dos entrevistas a la vez, eso es, en
principio, imposible… salvo por las explicaciones benignas de §6 y §7.

---

## 5. El caso de la «cascada»: un ejemplo real

Este fue el ejemplo que motivó este documento (envíos consecutivos de un mismo
encuestador):

| # | Inicio | Fin | Duración | Hueco mostrado |
|---|--------|-----|----------|----------------|
| 1 | 10:35 | 11:55 | 1 h 19 min | **−4 h 01 min** |
| 2 | 12:08 | 14:29 | 2 h 20 min | **−2 h 27 min** |
| 3 | 14:29 | 14:36 | 7 min 11 s | **−7 min** |
| 4 | 14:36 | 14:44 | 7 min 58 s | **−11 s** |
| 5 | 14:36 | 14:44 | 8 min | **+2 s** |

A primera vista parecen cinco solapes independientes. No lo son. Si despejamos
`prevEndMax = inicio − hueco` en cada fila, sale que **en las cuatro primeras el
`prevEndMax` es siempre ~14:36**. Eso delata que existe **un envío de ese encuestador que
empezó temprano (antes de las 10:35) y terminó ~14:36** — un formulario que estuvo
**abierto varias horas**. Contra ese `end` tardío, **todo lo que empiece antes de las 14:36
sale con hueco negativo**, y el hueco se va acortando a medida que los inicios se acercan a
esa hora. La fila 5 es la primera que arranca *después* de superarse ese máximo, y por eso
su hueco vuelve a positivo (+2 s) y **no** se marca.

**Conclusión:** no son cinco concurrencias reales; es **un solo registro «largo» (abierto
mucho tiempo) que contamina en cascada** a las encuestas que caen dentro de su ventana.
Desde v1.49.0 la app clasifica esta cascada: las filas cuyo hueco negativo proviene solo
del `end` tardío del registro largo —su predecesora inmediata ya había cerrado, o esa
predecesora las engulle— salen como **«solape con registro largo»**, con el enlace «dentro
de … – 14:36» al culpable. Las **filas 1–3** de la tabla caen ahí. La fila 5 arranca tras
superarse el máximo (+2 s) y no se marca.

> **Ojo con la fila 4.** El «hueco mostrado» se mide contra el `end` **máximo**
> (el registro largo); la clasificación usa además el hueco contra la **predecesora
> inmediata**, que puede ser otro número. La fila 4 será «solape con registro largo» o
> «solapada» según se **entrelace o no con la fila 3** (algo que depende de los segundos
> exactos, que esta tabla ilustrativa no fija). Es a propósito: la clasificación no
> «amnistía» todo lo que cae dentro de una ventana larga; separa lo **engullido**
> (benigno) de lo que **además se entrelaza con su vecina** (concurrencia real).

### Los dos casos, con horas exactas

Para verlos sin ambigüedad (el segundo es, con estas horas exactas, el test
`testInterleaveInsideLongWindowIsStillRealOverlap` de `QualityTest`):

- **Solape con registro largo** (benigno). `L` = 09:00→14:00 (formulario colgado);
  `A` = 10:00→10:30 cae dentro de `L` → `overlap_long` (culpable `L`, «dentro de
  09:00 – 14:00»); `B` = 11:00→11:20 también dentro de `L`, y su predecesora `A` ya
  cerró (hueco contra `A` = +30 min) → `overlap_long` (culpable `L`).
- **Solapada real** (concurrencia). `L` = 08:00→14:00; `A` = 10:00→10:30 cae dentro de
  `L` → `overlap_long` (culpable `L`); pero `B` = 10:20→10:50 **arranca antes de cerrarse
  `A`** y termina después que ella → se entrelazan con la predecesora inmediata →
  **`overlap`** (sin `long_uid`; no lo tapa el registro largo).

Es decir: dentro de la ventana de un registro largo aún puede haber concurrencia real, y
la bandera la separa.

---

## 6. ¿Cuándo un solape es legítimo o explicable?

- **Formulario dejado abierto o en pausa.** El encuestador abre una encuesta, la deja a
  medias (comida, otra tarea, se acaba la batería) y la cierra/guarda horas después. Su
  `end` se dispara y «engulle» las encuestas hechas mientras tanto. **Es la causa más común
  del ejemplo de §5.**
- **Edición o reapertura posterior** del envío: reabrir y volver a guardar mueve el `end`
  hacia adelante y genera solape artificial con lo que vino después.
- **Formularios largos por naturaleza** (muchas secciones, se rellenan en varias sentadas).

---

## 7. Por qué esto importa MÁS en baja conectividad y con cortes de luz

En un entorno con **electricidad intermitente y red pobre**, las explicaciones benignas
dejan de ser excepción y pasan a ser la norma:

- **Formularios colgados por apagón.** Un corte de luz o un teléfono que se queda sin
  batería a mitad de entrevista deja el formulario abierto; al recargar y guardar, el `end`
  salta horas o días. Es exactamente el «registro largo» de §5. **En tu contexto, un solape
  aislado —sobre todo uno que engulle a varios— es casi siempre benigno.**
- **Reloj del dispositivo poco fiable.** Un Android barato que pierde la alimentación puede
  perder la hora, y **sin conectividad no hay sincronización horaria** (NITZ/NTP). Un reloj
  mal puesto produce órdenes imposibles: `end` antes de `start`, duraciones negativas,
  solapes entre teléfonos distintos. KoboManager ancla las marcas sin zona horaria a UTC,
  pero **eso no puede corregir un reloj equivocado en origen**.
- **La duración se contamina a la vez.** El mismo formulario colgado marca una duración
  enorme → dispara también la bandera **«larga»**. Es decir, «solapada» y «larga» suelen ser
  **el mismo artefacto** visto por dos banderas.

**Implicación práctica para la configuración del QC en este entorno:**

- Deja el umbral de **duración máxima** desactivado o muy holgado (los apagones lo disparan
  solos).
- Trata un solape/una larga **aislados** como ruido.
- Mira el **patrón por encuestador**, no el envío suelto: muchos solapes concentrados en una
  persona, y acompañados de otras señales, es lo que vale.
- Apóyate más en las señales que **no dependen del reloj**: duplicados de respuestas, GPS
  clavado y, sobre todo, el **Índice de riesgo** (sus señales nativas no usan tiempos, y
  la tasa de banderas del QC que incorpora desde v1.50.0 solo cuenta las banderas
  limpias y es relativa a pares — ver `IndiceRiesgo.md`).

---

## 8. ¿Cuándo un solape sí es señal de alarma?

- **Código de encuestador compartido.** Si varias personas de campo usan el mismo código o
  las mismas iniciales, sus entrevistas *son* genuinamente simultáneas, pero para el sistema
  es «una persona en dos sitios a la vez». (Aquí ayuda la normalización/alias del eje
  encuestador, 1.46.0, para al menos no fragmentar a la misma persona.)
- **Fabricación / *curbstoning*.** Solapes **concentrados en un mismo encuestador**, sobre
  todo con **duraciones muy cortas** (como las filas 3–5 del ejemplo) y acompañados de
  **respuestas duplicadas** o **GPS repetido**, sugieren copiar-pegar, rellenar «en lote» a
  posteriori o inventar envíos.

La regla de oro: **un solape aislado casi nunca significa nada; el patrón sí.** Por eso la
bandera es una señal para priorizar una verificación (*back-check*), nunca un veredicto.

---

## 9. Preguntas rápidas

**¿Por qué el «hueco» sale negativo si estas encuestas parecen seguidas?**
Porque el hueco se mide contra el **mayor `end` anterior**, no contra el de la encuesta
justo anterior. Si hay un formulario largo/abierto antes, su `end` tardío hace negativos
todos los huecos posteriores que empiecen antes de esa hora.

**¿Un hueco de −2 segundos cuenta como solape?**
Sí. El solape no tiene tolerancia: cualquier valor negativo marca una de las dos banderas
(la clasificación decide cuál). (El «hueco corto» sí tiene umbral, `qc_min_gap`.)

**¿La duración larga y el solape están relacionados?**
Muy a menudo sí: un formulario dejado abierto produce a la vez una duración inflada
(«larga») y solapes en cascada con lo que se hizo dentro de su ventana. Misma causa, dos
banderas.

**¿Afecta el estado de revisión (aprobado/pendiente) a que se marque?**
No al cálculo: la física del hueco se computa sobre todos los envíos con tiempos. El estado
solo influye en **qué se muestra/reporta** según el alcance elegido en la página.

**¿Puedo desactivar la bandera de solape?**
No de forma independiente hoy. Puedes moderar «duración máxima» y «hueco corto» (umbrales
por formulario), pero el solape se marca siempre que haya hueco negativo. Desde v1.49.0,
eso sí, el ruido del apagón sale como «solape con registro largo» y la alarma de verdad
como «solapada»; ambas siguen contando como no admitidas.

---

## 10. Mejoras acordadas (ROADMAP)

Tras revisar este comportamiento en un entorno de baja conectividad, se acordaron
(jul-2026, prioritario) dos mejoras para separar el ruido de la señal:

- ✅ **Distinguir «solape con registro largo» de «solape entre consecutivas» —
  ENTREGADA en v1.49.0** (es el comportamiento descrito en §1 y §3): dos banderas,
  clasificación por el hueco contra la inmediatamente anterior, enlace al registro
  largo culpable y contador «engulle a N» en su fila del drill-down (si el registro
  largo se lista, ver §1).
- ✅ **Llevar esta señal (ya limpia) al Índice de riesgo — ENTREGADA en v1.50.0**: la
  tasa de banderas relativa a los pares (`qc_flag_rate`, ver `IndiceRiesgo.md` §3 y
  §6) — la «solapada» (entre consecutivas) entra en la tasa; el solape con registro
  largo queda fuera por ruidoso.

Ambas mejoras están entregadas; este documento describe el comportamiento vigente.
