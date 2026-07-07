<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, RouterLink } from 'vue-router'
import api from '../services/api'
import { apiError } from '../stores/auth'
import StatsPanels from '../components/StatsPanels.vue'
import Skeleton from '../components/Skeleton.vue'

const { t, locale } = useI18n()
const route = useRoute()
const formId = computed(() => Number(route.params.id))

const stats = ref(null)
const loading = ref(true)
const error = ref('')

// Filtro de estado activo (null = dejar que el backend aplique el alcance por
// defecto configurado). Tras cargar, se sincroniza con el filtro realmente aplicado.
const selectedStatus = ref(null)
// Equipos seleccionados (null = todos; array = subconjunto marcado). Persiste al
// cambiar de estado: ambos filtros se componen.
const selectedTeams = ref(null)

// Rango de fechas (días naturales UTC, inclusive). Presets: 'all' (sin rango),
// '7'|'30'|'90' (from = hoy−N, to abierto = hasta hoy) o 'custom' (dos inputs).
// No se persiste entre visitas (a propósito): cada entrada parte de «Todo».
const PRESETS = ['all', '7', '30', '90', 'custom']
const preset = ref('all')
const dateFrom = ref('')
const dateTo = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const params = {}
    if (selectedStatus.value) params.status = selectedStatus.value
    if (selectedTeams.value) params.teams = selectedTeams.value.join(',')
    if (dateFrom.value) params.from = dateFrom.value
    if (dateTo.value) params.to = dateTo.value
    const { data } = await api.get(`/forms/${formId.value}/stats`, { params })
    stats.value = data.data
    selectedStatus.value = data.data.filter // refleja la tarjeta activa
    selectedTeams.value = data.data.team_selection ?? null
  } catch (e) {
    error.value = apiError(e, t('stats.loadError'))
  } finally {
    loading.value = false
  }
}

function selectStatus(status) {
  if (status === selectedStatus.value) return
  selectedStatus.value = status
  load()
}

function selectTeams(keys) {
  selectedTeams.value = keys // null = todos; array = subconjunto (puede ser vacío)
  load()
}

// ---- Selector de periodo ----
const presetLabel = (p) => {
  if (p === 'all') return t('stats.periodAll')
  if (p === 'custom') return t('stats.periodCustom')
  return t('stats.periodDays', { n: p })
}

function setPreset(p) {
  if (p === preset.value) return
  preset.value = p
  if (p === 'custom') return // solo despliega los inputs; recarga al cambiar fechas
  dateFrom.value = p === 'all' ? '' : new Date(Date.now() - Number(p) * 86400000).toISOString().slice(0, 10)
  dateTo.value = ''
  load()
}

function clearRange() {
  preset.value = 'all'
  dateFrom.value = ''
  dateTo.value = ''
  load()
}

// Chip del rango realmente aplicado (el backend lo ecoa en date_from/date_to).
const fmtDay = (d) => new Date(d + 'T00:00:00Z').toLocaleDateString(locale.value, { timeZone: 'UTC' })
const rangeChip = computed(() => {
  const f = stats.value?.date_from
  const to = stats.value?.date_to
  if (!f && !to) return ''
  return `${f ? fmtDay(f) : '…'} – ${to ? fmtDay(to) : t('stats.periodNow')}`
})

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header>
      <div class="flex items-center justify-between gap-3">
        <RouterLink
          :to="{ name: 'submissions', params: { id: formId } }"
          class="text-sm text-primary-600 hover:underline"
        >
          {{ $t('stats.back') }}
        </RouterLink>
        <RouterLink
          :to="{ name: 'quality', params: { id: formId } }"
          class="text-sm font-medium text-primary-600 hover:underline"
        >
          {{ $t('stats.qualityLink') }}
        </RouterLink>
      </div>
      <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
        {{ $t('stats.title') }}{{ stats ? ' · ' + stats.form.name : '' }}
      </h1>
      <p v-if="stats?.last_submission" class="mt-1 text-sm text-slate-500">
        {{ $t('stats.lastSubmission', { date: stats.last_submission }) }}
      </p>
    </header>

    <!-- Selector de periodo (acota las métricas; el encabezado y la tendencia 7/30d
         siguen siendo globales). Presets sobre días naturales UTC + rango a medida. -->
    <div v-if="stats" class="flex flex-wrap items-center gap-2">
      <span class="text-xs uppercase tracking-wider text-slate-400">{{ $t('stats.period') }}</span>
      <div class="flex flex-wrap gap-1" role="group" :aria-label="$t('stats.period')">
        <button
          v-for="p in PRESETS"
          :key="p"
          type="button"
          :disabled="loading"
          :aria-pressed="String(preset === p)"
          class="rounded-lg px-3 py-1.5 text-xs font-semibold transition disabled:cursor-wait disabled:opacity-60"
          :class="preset === p
            ? 'bg-primary-600 text-white'
            : 'bg-white text-slate-600 shadow-sm ring-1 ring-slate-300 hover:bg-slate-50'"
          @click="setPreset(p)"
        >
          {{ presetLabel(p) }}
        </button>
      </div>
      <template v-if="preset === 'custom'">
        <label class="flex items-center gap-1 text-xs text-slate-500">
          {{ $t('stats.periodFrom') }}
          <input
            v-model="dateFrom"
            type="date"
            :disabled="loading"
            class="rounded-lg border border-slate-300 px-2 py-1 text-xs outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
            @change="load"
          />
        </label>
        <label class="flex items-center gap-1 text-xs text-slate-500">
          {{ $t('stats.periodTo') }}
          <input
            v-model="dateTo"
            type="date"
            :disabled="loading"
            class="rounded-lg border border-slate-300 px-2 py-1 text-xs outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
            @change="load"
          />
        </label>
      </template>
      <span
        v-if="rangeChip"
        class="inline-flex items-center gap-1.5 rounded-full bg-primary-50 px-3 py-1 text-xs font-medium text-primary-700 ring-1 ring-primary-200 dark:bg-primary-950/40 dark:text-primary-300 dark:ring-primary-900"
      >
        {{ rangeChip }}
        <button
          type="button"
          :disabled="loading"
          :aria-label="$t('stats.periodClear')"
          :title="$t('stats.periodClear')"
          class="font-bold hover:text-primary-900 dark:hover:text-primary-100"
          @click="clearRange"
        >×</button>
      </span>
    </div>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900">
      {{ error }}
    </div>
    <Skeleton v-else-if="loading && !stats" variant="cards" :count="4" />

    <StatsPanels
      v-else-if="stats"
      :stats="stats"
      interactive
      :reloading="loading"
      :selected-teams="selectedTeams"
      @select="selectStatus"
      @select-teams="selectTeams"
    />
  </div>
</template>
