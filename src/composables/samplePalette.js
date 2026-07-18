import { useSampleConfig } from './appConfig'
import { useDarkMode } from './darkMode'

/**
 * Paleta de CUMPLIMIENTO del panel de muestra (ajuste global del admin, ver
 * Configuración → «Paneles» → Muestras). Gobierna TODO el panel: mapa de calor,
 * semáforo, doughnut de resumen, barras de avance (total general, modo lineal),
 * textos de «cumplido» (✓/+n) y el «hecho» del gráfico de barras. La
 * «Distribución observada» no codifica cumplimiento (no hay objetivos), pero
 * adopta el TONO neutro de la familia para que el panel sea una sola paleta.
 *
 * Presets con pares fondo/texto CURADOS (contraste garantizado, nada de
 * combinaciones libres): 'classic' (rojo→verde), 'soft' (pastel), 'accessible'
 * (azul↔naranja, seguro para daltonismo) y 'mono' (un solo color cuya OPACIDAD
 * codifica el cumplimiento; color del ajuste o, vacío, el primario del tema).
 * «Fuera de plan» queda FUERA de la paleta (ámbar fijo): es otra semántica
 * (sin meta definida), no un grado de cumplimiento.
 */

// Tramos del mapa de calor: [<25, <50, <75, <100, ≥100] %.
const HEAT = {
  classic: [
    'bg-red-500 text-white',
    'bg-orange-400 text-orange-950',
    'bg-amber-300 text-amber-950',
    'bg-lime-300 text-lime-950',
    'bg-success-500 text-white',
  ],
  soft: [
    'bg-red-200 text-red-900',
    'bg-orange-200 text-orange-900',
    'bg-amber-200 text-amber-900',
    'bg-lime-200 text-lime-900',
    'bg-success-200 text-success-900',
  ],
  accessible: [
    'bg-orange-600 text-white',
    'bg-orange-400 text-orange-950',
    'bg-orange-200 text-orange-950',
    'bg-sky-300 text-sky-950',
    'bg-sky-600 text-white',
  ],
}

// Semáforo: cumplido / en ritmo / atrasado ('none' = sin objetivo, gris fijo).
const TRAFFIC = {
  classic: { met: 'bg-success-500', onpace: 'bg-amber-400', behind: 'bg-red-500' },
  soft: { met: 'bg-success-300', onpace: 'bg-amber-200', behind: 'bg-red-300' },
  accessible: { met: 'bg-sky-600', onpace: 'bg-sky-300', behind: 'bg-orange-500' },
}

// Color «hecho» del doughnut de resumen: token CSS + fallback («falta» = gris fijo).
const DONE = {
  classic: ['--color-success-600', '#16a34a'],
  soft: ['--color-success-400', '#4ade80'],
  accessible: ['--color-sky-600', '#0284c7'],
}

// Barras de avance (total general y modo lineal): [en curso, cumplido]. En
// 'accessible' el «en curso» NO es naranja (una barra a medias no está «mal»,
// solo incompleta): claro→oscuro del mismo azul.
const PROGRESS = {
  classic: ['bg-primary-500', 'bg-success-500'],
  soft: ['bg-primary-300', 'bg-success-300'],
  accessible: ['bg-sky-400', 'bg-sky-600'],
}

// Texto de «cumplido» (✓, sobre-muestra, proyección alcanzada).
const MET_TEXT = {
  classic: 'text-success-700 dark:text-success-400',
  soft: 'text-success-700 dark:text-success-400',
  accessible: 'text-sky-700 dark:text-sky-400',
}

// «Hecho» del gráfico de barras (hex para Chart.js; «objetivo» = gris fijo).
const CHART_DONE = {
  classic: '#2563eb',
  soft: '#93c5fd',
  accessible: '#0284c7',
}

// Tono neutro de la «Distribución observada» (reparto real, sin objetivos).
const DIST = {
  classic: 'bg-primary-400',
  soft: 'bg-primary-300',
  accessible: 'bg-sky-400',
}

// Opacidad del preset 'mono' por tramo del mapa de calor y estado del semáforo.
const MONO_HEAT_ALPHA = [0.18, 0.35, 0.55, 0.75, 1]
const MONO_TRAFFIC_ALPHA = { met: 1, onpace: 0.55, behind: 0.2 }

