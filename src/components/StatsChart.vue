<script setup>
import { computed, watchEffect } from 'vue'
import { Bar, Doughnut } from 'vue-chartjs'
import { useDarkMode } from '../composables/darkMode'
import {
  Chart as ChartJS,
  Title,
  Tooltip,
  Legend,
  BarElement,
  CategoryScale,
  LinearScale,
  ArcElement,
  LineController,
  LineElement,
  PointElement,
} from 'chart.js'

// LineController/LineElement/PointElement permiten datasets `type:'line'` dentro de un
// gráfico de barras (mixto): se usa para la línea de total ACUMULADO sobre «Envíos por
// día/mes».
ChartJS.register(
  Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale, ArcElement,
  LineController, LineElement, PointElement,
)

// Plugin propio (sin dependencias) para dibujar el valor —y el % si se da una base—
// sobre cada barra/segmento, no solo en el hover (clave en móvil). Solo actúa si el
// gráfico declara `options.plugins.valueLabels`; el resto de gráficos no se ven afectados.
//   { base?: number }  base>0 → añade «(p%)»; en doughnut, base por defecto = suma del dataset.
// ¿El color es claro? (luminancia) → para elegir texto oscuro encima. Acepta #rgb/#rrggbb
// y rgb()/rgba(). Si no se puede interpretar, asume oscuro (texto blanco, comportamiento previo).
function isLightColor(color) {
  if (typeof color !== 'string') return false
  let r, g, b
  const hex = color.trim().match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/i)
  if (hex) {
    let h = hex[1]
    if (h.length === 3) h = h.split('').map((c) => c + c).join('')
    r = parseInt(h.slice(0, 2), 16); g = parseInt(h.slice(2, 4), 16); b = parseInt(h.slice(4, 6), 16)
  } else {
    const m = color.match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i)
    if (!m) return false
    r = +m[1]; g = +m[2]; b = +m[3]
  }
  // Luminancia perceptual (0–255). >150 ≈ claro.
  return (0.299 * r + 0.587 * g + 0.114 * b) > 150
}

const valueLabelsPlugin = {
  id: 'valueLabels',
  afterDatasetsDraw(chart) {
    // Al registrar el plugin, Chart.js crea su namespace de opciones ({}), así que
    // hay que activarlo EXPLíCITAMENTE por gráfico con `enabled: true` (si no, se
    // dibujaría en todos, incluidos los de muchas barras donde se solaparían).
    const cfg = chart.options?.plugins?.valueLabels
    if (!cfg || !cfg.enabled) return
    const { ctx } = chart
    const type = chart.config.type
    const horiz = chart.options.indexAxis === 'y'
    ctx.save()
    ctx.font = '600 11px ui-sans-serif, system-ui, -apple-system, sans-serif'
    chart.data.datasets.forEach((ds, di) => {
      const meta = chart.getDatasetMeta(di)
      if (meta.hidden) return
      const sum = ds.data.reduce((a, b) => a + (Number(b) || 0), 0)
      const base = cfg.base != null ? cfg.base : type === 'doughnut' ? sum : null
      meta.data.forEach((el, i) => {
        const v = Number(ds.data[i])
        if (!v) return
        const pct = base && base > 0 ? Math.round((v * 100) / base) : null
        const txt = pct != null ? `${v} (${pct}%)` : `${v}`
        if (type === 'doughnut') {
          if (el.endAngle - el.startAngle < 0.3) return // segmento muy pequeño: solo en leyenda/hover
          const p = el.tooltipPosition()
          // Color de texto por contraste con el segmento: blanco sobre fondo oscuro,
          // gris oscuro sobre fondo claro (p. ej. el «sin adjuntos/sin geo» gris claro,
          // donde el blanco antes era ilegible).
          const bg = Array.isArray(ds.backgroundColor) ? ds.backgroundColor[i] : ds.backgroundColor
          ctx.fillStyle = isLightColor(bg) ? '#1e293b' : '#fff'
          ctx.textAlign = 'center'
          ctx.textBaseline = 'middle'
          ctx.fillText(txt, p.x, p.y)
          return
        }
        const w = ctx.measureText(txt).width
        // Texto FUERA de la barra: sigue al modo claro/oscuro (slate-600 se
        // invierte bajo `.dark`, ver style.css).
        const outsideColor = cssVar('--color-slate-600', '#475569')
        if (horiz) {
          ctx.textBaseline = 'middle'
          const inside = el.x - el.base > w + 12
          if (inside) {
            ctx.fillStyle = '#fff'
            ctx.textAlign = 'right'
            ctx.fillText(txt, el.x - 6, el.y)
          } else {
            ctx.fillStyle = outsideColor
            ctx.textAlign = 'left'
            ctx.fillText(txt, el.x + 6, el.y)
          }
        } else {
          ctx.fillStyle = outsideColor
          ctx.textAlign = 'center'
          ctx.textBaseline = 'bottom'
          ctx.fillText(txt, el.x, el.y - 4)
        }
      })
    })
    ctx.restore()
  },
}
ChartJS.register(valueLabelsPlugin)

const props = defineProps({
  type: { type: String, default: 'bar' }, // 'bar' | 'doughnut'
  data: { type: Object, required: true },
  options: { type: Object, default: () => ({}) },
})

const comp = computed(() => (props.type === 'doughnut' ? Doughnut : Bar))

// ---------- Modo claro/oscuro ----------
// Los colores de texto/rejilla de Chart.js se fijan como DEFAULTS globales leyendo
// los tokens slate (que se invierten bajo `.dark`); al cambiar el modo, el :key
// del template recrea el gráfico para que tome los nuevos valores.
function cssVar(name, fallback) {
  const v = getComputedStyle(document.documentElement).getPropertyValue(name).trim()
  return v || fallback
}
const { isDark } = useDarkMode()
watchEffect(() => {
  void isDark.value // dependencia: re-leer al alternar el modo
  ChartJS.defaults.color = cssVar('--color-slate-500', '#64748b')
  ChartJS.defaults.borderColor = isDark.value ? 'rgba(148, 163, 184, 0.15)' : 'rgba(0, 0, 0, 0.1)'
})
</script>

<template>
  <component :is="comp" :key="isDark" :data="data" :options="options" /></template>
