<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, RouterLink } from 'vue-router'
import api from '../services/api'
import { apiError } from '../stores/auth'
import StatsChart from '../components/StatsChart.vue'

const { t } = useI18n()
const route = useRoute()
const formId = computed(() => Number(route.params.id))

const stats = ref(null)
const loading = ref(true)
const error = ref('')

const byDayData = computed(() => ({
  labels: (stats.value?.by_day ?? []).map((d) => d.date),
  datasets: [
    {
      label: t('stats.submissions'),
      data: (stats.value?.by_day ?? []).map((d) => d.count),
      backgroundColor: '#2563eb',
      borderRadius: 4,
    },
  ],
}))

const byStatusData = computed(() => {
  const s = stats.value?.by_status ?? { pending: 0, approved: 0, rejected: 0 }
  return {
    labels: [t('review.pending'), t('review.approved'), t('review.rejected')],
    datasets: [
      {
        data: [s.pending, s.approved, s.rejected],
        backgroundColor: ['#f59e0b', '#16a34a', '#dc2626'],
      },
    ],
  }
})

const barOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { display: false } },
  scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
}
const doughnutOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { position: 'bottom' } },
}

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
        class="text-sm text-blue-600 hover:underline"
      >
        {{ $t('stats.back') }}
      </RouterLink>
      <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
        {{ $t('stats.title') }}{{ stats ? ' · ' + stats.form.name : '' }}
      </h1>
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

      <!-- Gráficos -->
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
    </template>
  </div>
</template>