const themeColor = (name, fallback) =>
  getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback

function hexToRgb(hex) {
  const m = String(hex).trim().match(/^#([0-9a-f]{6})$/i)
  if (!m) return { r: 37, g: 99, b: 235 } // primary-600 de fábrica
  const n = parseInt(m[1], 16)
  return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 }
}

export function useSamplePalette() {
  const { samplePalette, sampleMonoColor } = useSampleConfig()
  const { isDark } = useDarkMode()

  const monoColor = () => sampleMonoColor.value || themeColor('--color-primary-600', '#2563eb')

  const monoRgba = (alpha) => {
    const { r, g, b } = hexToRgb(monoColor())
    return `rgba(${r}, ${g}, ${b}, ${alpha})`
  }

  // Estilo del preset 'mono': rgba(color, alpha) + texto por luminancia del color
  // EFECTIVO (mezclado con el fondo del modo claro/oscuro, para que un chip tenue
  // en oscuro no lleve texto oscuro invisible).
  function monoStyle(alpha) {
    const { r, g, b } = hexToRgb(monoColor())
    const base = isDark.value ? { r: 15, g: 23, b: 42 } : { r: 255, g: 255, b: 255 } // slate-900 | blanco
    const er = r * alpha + base.r * (1 - alpha)
    const eg = g * alpha + base.g * (1 - alpha)
    const eb = b * alpha + base.b * (1 - alpha)
    const light = 0.299 * er + 0.587 * eg + 0.114 * eb > 150
    return {
      backgroundColor: monoRgba(alpha),
      color: light ? '#0f172a' : '#fff',
    }
  }

  // Chip del mapa de calor para un % (0–100+): { class, style } listos para v-bind.
  function heatChip(pct) {
    const idx = pct >= 100 ? 4 : pct >= 75 ? 3 : pct >= 50 ? 2 : pct >= 25 ? 1 : 0
    if (samplePalette.value === 'mono') return { class: '', style: monoStyle(MONO_HEAT_ALPHA[idx]) }
    return { class: HEAT[samplePalette.value]?.[idx] ?? HEAT.classic[idx], style: null }
  }

  // Celda del semáforo: state ∈ 'met'|'onpace'|'behind'|'none'.
  function trafficChip(state) {
    if (state === 'none') return { class: 'bg-slate-200', style: null }
    if (samplePalette.value === 'mono') {
      return { class: '', style: { backgroundColor: monoRgba(MONO_TRAFFIC_ALPHA[state]) } }
    }
    return { class: TRAFFIC[samplePalette.value]?.[state] ?? TRAFFIC.classic[state], style: null }
  }

  // Color «hecho» del doughnut de resumen.
  function doneColor() {
    if (samplePalette.value === 'mono') return monoColor()
    const [token, fallback] = DONE[samplePalette.value] ?? DONE.classic
    return themeColor(token, fallback)
  }

  // Relleno de una barra de avance: met=true → color de «cumplido».
  function barFill(met) {
    if (samplePalette.value === 'mono') {
      return { class: '', style: { backgroundColor: monoRgba(met ? 1 : 0.55) } }
    }
    const [progress, done] = PROGRESS[samplePalette.value] ?? PROGRESS.classic
    return { class: met ? done : progress, style: null }
  }

  // Texto de «cumplido» (✓ / sobre-muestra / proyección alcanzada).
  function metText() {
    if (samplePalette.value === 'mono') return { class: '', style: { color: monoColor() } }
    return { class: MET_TEXT[samplePalette.value] ?? MET_TEXT.classic, style: null }
  }

  // «Hecho» del gráfico de barras (hex para Chart.js).
  function chartDone() {
    if (samplePalette.value === 'mono') return monoColor()
    return CHART_DONE[samplePalette.value] ?? CHART_DONE.classic
  }

  // Barras de la «Distribución observada» (tono neutro de la familia).
  function distBar() {
    if (samplePalette.value === 'mono') return { class: '', style: { backgroundColor: monoRgba(0.7) } }
    return { class: DIST[samplePalette.value] ?? DIST.classic, style: null }
  }

  return { samplePalette, heatChip, trafficChip, doneColor, barFill, metText, chartDone, distBar }
}
