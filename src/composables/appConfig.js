import { ref } from 'vue'
import { publicApi } from '../services/api'

/**
 * Ajustes públicos de la interfaz (módulo singleton) — hoy, el congelado de
 * columnas en tablas (`table_freeze`: 'first'|'none', ajuste global del admin).
 *
 * Se lee de `GET /config` al arrancar y se cachea en localStorage para que las
 * tablas pinten bien desde el primer render (sin salto al llegar la respuesta).
 * El tema oscuro tiene su propio módulo (darkMode.js) por su lógica especial
 * de preferencia local; este se queda con el resto de ajustes de UI.
 */
const CACHE_KEY = 'km.cfg.tableFreeze'
const VALID = ['first', 'none']

const cached = localStorage.getItem(CACHE_KEY)
const tableFreeze = ref(VALID.includes(cached) ? cached : 'first')

publicApi
  .get('/config')
  .then(({ data }) => {
    const v = data.data.table_freeze
    if (VALID.includes(v)) {
      tableFreeze.value = v
      localStorage.setItem(CACHE_KEY, v)
    }
  })
  .catch(() => { /* sin red: vale el valor cacheado o el default */ })

/** ¿Se congela la primera columna de las tablas? (reactivo) */
export function useTableFreeze() {
  const freezeFirst = () => tableFreeze.value === 'first'
  return { tableFreeze, freezeFirst }
}
