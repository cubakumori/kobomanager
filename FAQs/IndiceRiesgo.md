# FAQ — El Índice de riesgo (detección de fabricación / *curbstoning*)

> Documentación pública del proyecto (carpeta FAQs/). Explica qué es el Índice de riesgo de KoboManager, qué mide
> cada señal y por qué, cómo se combina en un número, qué convierte a un encuestador en
> «riesgoso», y cómo interpretarlo — incluido el caso frecuente «aún no hay encuestadores
> con suficientes envíos para puntuar». Refleja el comportamiento **a fecha de v1.50.0**
> (`api/lib/Risk.php`; incluye la señal `qc_flag_rate` entregada en esa versión). Al
> final, las mejoras acordadas en el ROADMAP. Para el **flujo de trabajo** que une este
> índice con el Control de calidad (de las señales al *back-check*), ver
> `DeteccionFabricacion.md`.

---

## 1. Qué es (y qué NO es)

El Índice de riesgo **prioriza a qué encuestadores conviene hacer un *back-check*** (una
re-entrevista de verificación). Lo hace agregando varias **señales estadísticas relativas a
los compañeros de equipo** en un único número por encuestador (y por equipo).

- **NO es una prueba de fraude.** Es una señal para **priorizar verificaciones**. Un índice
  alto dice «mira a esta persona primero», no «esta persona hizo trampa».
- **Nunca es un número opaco.** Cada componente se muestra con su valor real, la mediana del
  equipo y una explicación en lenguaje llano. El índice siempre viaja junto a sus partes.
- **Es opt-in.** Solo se calcula si el formulario define `forms.risk_min_n` (el número
  mínimo de encuestas por persona para puntuar). Sin ese valor, la página muestra el estado
  vacío que invita a configurarlo.

---

## 2. Idea central: comparación con los PARES, de forma ROBUSTA

La fabricación se define **en relación con el trabajo normal en ese formulario y ese
equipo**. Por eso casi todas las señales son **relativas a los pares**: se compara a cada
encuestador con **los compañeros puntuables de su equipo** (si no hay campo de equipo,
contra todos los encuestadores del formulario).

Cada señal se convierte en un **z robusto**:

```
z = (valor − mediana_de_pares) / (1.4826 · MAD)
```

donde MAD es la desviación absoluta mediana. Se usa **mediana + MAD** (robusto) en vez de
media + desviación estándar **a propósito**: así el propio tramposo no infla la base contra
la que se le compara. **Solo el z en la dirección sospechosa y positivo suma** al índice;
ser *mejor* que los pares nunca resta.

El índice combinado de un encuestador es:

```
índice = Σ  peso_señal · max(0, z_señal)
```

---

## 3. Las señales, una por una

Registradas en `Risk::METRICS`. «Dir» indica qué extremo es sospechoso: `alto` (solo un
valor alto), `bajo` (solo un valor bajo) o `abs` (ambos extremos se desvían de los pares).

| Señal | Peso | Dir | Qué mide | Por qué delata fabricación |
|---|---|---|---|---|
| **percentmatch** | **1.0** | alto | Parecido de las encuestas del encuestador **entre sí** (para cada envío, su máxima coincidencia con otro suyo; media) | El que inventa rellena encuestas casi idénticas |
| **straight-lining** | 0.6 | alto | Fracción de `select_one` que comparten la misma opción dentro de un envío | Rellenar «en línea recta» sin leer las preguntas |
| **distribution** | 0.6 | alto | Distancia (TVD) entre su distribución de respuestas y la del pool, por campo `select_one` | Responde de forma muy distinta al conjunto |
| **skip_rate** | 0.4 | **abs** | (huecos + «no sabe») / celdas de contenido | Demasiados saltos = descuidado; **demasiado pocos** = «lo sabe todo», inventado |
| **benford** | 0.5 | alto | Desvío (TVD) del primer dígito de los valores numéricos frente a la ley de Benford | Los números inventados no siguen la distribución natural de dígitos |
| **productivity** | 0.4 | alto | Entrevistas por día activo | Ritmo implausible de trabajo |
| **gps_cluster** | 0.5 | **bajo** | Distancia media de sus puntos GPS a su centroide | **Baja** = puntos apiñados → rellenadas desde un mismo sitio |
| **qc_flag_rate** *(v1.50.0)* | 0.6 | alto | Envíos con bandera **contable** del QC (corta + solape entre consecutivas + duplicada) / envíos en alcance | Le señalan mucho más que a sus pares; las banderas ruidosas en apagones (larga, hueco corto, solape con registro largo) y el GPS (ya cubierto por gps_cluster) **no cuentan** |

