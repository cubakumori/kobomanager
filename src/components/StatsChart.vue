<script setup>
import { computed } from 'vue'
import { Bar, Doughnut } from 'vue-chartjs'
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
        const pct = base && base > 0 ? Math.round((v * 100) / base) : null
        const txt = pct != null ? `${v} (${pct}%)` : `${v}`
        if (type === 'doughnut') {
          if (el.endAngle - el.startAngle < 0.3) return // segmento muy pequeño: solo en leyenda/hover
          const p = el.tooltipPosition()
          ctx.fillStyle = '#fff'
          ctx.textAlign = 'center'
          ctx.textBaseline = 'middle'
          ctx.fillText(txt, p.x, p.y)
          return
        }
        const w = ctx.measureText(txt).width
        if (horiz) {
          ctx.textBaseline = 'middle'
          const inside = el.x - el.base > w + 12
          if (inside) {
            ctx.fillStyle = '#fff'
            ctx.textAlign = 'right'
            ctx.fillText(txt, el.x - 6, el.y)
          } else {
            ctx.fillStyle = '#475569'
            ctx.textAlign = 'left'
            ctx.fillText(txt, el.x + 6, el.y)
          }
        } else {
          ctx.fillStyle = '#475569'
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
</script>

<template>
  <component :is="comp" :data="data" :options="options" />
</template>
