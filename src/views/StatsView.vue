<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, RouterLink } from 'vue-router'
import api from '../services/api'
import { apiError } from '../stores/auth'
import { fmtDuration } from '../composables/derived'
import StatsChart from '../components/StatsChart.vue'

const { t } = useI18n()
const route = useRoute()
const formId = computed(() => Number(route.params.id))

const stats = ref(null)
const loading = ref(true)
const error = ref('')

// ---- Datos de gráficos ----
const PRIMARY = '#2563eb'

const byDayData = computed(() => ({
  labels: (stats.value?.by_day ?? []).map((d) => d.date),
  datasets: [
    { label: t('stats.submissions'), data: (stats.value?.by_day ?? []).map((d) => d.count), backgroundColor: PRIMARY, borderRadius: 4 },
  ],
}))

const byStatusData = computed(() => {
  const s = stats.value?.by_status ?? { pending: 0, approved: 0, rejected: 0 }
  return {
    labels: [t('review.pending'), t('review.approved'), t('review.rejected')],
    datasets: [{ data: [s.pending, s.approved, s.rejected], backgroundColor: ['#f59e0b', '#16a34a', '#dc2626'] }],
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
    datasets: [{ data: [g.with, g.without], backgroundColor: ['#16a34a', '#e2e8f0'] }],
  }
})

// Datos de una pregunta concreta (opciones ordenadas por conteo desc).
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

// Altura del gráfico horizontal según nº de barras (mín. legible).
const hBarHeight = (n) => Math.max(120, n * 30 + 40) + 'px'

// Resumen de adjuntos por tipo, p. ej. «12 img · 3 audio».
const attByKindText = computed(() => {
  const by = stats.value?.attachments?.by_kind ?? {}
  return ['image', 'audio', 'video', 'file']
    .filter((k) => by[k])
    .map((k) => `${by[k]} ${t('derived.kind.' + k)}`)
    .join(' · ')
})

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get(`/forms/${formId.value}/stats`)
    stats.value = data.data
  } catch (e) {
    error.value = apiError(e, t('stats.loadError'))
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header>
      <RouterLink
        :to="{ name: 'submissions', params: { id: formId } }"
        class="text-sm text-primary-600 hover:underline"
      >
        {{ $t('stats.back') }}
      </RouterLink>
      <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
        {{ $t('stats.title') }}{{ stats ? ' · ' + stats.form.name : '' }}
      </h1>
      <p v-if="stats?.last_submission" class="mt-1 text-sm text-slate-500">
        {{ $t('stats.lastSubmission', { date: stats.last_submission }) }}
      </p>
    </header>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">
      {{ error }}
    </div>
    <div v-else-if="loading" class="text-sm text-slate-500">{{ $t('common.loading') }}</div>

    <template v-else-if="stats">
      <!-- Tarjetas resumen -->
      <div class="grid gap-4 sm:grid-cols-4">
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <p class="text-xs uppercase tracking-wider text-slate-400">{{ $t('stats.total') }}</p>
          <p class="mt-1 text-2xl font-semibold text-slate-900">{{ stats.total }}</p>
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <p class="text-xs uppercase tracking-wider text-slate-400">{{ $t('stats.pending') }}</p>
          <p class="mt-1 text-2xl font-semibold text-amber-600">{{ stats.by_status.pending }}</p>
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <p class="text-xs uppercase tracking-wider text-slate-400">{{ $t('stats.approved') }}</p>
          <p class="mt-1 text-2xl font-semibold text-green-600">{{ stats.by_status.approved }}</p>
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <p class="text-xs uppercase tracking-wider text-slate-400">{{ $t('stats.rejected') }}</p>
          <p class="mt-1 text-2xl font-semibold text-red-600">{{ stats.by_status.rejected }}</p>
        </div>
      </div>

      <!-- Gráficos base -->
      <div class="grid gap-4 lg:grid-cols-3">
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 lg:col-span-2">
          <h2 class="mb-4 font-semibold text-slate-900">{{ $t('stats.byDay') }}</h2>
          <div class="h-64">
            <StatsChart v-if="stats.by_day.length" type="bar" :data="byDayData" :options="barOptions" />
            <p v-else class="text-sm text-slate-400">{{ $t('stats.noData') }}</p>
          </div>
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h2 class="mb-4 font-semibold text-slate-900">{{ $t('stats.byStatus') }}</h2>
          <div class="h-64">
            <StatsChart type="doughnut" :data="byStatusData" :options="doughnutOptions" />
          </div>
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
          <div class="h-64"><StatsChart type="bar" :data="durationHistData" :options="barOptions" /></div>
        </div>
      </div>

      <!-- Actividad por hora / día -->
      <div class="grid gap-4 lg:grid-cols-2">
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h2 class="mb-4 font-semibold text-slate-900">{{ $t('stats.activityHour') }}</h2>
          <div class="h-64"><StatsChart type="bar" :data="byHourData" :options="barOptions" /></div>
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h2 class="mb-4 font-semibold text-slate-900">{{ $t('stats.activityDay') }}</h2>
          <div class="h-64"><StatsChart type="bar" :data="byDowData" :options="barOptions" /></div>
        </div>
      </div>

      <!-- Adjuntos / Geo -->
      <div class="grid gap-4 lg:grid-cols-2">
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h2 class="mb-1 font-semibold text-slate-900">{{ $t('stats.attachments') }}</h2>
          <p class="mb-3 text-sm text-slate-500">
            {{ $t('stats.attSummary', { pct: stats.attachments.with_pct }) }}<span v-if="attByKindText"> · {{ attByKindText }}</span>
          </p>
          <div class="h-56"><StatsChart type="doughnut" :data="attachmentsData" :options="doughnutOptions" /></div>
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h2 class="mb-1 font-semibold text-slate-900">{{ $t('stats.geo') }}</h2>
          <p class="mb-3 text-sm text-slate-500">{{ $t('stats.geoSummary', { pct: stats.geo.with_pct }) }}</p>
          <div class="h-56"><StatsChart type="doughnut" :data="geoData" :options="doughnutOptions" /></div>
        </div>
      </div>

      <!-- Por enumerador -->
      <div v-if="stats.by_enumerator.length" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h2 class="mb-4 font-semibold text-slate-900">{{ $t('stats.byEnumerator') }}</h2>
        <div :style="{ height: hBarHeight(stats.by_enumerator.length) }">
          <StatsChart type="bar" :data="byEnumeratorData" :options="hBarOptions" />
        </div>
        <p v-if="stats.enumerator_others" class="mt-2 text-xs text-slate-400">
          {{ $t('stats.others', { n: stats.enumerator_others }) }}
        </p>
      </div>

      <!-- Distribución por pregunta (select_one) -->
      <section v-if="stats.by_question.length" class="space-y-4">
        <h2 class="font-semibold text-slate-900">{{ $t('stats.byQuestion') }}</h2>
        <div class="grid gap-4 lg:grid-cols-2">
          <div
            v-for="q in stats.by_question"
            :key="q.field"
            class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200"
          >
            <h3 class="text-sm font-semibold text-slate-800">{{ q.label }}</h3>
            <p class="mb-3 text-xs text-slate-400">{{ $t('stats.answered', { n: q.answered }) }}</p>
            <div :style="{ height: hBarHeight(q.options.length) }">
              <StatsChart type="bar" :data="questionData(q)" :options="hBarOptions" />
            </div>
            <p v-if="q.others" class="mt-2 text-xs text-slate-400">{{ $t('stats.others', { n: q.others }) }}</p>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>
