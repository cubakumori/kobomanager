# FAQs — Aclaraciones prácticas sobre los fundamentos de KoboManager

> These FAQs are written in Spanish. They explain the *reasoning* behind some of the app's
> less obvious features — the "why" and "how to interpret", not the step-by-step usage
> (that lives in the in-app **Guide**).

Esta carpeta reúne **aclaraciones de sentido práctico** sobre los fundamentos de
KoboManager: qué significan de verdad ciertas señales y cómo interpretarlas, con énfasis en
los entornos reales de uso (trabajo de campo, baja conectividad, cortes de electricidad).

No son un manual de uso —eso es la **Guía** dentro de la propia app («cómo se hace»)—, sino
la capa de **por qué** y **cómo leer** lo que la app muestra, pensada sobre todo para
quien supervisa la calidad de los datos. Se irá **ampliando** a medida que convenga aclarar
otros fundamentos.

## Índice

- **[Solapamiento](Solapamiento.md)** — las banderas de solape del Control de calidad
  («Solapada» vs «Solape con registro largo»): cómo se calculan a partir de `start`/`end`,
  por qué aparecen en cascada con los apagones y cómo distinguir la concurrencia real del
  ruido benigno.
- **[Índice de riesgo](IndiceRiesgo.md)** — la detección heurística de fabricación
  (*curbstoning*): qué mide cada señal, por qué se compara con los pares de forma robusta,
  qué convierte a un encuestador en «riesgoso» y por qué a veces «no hay a quién puntuar».
- **[Detección de fabricación](DeteccionFabricacion.md)** — la **guía práctica** que une el
  Control de calidad y el Índice de riesgo en un flujo de trabajo: de las señales al
  *back-check* (re-entrevista de verificación).

## Cómo se relacionan

El FAQ de **Detección de fabricación** es el punto de entrada práctico; remite a los otros
dos para el detalle de cada señal. Los tres se refieren entre sí por su nombre de fichero.