Además hay una **señal de nivel de equipo** (`team_distribution`): la distancia (TVD) de la
distribución de respuestas del equipo frente al pool de **todos los equipos**, z-scoreada
**entre equipos**. Detecta equipos enteros que responden distinto al resto.

### Percentmatch, en detalle (la señal dominante)

Por cada envío del encuestador se calcula su **máxima coincidencia** con cualquier otro
envío suyo (fracción de campos donde **ambos respondieron** y el valor coincide); el valor
del encuestador es la **media** de esos máximos. Un *curbstoner* que copia-pega respuestas
produce un percentmatch alto. En el drill-down se acompaña de `p90`, del nº de pares
«casi idénticos» (≥ 0.95) y de si se muestreó (se acota a 200 envíos por encuestador para
contener el coste O(n²)).

---

## 4. Gates de volumen: por qué a veces una señal «no aplica»

Cada señal tiene un **mínimo de datos** por debajo del cual no se calcula (queda «n/d» y no
puntúa), para no inventar señal con poca evidencia:

- **percentmatch**: ≥ 2 envíos con contenido.
- **straight-lining**: ≥ 3 `select_one` respondidas por envío (`SL_MIN_FIELDS`).
- **distribution**: ≥ 20 envíos en el pool por campo (`DIST_MIN_POOL`).
- **benford**: ≥ 30 valores numéricos (`BENFORD_MIN`).
- **gps_cluster**: ≥ 5 puntos con coordenadas (`GPS_MIN_POINTS`).
- **qc_flag_rate**: sin gate de volumen propio más allá del `risk_min_n` general — el
  solape se marca siempre y basta ≥ 1 envío en alcance para tener tasa. Su condición es
  otra: que el llamador compute el Control de calidad y le pase los conteos
  (`Quality::riskRates`); en la app eso ocurre siempre que el índice está activo.

Si un formulario no tiene esquema, o no tiene campos `select_one` / numéricos / GPS, las
señales correspondientes quedan inactivas y la página explica el motivo.

---

## 5. Qué convierte a un encuestador en «riesgoso»

- Un encuestador se **puntúa** solo si tiene **al menos `risk_min_n` envíos en alcance**
  (ver §7). Los demás quedan como «datos insuficientes».
- Se considera **sospechoso** cuando su índice combinado supera el **umbral de sospecha**
  (`SUSPICION_Z = 2.0`). Cada componente, además, se etiqueta por nivel: `elevated` a partir
  de z ≥ 1.0 y `high` a partir de z ≥ 2.0.
- En resumen: es «riesgoso» quien **destaca por varias de estas señales frente a sus propios
  compañeros de equipo**, no quien supera un valor absoluto.

### El índice del equipo

El índice de un equipo **no es la media de sus miembros** (eso diluiría al tramposo): es el
índice **del peor miembro**. Aparte, el equipo lleva un contador «alberga N sospechosos»
(cuántos superan el umbral) y el nombre del peor.

---

## 6. Qué NO mira el Índice de riesgo (importante)

El Índice de riesgo y el Control de calidad siguen siendo **subsistemas distintos**:

- **Control de calidad** = física por envío (duración corta/larga, hueco corto, solape,
  duplicados exactos, GPS clavado), con umbrales absolutos que tú fijas.
- **Índice de riesgo** = estadística por encuestador, relativa a los pares.

Desde **v1.50.0** existe un puente controlado entre ambos: la señal `qc_flag_rate` (§3)
lleva al índice la **tasa** de envíos señalados por el QC — pero solo con las banderas
limpias (corta + solape entre consecutivas + duplicada). Lo que el índice sigue **sin**
mirar, a propósito:

- **Larga, hueco corto y solape con registro largo**: ruido puro con apagones (un
  formulario colgado las dispara solas).
- **GPS clavado** como bandera: el índice ya tiene `gps_cluster` como señal propia y
  contarla dos veces inflaría el mismo fenómeno.
- Los **valores absolutos** del QC: al índice solo llega la tasa **relativa a los
  pares**, así que el ruido sistémico (a todos se les disparan banderas en la misma
  campaña) se cancela y solo destaca el atípico.

Consecuencia útil en tu entorno (baja conectividad / apagones): las señales nativas del
índice **no usan relojes** (salvo el *día* de `submitted_at` para productividad) —
percentmatch, straight-lining, distribución, Benford y GPS son inmunes al reloj roto — y
la única que toca tiempos (`qc_flag_rate`, vía la bandera «corta» y el solape real) es
relativa a pares. Sigue siendo **la herramienta más fiable de las dos** cuando las horas
de `start`/`end` son ruidosas. (Ver `Solapamiento.md`.)

---

## 7. «Aún no hay encuestadores con suficientes envíos para puntuar»

