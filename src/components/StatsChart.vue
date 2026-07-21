<script setup>
import { computed, watchEffect } from 'vue'
import { useI18n } from 'vue-i18n'
import { Bar, Doughnut } from 'vue-chartjs'
import { useDarkMode } from '../composables/darkMode'
import { usePctFormat } from '../composables/appConfig'

// Formato global de porcentajes para las etiquetas «valor (p%)» del plugin.
// El plugin dibuja fuera del ciclo reactivo: lee esta variable en cada draw.
const { formatPctNumber } = usePctFormat()
const { locale } = useI18n()
let chartLocale = locale.value
watchEffect(() => { chartLocale = locale.value })
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
        const pct = base && base > 0 ? formatPctNumber((v * 100) / base, chartLocale) : null
        const txt = pct != null ? `${v} (${pct}%)` : `${v}`
        if (type === 'doughnut') {
          if (el.endAngle - el.startAngle < 0.3) return // segmento muy pequeño: solo en leyenda/hover
          const p = el.tooltipPosition()
          // Texto BLANCO con sombra oscura: legible sobre cualquier fondo — el del
          // segmento (claro u oscuro) Y el de la página cuando la etiqueta lo pisa
          // (una cifra a caballo entre el arco y el fondo antes se fundía con él).
          ctx.fillStyle = '#fff'
          ctx.shadowColor = 'rgba(0, 0, 0, 0.85)'
          ctx.shadowBlur = 4
          ctx.shadowOffsetX = 0
          ctx.shadowOffsetY = 1
          ctx.textAlign = 'center'
          ctx.textBaseline = 'middle'
          ctx.fillText(txt, p.x, p.y)
          // La sombra no debe contaminar otras etiquetas (barras del mismo draw).
          ctx.shadowColor = 'transparent'
          ctx.shadowBlur = 0
          ctx.shadowOffsetY = 0
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
