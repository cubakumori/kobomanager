import { createI18n } from 'vue-i18n'

export const SUPPORTED_LOCALES = ['es', 'en']
export const DEFAULT_LOCALE = 'es'

// Catálogos por área en locales/{locale}/{área}.json. Cada fichero contiene
// namespaces completos de primer nivel (p. ej. common.json → common, nav, lang,
// errors), así que las claves $t('ns.clave') no llevan prefijo de fichero.
// Añadir un fichero nuevo no requiere tocar este cargador.
//
// Solo el locale por defecto (es) va en el bundle principal; el resto se carga
// bajo demanda en setLocale() (chunks aparte, ~15-20 KB gz menos al arrancar).
// El cambio de idioma solo se aplica DESPUÉS de registrar los mensajes, así que
// nunca se ven claves sin traducir.
//
// EXCEPCIÓN: guide.json (el catálogo más pesado, solo lo usa /guide) queda FUERA
// de ambos globs y se carga bajo demanda con loadGuideMessages() desde GuideView,
// que espera a tenerlo antes de renderizar (no se ven claves crudas).
const eagerModules = import.meta.glob(['./locales/es/*.json', '!./locales/es/guide.json'], { eager: true })
const lazyModules = {
  en: import.meta.glob(['./locales/en/*.json', '!./locales/en/guide.json']),
}
const guideModules = import.meta.glob('./locales/*/guide.json')

// Une los ficheros de un locale en un único árbol { namespace: {...} }.
function buildMessages(mods) {
  const messages = {}
  for (const mod of mods) {
    for (const [ns, entries] of Object.entries(mod.default ?? mod)) {
      messages[ns] = { ...messages[ns], ...entries }
    }
  }
  return messages
}

const loadedLocales = new Set([DEFAULT_LOCALE])

export const i18n = createI18n({
  legacy: false,
  globalInjection: true,
  locale: DEFAULT_LOCALE,
  fallbackLocale: 'es',
  messages: { [DEFAULT_LOCALE]: buildMessages(Object.values(eagerModules)) },
})

// Carga diferida del catálogo de la Guía para un locale (idempotente). Si la
// descarga falla (sin red y sin caché), no marca el locale como cargado: el
// próximo intento reintenta y, mientras, GuideView mantiene su estado de carga.
const loadedGuide = new Set()
export async function loadGuideMessages(locale) {
  const l = SUPPORTED_LOCALES.includes(locale) ? locale : DEFAULT_LOCALE
  if (loadedGuide.has(l)) return true
  const load = guideModules[`./locales/${l}/guide.json`]
  if (!load) return false
  try {
    const mod = await load()
    i18n.global.mergeLocaleMessage(l, mod.default ?? mod)
    loadedGuide.add(l)
    return true
  } catch {
    return false
  }
}

/** Cambia el idioma activo de la interfaz (y el atributo lang del documento).
 *  Si el locale aún no está cargado, lo descarga antes de aplicarlo; si la
 *  descarga falla (sin red y sin caché), conserva el idioma actual. */
export async function setLocale(locale) {
  const l = SUPPORTED_LOCALES.includes(locale) ? locale : DEFAULT_LOCALE
  if (!loadedLocales.has(l)) {
    try {
      const mods = await Promise.all(Object.values(lazyModules[l] ?? {}).map((load) => load()))
      i18n.global.setLocaleMessage(l, buildMessages(mods))
      loadedLocales.add(l)
    } catch {
      return
    }
  }
  i18n.global.locale.value = l
  document.documentElement.setAttribute('lang', l)
}

export default i18n