Este mensaje **no es un fallo**: significa que ningún encuestador alcanza `risk_min_n`
envíos **dentro del alcance evaluado**. Las dos causas, casi siempre combinadas:

1. **El alcance por estado de revisión.** Por defecto el índice se alimenta **solo de
   pendientes / en espera** (los aprobados/rechazados ya pasaron revisión humana). Si ya
   revisaste y aprobaste casi todo, el índice está mirando un puñado de envíos, repartidos
   entre muchos encuestadores → nadie llega a `risk_min_n`.
2. **`risk_min_n` demasiado alto** para el volumen en alcance.

**Ejemplo real (form 4):** `risk_min_n = 10`, y de ~1345 envíos había **986 aprobados, 327
rechazados, 32 pendientes**. Con el alcance por defecto, el índice miraba solo 32 → 0
puntuados. Al cambiar el alcance a **«Todos»**, pasó a **40 puntuados** (57 con datos
insuficientes) y los equipos aparecieron rankeados.

### Cómo cambiarlo (desde v1.48.0)

La página de riesgo tiene ahora —igual que el Control de calidad— un **chip-botón de
alcance**: alterna entre **«Pendientes/En espera»** y **«Todos»** por-petición, y **recuerda
la elección por dispositivo**. El enlace «Cambiar» (admin) lleva al ajuste **global** por
defecto (Configuración → Paneles). También puedes bajar `risk_min_n` en los ajustes del
formulario si tu volumen por persona es pequeño.

> Matiz de diseño: que el índice mire por defecto solo lo no revisado tiene lógica —está
> pensado para **priorizar back-checks antes de aprobar**—. Una vez apruebas casi todo, ya
> no queda nada que priorizar en ese alcance; para una mirada retrospectiva, usa «Todos».

---

## 8. El papel de la normalización del eje encuestador

Si el encuestador es **texto libre** (iniciales tecleadas), la misma persona puede aparecer
como «ABC», «abc», «A.B.C». Sin normalizar, cada grafía es un «encuestador» distinto con
pocos envíos cada uno → **nadie alcanza `risk_min_n`** y los cohortes de pares quedan
contaminados por «medio-encuestadores». Con la normalización/alias (1.46.0) activada, las
variantes se pliegan, cada persona alcanza su volumen real y las comparaciones con pares son
limpias. Para el índice esto es **doblemente importante**.

---

## 9. Preguntas rápidas

**¿Un índice alto prueba que alguien hizo trampa?**
No. Prioriza a quién verificar. La confirmación es el *back-check*.

**¿Por qué se compara con los compañeros y no con un umbral fijo?**
Porque «normal» depende del formulario, la zona y el equipo. Lo sospechoso es **desviarse de
los pares**, y el centrado robusto (mediana/MAD) evita que el propio tramposo mueva la
referencia.

**¿Puedo saber por qué un encuestador puntúa alto?**
Sí: se despliega en sus componentes, cada uno con su valor, la mediana del equipo, su z y su
nivel (elevated/high), más explicación.

**Tengo mil y pico de envíos y dice que no hay a quién puntuar. ¿Por qué?**
Casi seguro por el alcance (solo pendientes/en espera) con casi todo ya aprobado. Cambia el
alcance a «Todos» en la propia página (§7).

**¿El índice detecta solapes o encuestas cortas?**
Desde **v1.50.0**, sí — como **tasa relativa a pares** (`qc_flag_rate`, §3): cuenta los
envíos con bandera limpia del QC (corta, solape entre consecutivas, duplicada) sobre los
envíos en alcance, y solo destaca quien está señalado mucho más que sus compañeros (§6).

---

## 10. Mejoras acordadas (ROADMAP)

- ✅ **Señal de riesgo relativa a pares basada en la tasa de banderas de QC —
  ENTREGADA en v1.50.0** (`qc_flag_rate`, peso 0.6, dir `alto`; ver §3 y §6): «envíos
  con bandera contable / envíos en alcance», z-scoreada contra los pares, reutilizando
  los conteos que el QC ya calcula (`Quality::riskRates`, sin recomputar). Banderas
  contables: corta + «solape entre consecutivas» (de la clasificación de solapes de
  v1.49.0) + duplicada; excluidas larga/hueco-corto/solape-con-registro-largo (ruido de
  apagones) y GPS clavado (ya cubierta por `gps_cluster`).
- **Índice de riesgo — Fase 2 (histórico y `audit` de Kobo):** sincronizar el `audit.csv`
  por envío (tiempo por pregunta) y un histórico semanal persistido. Frente mayor,
  condicionado a que el formulario recoja la pregunta tipo `audit`.

La Fase 2 queda para una sesión de implementación futura.
