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
  // Cabecera clicable: al pulsar una tarjeta se emite `select` con el estado y
  // las métricas se recalculan para ese subconjunto. La vista pública lo deja en
  // false (tarjetas como simples contadores, sin estado de revisión).
  interactive: { type: Boolean, default: false },
  // Recarga en curso (cambio de filtro): atenúa las tarjetas mientras llega.
  reloading: { type: Boolean, default: false },
  // Equipos marcados (null = todos). Controla qué equipos suman a los agregados.
  selectedTeams: { type: Array, default: null },
})
const emit = defineEmits(['select', 'select-teams'])
const stats = computed(() => props.stats)

// ---- Filtro por equipos (checkboxes del desglose) ----
const allTeamKeys = computed(() => (stats.value?.by_team ?? []).map((t) => t.key))
const teamSubsetActive = computed(() => Array.isArray(props.selectedTeams))
const isTeamOn = (key) => props.selectedTeams === null || props.selectedTeams.includes(key)
const teamsOnCount = computed(() =>
  props.selectedTeams === null ? allTeamKeys.value.length : props.selectedTeams.length,
)

function toggleTeam(key) {
  const cur = props.selectedTeams ? [...props.selectedTeams] : [...allTeamKeys.value]
  const i = cur.indexOf(key)
  if (i >= 0) cur.splice(i, 1)
  else cur.push(key)
  // Si quedan todos marcados, se vuelve a «sin filtro» (null) para no arrastrar el parámetro.
  emit('select-teams', cur.length === allTeamKeys.value.length ? null : cur)
}

// Tarjetas del encabezado. «Total» (clave 'all') siempre; el resto solo con el
// bloque de revisión presente (vista interna). La clave casa con el filtro que
// devuelve el backend (`stats.filter`) para resaltar la activa.
const headerCards = computed(() => {
  const s = stats.value
  const cards = [{ key: 'all', label: t('stats.total'), value: s.total, cls: 'text-slate-900' }]
  if (s.by_status) {
    cards.push(
      { key: 'pending', label: t('stats.pending'), value: s.by_status.pending, cls: 'text-amber-600 dark:text-amber-400' },
      { key: 'approved', label: t('stats.approved'), value: s.by_status.approved, cls: 'text-success-600 dark:text-success-400' },
      { key: 'on_hold', label: t('stats.onHold'), value: s.by_status.on_hold ?? 0, cls: 'text-sky-600' },
      { key: 'rejected', label: t('stats.rejected'), value: s.by_status.rejected, cls: 'text-red-600 dark:text-red-400' },
    )
  }
  return cards
})

// Etiqueta del subconjunto activo, para el aviso «basado en …».
const filterLabel = computed(() => {
  const f = stats.value?.filter
  return f && f !== 'all' ? t('review.' + f) : t('stats.scopeAll')
})

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

// ---- Desglose por equipo → encuestador ----
// Etiqueta del campo de equipo y del de encuestador (o «usuario Kobo» si se usa
// `_submitted_by`, que llega con label null).
const teamFieldLabel = computed(() => stats.value?.team_field?.label || stats.value?.team_field?.key || '')
const enumFieldLabel = computed(() => stats.value?.enumerator_field?.label || t('stats.enumSubmittedBy'))

// Se muestra solo si aporta algo: ≥2 equipos, ≥2 encuestadores en el primero, o hay
// equipos en «otros». Con un único equipo y un único encuestador sería ruido.
const showTeam = computed(() => {
  const ts = stats.value?.by_team ?? []
  if (!ts.length) return false
  return ts.length >= 2 || (ts[0]?.enumerators?.length ?? 0) >= 2 || (stats.value?.team_others ?? 0) > 0
})

const fmtPct = (p) => (p == null ? '—' : p + '%')
const fmtCompleteness = (c) => (c == null ? '—' : Math.round(c * 100) + '%')
const fmtMedian = (d) => (d ? fmtDuration(d.median_s) : '—')

// Conteos de revisión con color (omite los que están a 0). `status` solo viene en la
// vista interna; en pública es undefined → array vacío y la columna se oculta.
const reviewPills = (st) =>
  st
    ? [
        { key: 'approved', n: st.approved, cls: 'text-success-600 dark:text-success-400' },
        { key: 'rejected', n: st.rejected, cls: 'text-red-600 dark:text-red-400' },
        { key: 'on_hold', n: st.on_hold, cls: 'text-sky-600' },
        { key: 'pending', n: st.pending, cls: 'text-amber-600 dark:text-amber-400' },
      ].filter((p) => p.n > 0)
    : []
</script>

