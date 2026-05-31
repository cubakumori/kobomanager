import { createI18n } from 'vue-i18n'
import es from './es.json'
import en from './en.json'

export const SUPPORTED_LOCALES = ['es', 'en']
export const DEFAULT_LOCALE = 'es'

export const i18n = createI18n({
  legacy: false,
  globalInjection: true,
  locale: DEFAULT_LOCALE,
  fallbackLocale: 'es',
  messages: { es, en },
})

/** Cambia el idioma activo de la interfaz (y el atributo lang del documento). */
export function setLocale(locale) {
  const l = SUPPORTED_LOCALES.includes(locale) ? locale : DEFAULT_LOCALE
  i18n.global.locale.value = l
  document.documentElement.setAttribute('lang', l)
}

export default i18n
