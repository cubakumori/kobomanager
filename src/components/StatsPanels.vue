<script setup>
/**
 * Render de los paneles de estadísticas a partir de un objeto `stats` ya
 * cargado. Fuente de verdad única, compartida por la vista autenticada
 * (StatsView) y la vista pública de enlaces compartidos (PublicShareView).
 *
 * La distribución por estado de revisión (`by_status`) es interna: si el objeto
 * no la trae (caso público), las tarjetas y el gráfico de revisión se omiten.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { fmtDuration } from '../composables/derived'
import StatsChart from './StatsChart.vue'

const { t } = useI18n()
const props = defineProps({
  stats: { type: Object, required: true },
})
const stats = computed(() => props.stats)

const PRIMARY = '#2563eb'

// Lee un token de color del tema (variable CSS en :root); cae al hex si aún no
// está resuelto. El verde de «aprobado» / «con ubicación» sigue el token `success`.
const themeColor = (name, fallback) =>
  getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback

const periodIsMonth = computed(() => stats.value?.period_granularity === 'month')
const periodSeries = computed(() =>
  periodIsMonth.value ? (stats.value?.by_month ?? []) : (stats.value?.by_day ?? []),
)
const ACCENT = '#059669'
const byPeriodData = computed(() => ({
  labels: periodSeries.value.map((d) => d.date),
  datasets: [
    { type: 'bar', label: t('stats.submissions'), data: periodSeries.value.map((d) => d.count), backgroundColor: PRIMARY, borderRadius: 4, order: 2 },
    {
      type: 'line',
      label: t('stats.cumulative'),
      data: periodSeries.value.map((d) => d.cumulative),
      borderColor: ACCENT,
      backgroundColor: ACCENT,
      borderWidth: 2,
      pointRadius: periodSeries.value.length > 40 ? 0 : 2,
      tension: 0.25,
      yAxisID: 'y1',
      order: 1,
    },
  ],
}))
const periodOptions = {
  responsive: true,
  maintainAspectRatio: false,
  interaction: { mode: 'index', intersect: false },
  plugins: { legend: { display: true, position: 'bottom' } },
  scales: {
    y: { beginAtZero: true, ticks: { precision: 0 }, title: { display: false } },
    y1: { beginAtZero: true, position: 'right', ticks: { precision: 0 }, grid: { drawOnChartArea: false } },
  },
}

const byStatusData = computed(() => {
  const s = stats.value?.by_status ?? { pending: 0, approved: 0, on_hold: 0, rejected: 0 }
  return {
    labels: [t('review.pending'), t('review.approved'), t('review.on_hold'), t('review.rejected')],
    datasets: [{ data: [s.pending, s.approved, s.on_hold ?? 0, s.rejected], backgroundColor: ['#f59e0b', themeColor('--color-success-600', '#16a34a'), '#0284c7', '#dc2626'] }],
  }
})

function hBar(labels, values) {
  return { labels, datasets: [{ data: values, backgroundColor: PRIMARY, borderRadius: 4 }] }
}

const byEnumeratorData = computed(() => {
  const e = stats.value?.by_enumerator ?? []
  return hBar(e.map((x) => x.name), e.map((x) => x.count))
})

const byHourData = computed(() =>
  hBar([...Array(24).keys()].map((h) => String(h).padStart(2, '0')), stats.value?.by_hour ?? []),
)

const byDowData = computed(() =>
  hBar([...Array(7).keys()].map((d) => t('derived.dow.' + d)), stats.value?.by_dow ?? []),
)

const tzLabel = computed(() => {
  const tz = stats.value?.timezone
  if (!tz) return ''
  return tz.label && tz.label !== 'UTC' && tz.label !== tz.offset
    ? t('stats.tzLabel', { label: tz.label, offset: tz.offset })
    : `(${tz.offset})`
})

const durationHistData = computed(() => {
  const h = stats.value?.duration?.histogram ?? []
  return hBar(h.map((b) => t('stats.durBucket.' + b.key)), h.map((b) => b.count))
})

const attachmentsData = computed(() => {
  const a = stats.value?.attachments ?? { with: 0, without: 0 }
  return {
    labels: [t('stats.attWith'), t('stats.attWithout')],
    datasets: [{ data: [a.with, a.without], backgroundColor: [PRIMARY, '#e2e8f0'] }],
  }
})

const geoData = computed(() => {
  const g = stats.value?.geo ?? { with: 0, without: 0 }
  return {
    labels: [t('stats.geoWith'), t('stats.geoWithout')],
    datasets: [{ data: [g.with, g.without], backgroundColor: [themeColor('--color-success-600', '#16a34a'), '#e2e8f0'] }],
  }
})

function questionData(q) {
  return hBar(q.options.map((o) => o.label), q.options.map((o) => o.count))
}

// ---- Opciones de gráficos ----
const barOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { display: false } },
  scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
}
const hBarOptions = {
  responsive: true,
  maintainAspectRatio: false,
  indexAxis: 'y',
  plugins: { legend: { display: false } },
  scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
}
const doughnutOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { position: 'bottom' } },
}

const doughnutValueOpts = (base) => ({
  ...doughnutOptions,
  plugins: { ...doughnutOptions.plugins, valueLabels: { enabled: true, base } },
})
const hBarValueOpts = (base) => ({
  ...hBarOptions,
  layout: { padding: { right: 52 } },
  plugins: { ...hBarOptions.plugins, valueLabels: { enabled: true, base } },
})
const barValueOptions = {
  ...barOptions,
  plugins: { ...barOptions.plugins, valueLabels: { enabled: true } },
}

function trendInfo(pct) {
  if (pct == null) return { text: '—', cls: 'text-slate-400', arrow: '' }
  if (pct > 0) return { text: `+${pct}%`, cls: 'text-success-600 dark:text-success-400', arrow: '▲' }
  if (pct < 0) return { text: `${pct}%`, cls: 'text-red-600 dark:text-red-400', arrow: '▼' }
  return { text: '0%', cls: 'text-slate-400', arrow: '' }
}

const showEnumerator = computed(() => {
  const e = stats.value?.by_enumerator ?? []
  const real = e.filter((x) => x.name !== '—')
  return real.length >= 2 || (stats.value?.enumerator_others ?? 0) > 0
})

const hBarHeight = (n) => Math.max(120, n * 30 + 40) + 'px'

const attByKindText = computed(() => {
  const by = stats.value?.attachments?.by_kind ?? {}
  return ['image', 'audio', 'video', 'file']
    .filter((k) => by[k])
    .map((k) => `${by[k]} ${t('derived.kind.' + k)}`)
    .join(' · ')
})
</script>

<template>
  <div class="space-y-6">
    <!-- Tarjetas resumen. Las de estado de revisión solo si el bloque interno está presente. -->
    <div class="grid gap-4 grid-cols-2 sm:grid-cols-3" :class="stats.by_status ? 'lg:grid-cols-5' : ''">
      <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <p class="text-xs uppercase tracking-wider text-slate-400">{{ $t('stats.total') }}</p>
        <p class="mt-1 text-2xl font-semibold text-slate-900">{{ stats.total }}</p>
      </div>
      <template v-if="stats.by_status">
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <p class="text-xs uppercase tracking-wider text-slate-400">{{ $t('stats.pending') }}</p>
          <p class="mt-1 text-2xl font-semibold text-amber-600 dark:text-amber-400">{{ stats.by_status.pending }}</p>
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <p class="text-xs uppercase tracking-wider text-slate-400">{{ $t('stats.approved') }}</p>
          <p class="mt-1 text-2xl font-semibold text-success-600 dark:text-success-400">{{ stats.by_status.approved }}</p>
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <p class="text-xs uppercase tracking-wider text-slate-400">{{ $t('stats.onHold') }}</p>
          <p class="mt-1 text-2xl font-semibold text-sky-600">{{ stats.by_status.on_hold ?? 0 }}</p>
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <p class="text-xs uppercase tracking-wider text-slate-400">{{ $t('stats.rejected') }}</p>
          <p class="mt-1 text-2xl font-semibold text-red-600 dark:text-red-400">{{ stats.by_status.rejected }}</p>
        </div>
      </template>
    </div>

    <!-- Gráficos base. -->
    <div class="grid gap-4 lg:grid-cols-3">
      <div v-if="stats.by_status" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h2 class="mb-4 font-semibold text-slate-900">{{ $t('stats.byStatus') }}</h2>
        <div class="h-64">
          <StatsChart type="doughnut" :data="byStatusData" :options="doughnutValueOpts(stats.total)" />
        </div>
      </div>
      <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200" :class="stats.by_status ? 'lg:col-span-2' : 'lg:col-span-3'">
        <h2 class="mb-4 font-semibold text-slate-900">{{ periodIsMonth ? $t('stats.byMonth') : $t('stats.byDay') }}</h2>
        <div class="h-64">
          <StatsChart v-if="periodSeries.length" type="bar" :data="byPeriodData" :options="periodOptions" />
          <p v-else class="text-sm text-slate-400">{{ $t('stats.noData') }}</p>
        </div>
      </div>
    </div>

    <!-- Tendencia reciente (vs periodo anterior equivalente). No en draft/archivados. -->
    <div v-if="stats.trend && !['draft', 'archived'].includes(stats.deployment_status)" class="grid gap-4 grid-cols-1 sm:grid-cols-2">
      <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <p class="text-xs uppercase tracking-wider text-slate-400">{{ $t('stats.last7') }}</p>
        <div class="mt-1 flex items-baseline gap-2">
          <p class="text-2xl font-semibold text-slate-900">{{ stats.trend.last_7 }}</p>
          <span class="text-sm font-medium" :class="trendInfo(stats.trend.pct_7).cls">
            {{ trendInfo(stats.trend.pct_7).arrow }} {{ trendInfo(stats.trend.pct_7).text }}
          </span>
        </div>
        <p class="mt-1 text-xs text-slate-400">{{ $t('stats.vsPrev', { n: stats.trend.prev_7 }) }}</p>
      </div>
      <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <p class="text-xs uppercase tracking-wider text-slate-400">{{ $t('stats.last30') }}</p>
        <div class="mt-1 flex items-baseline gap-2">
          <p class="text-2xl font-semibold text-slate-900">{{ stats.trend.last_30 }}</p>
          <span class="text-sm font-medium" :class="trendInfo(stats.trend.pct_30).cls">
            {{ trendInfo(stats.trend.pct_30).arrow }} {{ trendInfo(stats.trend.pct_30).text }}
          </span>
        </div>
        <p class="mt-1 text-xs text-slate-400">{{ $t('stats.vsPrev', { n: stats.trend.prev_30 }) }}</p>
      </div>
    </div>

    <!-- Duración -->
    <div v-if="stats.duration" class="grid gap-4 lg:grid-cols-3">
      <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h2 class="mb-4 font-semibold text-slate-900">{{ $t('stats.duration') }}</h2>
        <dl class="space-y-2 text-sm">
          <div class="flex justify-between"><dt class="text-slate-500">{{ $t('stats.durMean') }}</dt><dd class="font-medium text-slate-800">{{ fmtDuration(stats.duration.mean_s) }}</dd></div>
          <div class="flex justify-between"><dt class="text-slate-500">{{ $t('stats.durMedian') }}</dt><dd class="font-medium text-slate-800">{{ fmtDuration(stats.duration.median_s) }}</dd></div>
          <div class="flex justify-between"><dt class="text-slate-500">{{ $t('stats.durMin') }}</dt><dd class="font-medium text-slate-800">{{ fmtDuration(stats.duration.min_s) }}</dd></div>
          <div class="flex justify-between"><dt class="text-slate-500">{{ $t('stats.durMax') }}</dt><dd class="font-medium text-slate-800">{{ fmtDuration(stats.duration.max_s) }}</dd></div>
          <p class="pt-1 text-xs text-slate-400">{{ $t('stats.durBasedOn', { n: stats.duration.count }) }}</p>
        </dl>
      </div>
      <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 lg:col-span-2">
        <h2 class="mb-4 font-semibold text-slate-900">{{ $t('stats.durHistogram') }}</h2>
        <div class="h-64"><StatsChart type="bar" :data="durationHistData" :options="barValueOptions" /></div>
      </div>
    </div>

    <!-- Actividad por hora / día -->
    <div class="grid gap-4 lg:grid-cols-2">
      <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h2 class="font-semibold text-slate-900">{{ $t('stats.activityHour') }}</h2>
        <p v-if="tzLabel" class="mb-3 text-xs text-slate-400">{{ tzLabel }}</p>
        <div class="mt-4 h-64"><StatsChart type="bar" :data="byHourData" :options="barOptions" /></div>
      </div>
      <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h2 class="font-semibold text-slate-900">{{ $t('stats.activityDay') }}</h2>
        <p v-if="tzLabel" class="mb-3 text-xs text-slate-400">{{ tzLabel }}</p>
        <div class="mt-4 h-64"><StatsChart type="bar" :data="byDowData" :options="barValueOptions" /></div>
      </div>
    </div>

    <!-- Adjuntos / Geo -->
    <div class="grid gap-4 lg:grid-cols-2">
      <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h2 class="mb-1 font-semibold text-slate-900">{{ $t('stats.attachments') }}</h2>
        <p class="mb-3 text-sm text-slate-500">
          {{ $t('stats.attSummary', { pct: stats.attachments.with_pct }) }}<span v-if="attByKindText"> · {{ attByKindText }}</span>
        </p>
        <div class="h-56"><StatsChart type="doughnut" :data="attachmentsData" :options="doughnutValueOpts(stats.total)" /></div>
      </div>
      <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h2 class="mb-1 font-semibold text-slate-900">{{ $t('stats.geo') }}</h2>
        <p class="mb-3 text-sm text-slate-500">{{ $t('stats.geoSummary', { pct: stats.geo.with_pct }) }}</p>
        <div class="h-56"><StatsChart type="doughnut" :data="geoData" :options="doughnutValueOpts(stats.total)" /></div>
      </div>
    </div>

    <!-- Por enumerador (solo si hay 2+ enumeradores reales) -->
    <div v-if="showEnumerator" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <h2 class="mb-4 font-semibold text-slate-900">{{ $t('stats.byEnumerator') }}</h2>
      <div :style="{ height: hBarHeight(stats.by_enumerator.length) }">
        <StatsChart type="bar" :data="byEnumeratorData" :options="hBarValueOpts(stats.total)" />
      </div>
      <p v-if="stats.enumerator_others" class="mt-2 text-xs text-slate-400">
        {{ $t('stats.others', { n: stats.enumerator_others }) }}
      </p>
    </div>

    <!-- Distribución por pregunta (select_one / select_multiple) -->
    <section v-if="stats.by_question.length" class="space-y-4">
      <h2 class="font-semibold text-slate-900">{{ $t('stats.byQuestion') }}</h2>
      <div class="grid gap-4 lg:grid-cols-2">
        <div
          v-for="q in stats.by_question"
          :key="q.field"
          class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200"
        >
          <h3 class="text-sm font-semibold text-slate-800">{{ q.label }}</h3>
          <p class="mb-3 text-xs text-slate-400">
            {{ $t('stats.answered', { n: q.answered }) }}<span v-if="q.multi"> · {{ $t('stats.multiHint') }}</span>
          </p>
          <div :style="{ height: hBarHeight(q.options.length) }">
            <StatsChart type="bar" :data="questionData(q)" :options="hBarValueOpts(q.answered)" />
          </div>
          <p v-if="q.others" class="mt-2 text-xs text-slate-400">{{ $t('stats.others', { n: q.others }) }}</p>
        </div>
      </div>
    </section>
  </div>
</template>