<template>
  <div class="space-y-6">
    <!-- Tarjetas resumen. Las de estado de revisión solo si el bloque interno está
         presente. En modo interactivo son botones: al pulsarlos las estadísticas se
         recalculan para ese subconjunto (la activa lleva anillo y ✓). -->
    <div class="grid gap-4 grid-cols-2 sm:grid-cols-3" :class="stats.by_status ? 'lg:grid-cols-5' : ''">
      <component
        :is="interactive ? 'button' : 'div'"
        v-for="c in headerCards"
        :key="c.key"
        :type="interactive ? 'button' : undefined"
        :disabled="interactive && reloading"
        :aria-pressed="interactive ? String(stats.filter === c.key) : undefined"
        class="relative rounded-xl bg-white p-5 text-left shadow-sm ring-1 transition"
        :class="[
          interactive && stats.filter === c.key ? 'ring-2 ring-primary-500' : 'ring-slate-200',
          interactive ? 'cursor-pointer hover:ring-primary-300 disabled:cursor-wait disabled:opacity-60' : '',
        ]"
        @click="interactive ? $emit('select', c.key) : null"
      >
        <span
          v-if="interactive && stats.filter === c.key"
          class="absolute right-2 top-2 flex h-5 w-5 items-center justify-center rounded-full bg-primary-600 text-xs font-bold text-white"
          aria-hidden="true"
        >✓</span>
        <p class="text-xs uppercase tracking-wider text-slate-400">{{ c.label }}</p>
        <p class="mt-1 text-2xl font-semibold" :class="c.cls">{{ c.value }}</p>
      </component>
    </div>

    <!-- Aviso del subconjunto activo (solo interactivo). -->
    <p v-if="interactive" class="-mt-3 text-xs text-slate-400">
      {{ $t('stats.showingScope', { label: filterLabel, n: stats.base }) }}
    </p>

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

    <!-- Adjuntos / Geo. Cada tarjeta solo si el subconjunto mostrado tiene alguno
         (un formulario sin adjuntos/ubicación no genera una tarjeta vacía). -->
    <div v-if="stats.attachments.with > 0 || stats.geo.with > 0" class="grid gap-4 lg:grid-cols-2">
      <div v-if="stats.attachments.with > 0" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h2 class="mb-1 font-semibold text-slate-900">{{ $t('stats.attachments') }}</h2>
        <p class="mb-3 text-sm text-slate-500">
          {{ $t('stats.attSummary', { pct: stats.attachments.with_pct }) }}<span v-if="attByKindText"> · {{ attByKindText }}</span>
        </p>
        <div class="h-56"><StatsChart type="doughnut" :data="attachmentsData" :options="doughnutValueOpts(stats.base)" /></div>
      </div>
      <div v-if="stats.geo.with > 0" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h2 class="mb-1 font-semibold text-slate-900">{{ $t('stats.geo') }}</h2>
        <p class="mb-3 text-sm text-slate-500">{{ $t('stats.geoSummary', { pct: stats.geo.with_pct }) }}</p>
        <div class="h-56"><StatsChart type="doughnut" :data="geoData" :options="doughnutValueOpts(stats.base)" /></div>
      </div>
    </div>

    <!-- Por enumerador (solo si hay 2+ enumeradores reales) -->
    <div v-if="showEnumerator" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <h2 class="mb-4 font-semibold text-slate-900">{{ $t('stats.byEnumerator') }}</h2>
      <div :style="{ height: hBarHeight(stats.by_enumerator.length) }">
        <StatsChart type="bar" :data="byEnumeratorData" :options="hBarValueOpts(stats.base)" />
      </div>
      <p v-if="stats.enumerator_others" class="mt-2 text-xs text-slate-400">
        {{ $t('stats.others', { n: stats.enumerator_others }) }}
      </p>
    </div>

    <!-- Por equipo → encuestador (desglose de dos niveles, si está configurado) -->
    <section v-if="showTeam" class="space-y-3">
      <div class="flex flex-wrap items-end justify-between gap-x-4 gap-y-1">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('stats.byTeamTitle') }}</h2>
          <p class="text-xs text-slate-400">
            {{ $t('stats.byTeamTeam', { field: teamFieldLabel }) }} · {{ $t('stats.byTeamEnum', { field: enumFieldLabel }) }}
          </p>
          <p v-if="interactive" class="mt-0.5 text-xs text-slate-400">{{ $t('stats.teamToggleHint') }}</p>
        </div>
        <div v-if="interactive && teamSubsetActive" class="flex items-center gap-2 text-xs">
          <span class="text-slate-500">{{ $t('stats.teamsSubset', { k: teamsOnCount, m: stats.by_team.length }) }}</span>
          <button type="button" class="font-medium text-primary-600 hover:underline" :disabled="reloading" @click="$emit('select-teams', null)">
            {{ $t('stats.teamsShowAll') }}
          </button>
        </div>
      </div>

      <details
        v-for="(team, i) in stats.by_team"
        :key="i"
        class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200 transition"
        :class="{ 'opacity-50': interactive && !isTeamOn(team.key) }"
        :open="stats.by_team.length <= 3"
      >
        <summary class="flex cursor-pointer list-none flex-wrap items-center gap-x-3 gap-y-1 px-5 py-3 hover:bg-slate-50">
          <button
            v-if="interactive"
            type="button"
            role="switch"
            :aria-checked="String(isTeamOn(team.key))"
            :aria-label="team.name"
            :disabled="reloading"
            :title="$t('stats.teamToggleHint')"
            class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-primary-500/30 disabled:cursor-wait disabled:opacity-60"
            :class="isTeamOn(team.key) ? 'bg-primary-600' : 'bg-slate-300 dark:bg-slate-600'"
            @click.stop="toggleTeam(team.key)"
          >
            <span
              class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
              :class="isTeamOn(team.key) ? 'translate-x-4' : 'translate-x-0.5'"
            />
          </button>
          <span class="font-semibold text-slate-900">{{ team.name }}</span>
          <span class="text-sm text-slate-500">{{ team.count }} · {{ fmtPct(team.pct) }}</span>
          <span class="ml-auto flex gap-2 text-xs font-semibold">
            <span v-for="p in reviewPills(team.status)" :key="p.key" :class="p.cls" :title="$t('review.' + p.key)">
              {{ p.n }}
            </span>
          </span>
        </summary>

        <div class="border-t border-slate-100 px-5 py-3">
          <!-- Métricas del equipo -->
          <dl class="mb-3 grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:grid-cols-3">
            <div><dt class="text-slate-400">{{ $t('stats.colDuration') }}</dt><dd class="font-medium text-slate-700">{{ fmtMedian(team.duration) }}</dd></div>
            <div><dt class="text-slate-400">{{ $t('stats.colCompleteness') }}</dt><dd class="font-medium text-slate-700">{{ fmtCompleteness(team.completeness_mean) }}</dd></div>
            <div><dt class="text-slate-400">{{ $t('stats.colLastActivity') }}</dt><dd class="font-medium text-slate-700">{{ team.last_activity ?? '—' }}</dd></div>
          </dl>

          <!-- Encuestadores del equipo -->
          <div class="overflow-x-auto">
            <table class="w-full whitespace-nowrap text-left text-sm">
              <thead class="text-xs uppercase tracking-wider text-slate-400">
                <tr>
                  <th class="py-1 pr-3">{{ $t('stats.colEnumerator') }}</th>
                  <th class="py-1 pr-3 text-right">{{ $t('stats.colVolume') }}</th>
                  <th class="py-1 pr-3 text-right">{{ $t('stats.colDuration') }}</th>
                  <th class="py-1 pr-3 text-right">{{ $t('stats.colCompleteness') }}</th>
                  <th v-if="team.status" class="py-1 pr-3 text-right">{{ $t('stats.colReview') }}</th>
                  <th class="py-1 text-right">{{ $t('stats.colLastActivity') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="(e, j) in team.enumerators" :key="j">
                  <td class="py-1.5 pr-3 font-medium text-slate-700">{{ e.name }}</td>
                  <td class="py-1.5 pr-3 text-right text-slate-600">{{ e.count }} <span class="text-slate-400">· {{ fmtPct(e.pct) }}</span></td>
                  <td class="py-1.5 pr-3 text-right text-slate-600">{{ fmtMedian(e.duration) }}</td>
                  <td class="py-1.5 pr-3 text-right text-slate-600">{{ fmtCompleteness(e.completeness_mean) }}</td>
                  <td v-if="team.status" class="py-1.5 pr-3 text-right">
                    <span v-for="p in reviewPills(e.status)" :key="p.key" :class="p.cls" :title="$t('review.' + p.key)" class="ml-1.5 font-semibold">{{ p.n }}</span>
                    <span v-if="!reviewPills(e.status).length" class="text-slate-300">—</span>
                  </td>
                  <td class="py-1.5 text-right text-slate-500">{{ e.last_activity ?? '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-if="team.enumerator_others" class="mt-2 text-xs text-slate-400">
            {{ $t('stats.enumOthers', { n: team.enumerator_others }) }}
          </p>
        </div>
      </details>

      <p v-if="stats.team_others" class="text-xs text-slate-400">{{ $t('stats.teamOthers', { n: stats.team_others }) }}</p>
    </section>

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
