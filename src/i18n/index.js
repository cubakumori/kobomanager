import { createI18n } from 'vue-i18n'

export const SUPPORTED_LOCALES = ['es', 'en']
export const DEFAULT_LOCALE = 'es'

// Catálogos por área en locales/{locale}/{área}.json. Cada fichero contiene
// namespaces completos de primer nivel (p. ej. common.json → common, nav, lang,
// errors), así que las claves $t('ns.clave') no llevan prefijo de fichero.
// Añadir un fichero nuevo no requiere tocar este cargador.
const modules = import.meta.glob('./locales/*/*.json', { eager: true })

const messages = {}
for (const [path, mod] of Object.entries(modules)) {
  const locale = path.split('/')[2]
  messages[locale] ??= {}
  for (const [ns, entries] of Object.entries(mod.default)) {
    messages[locale][ns] = { ...messages[locale][ns], ...entries }
  }
}

export const i18n = createI18n({
  legacy: false,
  globalInjection: true,
  locale: DEFAULT_LOCALE,
  fallbackLocale: 'es',
  messages,
})

/** Cambia el idioma activo de la interfaz (y el atributo lang del documento). */
export function setLocale(locale) {
  const l = SUPPORTED_LOCALES.includes(locale) ? locale : DEFAULT_LOCALE
  i18n.global.locale.value = l
  document.documentElement.setAttribute('lang', l)
}

export default i18n
