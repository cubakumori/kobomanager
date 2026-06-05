// Etiquetas legibles para envíos.
//
// El backend devuelve, junto a los datos, `label_mode` ('labels'|'raw') y un
// `schema` resuelto al idioma del usuario:
//   { labels: { clave: texto }, options: { clave: { código: texto } }, multi: [claves] }
// Las claves de los envíos llevan ruta de grupo (`g_authors/g_person/prov`), así que
// se busca primero por la clave completa y, si no, por el nombre hoja (`prov`).

function rawValue(v) {
  return v !== null && typeof v === 'object' ? JSON.stringify(v) : String(v ?? '')
}

function leaf(key) {
  const i = String(key).lastIndexOf('/')
  return i === -1 ? key : key.slice(i + 1)
}

/** Crea un traductor de campos/valores a partir del schema y el modo activo. */
export function makeLabeler(schema, labelMode) {
  const on = labelMode === 'labels' && !!schema
  const labels = schema?.labels ?? {}
  const options = schema?.options ?? {}
  const multi = new Set(schema?.multi ?? [])

  function optionsFor(key) {
    return options[key] ?? options[leaf(key)] ?? null
  }

  function isMulti(key) {
    return multi.has(key) || multi.has(leaf(key))
  }

  // ¿Hay una label de pregunta para esta clave? (útil para priorizar columnas).
  function hasLabel(key) {
    return on && (key in labels || leaf(key) in labels)
  }

  // Etiqueta legible de la pregunta (o la clave cruda si no hay label / modo raw).
  function label(key) {
    if (!on) return key
    return labels[key] ?? labels[leaf(key)] ?? key
  }

  // Valor mostrable: mapea códigos de opción a su etiqueta (uno o varios).
  function value(key, v) {
    if (!on) return rawValue(v)
    const opt = optionsFor(key)
    if (!opt) return rawValue(v)
    if (isMulti(key)) {
      const codes = String(v ?? '').trim().split(/\s+/).filter(Boolean)
      return codes.length ? codes.map((c) => opt[c] ?? c).join(', ') : rawValue(v)
    }
    const code = String(v ?? '')
    return code in opt ? opt[code] : rawValue(v)
  }

  return { on, label, value, hasLabel, optionsFor, isMulti, leaf, rawValue }
}
