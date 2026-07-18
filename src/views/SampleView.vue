<script setup>
/**
 * Panel de cumplimiento de la MUESTRA por equipo: hecho/objetivo por celda
 * `equipo × valor`, totales y proyección por equipo. Solo lectura; respeta el
 * scoping por filas del usuario (un jefe de equipo ve el suyo). El plan se edita
 * en los ajustes del formulario (SamplePlanEditor).
 *
 * La PRESENTACIÓN se elige con un selector (preferencia por dispositivo en
 * localStorage, como el tema): lineal (tarjetas con barras), tabla, mapa de
 * calor (la misma tabla con color por %), barras agrupadas hecho vs objetivo
 * (Chart.js), semáforo (rejilla sin cifras) y resumen (doughnut global).
 * Todo frontend: el endpoint devuelve lo mismo en todos los modos.
 */
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, RouterLink } from 'vue-router'
import api from '../services/api'
import { apiError, useAuthStore } from '../stores/auth'
import { usePctFormat } from '../composables/appConfig'
import Skeleton from '../components/Skeleton.vue'
import StatsChart from '../components/StatsChart.vue'

const { t, locale } = useI18n()
const route = useRoute()
const auth = useAuthStore()
const { formatPct } = usePctFormat()
const formId = computed(() => Number(route.params.id))

const data = ref(null)
const loading = ref(true)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.get(`/forms/${formId.value}/sample`)
    data.value = res.data.data
  } catch (e) {
    error.value = apiError(e, t('sample.loadError'))
  } finally {
    loading.value = false
  }
}

const num = (n) => (n ?? 0).toLocaleString(locale.value)
const pct = (p) => formatPct(p, locale.value)
// Ancho de la barra: acotado a 100 % aunque haya sobre-muestra.
const barWidth = (done, target) => {
  if (!target || target <= 0) return 0
  return Math.min(100, Math.round((done * 100) / target))
}
const canSettings = computed(() => {
  const f = data.value
  return auth.isAdmin || !!(f && f.can_settings)
})

// ---------- Selector de tipo de vista ----------
// Preferencia por dispositivo (no por formulario), como el tema.
const VIEW_KEY = 'km.sample.view'
const VIEWS = [
  { key: 'linear', label: 'sample.viewLinear' },
  { key: 'table', label: 'sample.viewTable' },
  { key: 'heatmap', label: 'sample.viewHeatmap' },
  { key: 'bars', label: 'sample.viewBars' },
  { key: 'traffic', label: 'sample.viewTraffic' },
  { key: 'summary', label: 'sample.viewSummary' },
]
const storedView = localStorage.getItem(VIEW_KEY)
const view = ref(VIEWS.some((v) => v.key === storedView) ? storedView : 'linear')
function setView(key) {
  view.value = key
  localStorage.setItem(VIEW_KEY, key)
}

// ---------- Ejes de la tabla (tabla / mapa de calor / semáforo) ----------
// El backend omite en `team.cells` las celdas vacías sin objetivo, así que la
// tabla se arma sobre el eje canónico `data.values` mapeando cada fila por valor.
const tableRows = computed(() =>
  (data.value?.teams ?? []).map((team) => ({
    team,
    map: Object.fromEntries(team.cells.map((c) => [c.value, c])),
  })),
)
// Columnas: solo los valores con alguna celda (evita columnas 100 % vacías de
// opciones del esquema sin datos ni plan).
const tableValues = computed(() =>
  (data.value?.values ?? []).filter((v) => tableRows.value.some((r) => r.map[v.value])),
)
const colTotals = computed(() =>
  tableValues.value.map((v) => {
    let done = 0
    let target = 0
    let hasTarget = false
    for (const r of tableRows.value) {
      const c = r.map[v.value]
      if (!c) continue
      done += c.done
      if (c.target != null) {
        target += c.target
        hasTarget = true
      }
    }
    return { done, target: hasTarget ? target : null }
  }),
)

// Mapa de calor: color del chip por tramo de cumplimiento (rojo→ámbar→verde);
// el verde final sigue el token `success` del tema (clases, no hex).
function heatClass(cell) {
  if (cell.target == null) return 'bg-amber-100 text-amber-800'
  const p = cell.pct ?? 0
  if (p >= 100) return 'bg-success-500 text-white'
  if (p >= 75) return 'bg-lime-300 text-lime-950'
  if (p >= 50) return 'bg-amber-300 text-amber-950'
  if (p >= 25) return 'bg-orange-400 text-orange-950'
  return 'bg-red-500 text-white'
}

// Avance REAL del plan: solo celdas con objetivo y con el hecho acotado a su
// objetivo (el `grand.done` incluye lo fuera de plan y la sobre-muestra, que
// distorsionan: un equipo que se pasa no cubre lo que falta en otro). Base del
// umbral del semáforo y del doughnut de resumen.
const plannedAgg = computed(() => {
  let done = 0
  let target = 0
  for (const tm of data.value?.teams ?? []) {
    for (const c of tm.cells) {
      if (c.target != null) {
        done += Math.min(c.done, c.target)
        target += c.target
      }
    }
  }
  return { done, target }
})
// Semáforo: «atrasado» es RELATIVO a este avance global (quién va por debajo
// del ritmo común), no un umbral fijo.
const globalPct = computed(() => {
  const { done, target } = plannedAgg.value
  return target > 0 ? (done * 100) / target : 0
})
function trafficClass(cell) {
  if (!cell || cell.target == null) return 'bg-slate-200'
  const p = cell.pct ?? 0
  if (p >= 100) return 'bg-success-500'
  if (p >= globalPct.value) return 'bg-amber-400'
  return 'bg-red-500'
}
function trafficTitle(cell, label) {
  if (!cell) return label
  const base = `${label}: ${num(cell.done)}${cell.target != null ? ' / ' + num(cell.target) : ''}`
  return cell.target == null ? `${base} · ${t('sample.trafficNoTarget')}` : base
}

// ---------- Gráficos (barras agrupadas y doughnut de resumen) ----------
const PRIMARY = '#2563eb'
// Lee un token de color del tema (variable CSS en :root); cae al hex si aún no
// está resuelto. Mismo helper que en Estadísticas.
const themeColor = (name, fallback) =>
  getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback

const barsData = computed(() => {
  const teams = data.value?.teams ?? []
  return {
    labels: teams.map((tm) => tm.name),
    datasets: [
      { label: t('sample.done'), data: teams.map((tm) => tm.done), backgroundColor: PRIMARY, borderRadius: 4 },
      { label: t('sample.target'), data: teams.map((tm) => tm.target), backgroundColor: '#cbd5e1', borderRadius: 4 },
    ],
  }
})
const barsOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { display: true, position: 'bottom' }, valueLabels: { enabled: true } },
  scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
}

const summaryData = computed(() => {
  const { done, target } = plannedAgg.value
  return {
    labels: [t('sample.done'), t('sample.summaryMissing')],
    datasets: [{ data: [done, Math.max(0, target - done)], backgroundColor: [themeColor('--color-success-600', '#16a34a'), '#e2e8f0'] }],
  }
})
const summaryOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { display: true, position: 'bottom' }, valueLabels: { enabled: true } },
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header>
      <div class="flex items-center justify-between gap-3">
        <RouterLink :to="{ name: 'submissions', params: { id: formId } }" class="text-sm text-primary-600 hover:underline">
          {{ $t('sample.back') }}
        </RouterLink>
        <RouterLink :to="{ name: 'stats', params: { id: formId } }" class="text-sm font-medium text-primary-600 hover:underline">
          {{ $t('sample.statsLink') }}
        </RouterLink>
      </div>
      <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
        {{ $t('sample.title') }}{{ data?.form ? ' · ' + data.form.name : '' }}
      </h1>
      <p class="mt-1 text-sm text-slate-500">{{ $t('sample.subtitle') }}</p>
    </header>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900">
      {{ error }}
    </div>

    <Skeleton v-if="loading" variant="cards" :count="3" />

    <template v-else-if="data">
      <!-- Sin campo de muestreo configurado -->
      <div v-if="!data.configured" class="rounded-xl bg-white p-6 text-center shadow-sm ring-1 ring-slate-200">
        <p class="text-sm text-slate-600">{{ $t('sample.notConfigured') }}</p>
        <p class="mt-1 text-sm text-slate-400">{{ $t('sample.notConfiguredBody') }}</p>
        <RouterLink
          v-if="canSettings"
          :to="{ name: 'admin-form-settings', params: { id: formId } }"
          class="mt-4 inline-block rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700"
        >
          {{ $t('sample.configureLink') }}
        </RouterLink>
      </div>

      <template v-else>
        <!-- Aviso: campo de equipo sin configurar -->
        <div v-if="!data.team_field_configured" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900">
          {{ $t('sample.needsTeam') }}
        </div>

        <!-- Aviso: campo configurado pero SIN objetivos → no se monitorea la muestra -->
        <div v-else-if="!data.has_plan" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900">
          {{ $t('sample.noPlan') }}
        </div>

        <!-- Selector de tipo de vista -->
        <div role="group" :aria-label="$t('sample.viewLabel')" class="inline-flex flex-wrap gap-1 rounded-lg bg-slate-100 p-1">
          <button
            v-for="v in VIEWS"
            :key="v.key"
            type="button"
            :aria-pressed="view === v.key"
            class="rounded-md px-2.5 py-1 text-xs font-medium transition"
            :class="view === v.key ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
            @click="setView(v.key)"
          >
            {{ $t(v.label) }}
          </button>
        </div>

        <!-- Total general -->
        <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-semibold text-slate-900">{{ $t('sample.grandTotal') }}</h2>
            <span class="text-xs uppercase tracking-wider text-slate-400">
              {{ data.denominator === 'approved_pending' ? $t('sample.denominatorApprovedPending') : $t('sample.denominatorApproved') }}
            </span>
          </div>
          <div class="mt-3">
            <div class="flex items-baseline justify-between text-sm">
              <span class="font-semibold text-slate-900">{{ num(data.grand.done) }} / {{ num(data.grand.target) }}</span>
              <span class="text-slate-500">{{ pct(data.grand.pct) }}</span>
            </div>
            <div class="mt-1 h-2.5 w-full overflow-hidden rounded-full bg-slate-100">
              <div
                class="h-full rounded-full transition-all"
                :class="(data.grand.pct ?? 0) >= 100 ? 'bg-success-500' : 'bg-primary-500'"
                :style="{ width: barWidth(data.grand.done, data.grand.target) + '%' }"
              ></div>
            </div>
          </div>
        </section>

        <template v-if="data.teams.length">
          <!-- ===== Modo LINEAL: tarjeta por equipo con barras y proyección ===== -->
          <template v-if="view === 'linear'">
            <section
              v-for="team in data.teams"
              :key="team.key"
              class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200"
            >
              <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3 class="font-semibold text-slate-900">
                  {{ team.name }}
                  <span v-if="team.out_of_plan" class="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-[0.65rem] font-medium uppercase tracking-wide text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">{{ $t('sample.outOfPlan') }}</span>
                </h3>
                <span class="text-sm text-slate-500">
                  <span class="font-semibold text-slate-900">{{ num(team.done) }} / {{ num(team.target) }}</span>
                  · {{ pct(team.pct) }}
                </span>
              </div>

              <!-- Barra del total del equipo -->
              <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-slate-100">
                <div
                  class="h-full rounded-full transition-all"
                  :class="(team.pct ?? 0) >= 100 ? 'bg-success-500' : 'bg-primary-500'"
                  :style="{ width: barWidth(team.done, team.target) + '%' }"
                ></div>
              </div>
              <p v-if="team.target > 0" class="mt-1 text-xs" :class="team.done >= team.target ? 'text-success-700 dark:text-success-400' : 'text-slate-500'">
                <template v-if="team.done >= team.target">{{ $t('sample.surplus', { n: num(team.done - team.target) }) }}</template>
                <template v-else>{{ $t('sample.remaining', { n: num(team.target - team.done) }) }}</template>
              </p>

              <!-- Celdas equipo × valor -->
              <div class="mt-4 grid gap-x-6 gap-y-3 sm:grid-cols-2">
                <div v-for="cell in team.cells" :key="cell.value">
                  <div class="flex items-baseline justify-between text-sm">
                    <span class="text-slate-700">
                      {{ cell.label }}
                      <span v-if="cell.out_of_plan" class="ml-1 text-[0.65rem] uppercase tracking-wide text-amber-600">{{ $t('sample.outOfPlan') }}</span>
                    </span>
                    <span class="tabular-nums text-slate-500">
                      {{ num(cell.done) }}<template v-if="cell.target != null"> / {{ num(cell.target) }}</template>
                    </span>
                  </div>
                  <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                    <div
                      class="h-full rounded-full"
                      :class="cell.target == null ? 'bg-amber-400' : ((cell.pct ?? 0) >= 100 ? 'bg-success-500' : 'bg-primary-500')"
                      :style="{ width: (cell.target == null ? 100 : barWidth(cell.done, cell.target)) + '%' }"
                    ></div>
                  </div>
                </div>
              </div>

              <!-- Proyección -->
              <div v-if="team.projection" class="mt-4 border-t border-slate-100 pt-3 text-xs text-slate-500">
                <span class="font-medium text-slate-600">{{ $t('sample.projection') }}:</span>
                <template v-if="team.projection.met">
                  <span class="ml-1 text-success-700 dark:text-success-400">{{ $t('sample.projectionMet') }}</span>
                </template>
                <template v-else-if="team.projection.eta">
                  <span class="ml-1">{{ $t('sample.projectionRate', { n: (team.projection.rate_per_day ?? 0).toLocaleString(locale, { maximumFractionDigits: 1 }) }) }}</span>
                  · <span class="font-medium text-slate-700">{{ $t('sample.projectionEta', { date: team.projection.eta }) }}</span>
                </template>
                <template v-else>
                  <span class="ml-1">{{ $t('sample.projectionNoData') }}</span>
                </template>
                <span v-if="team.projection.first_submission" class="ml-2 text-slate-400">· {{ $t('sample.firstSubmission', { date: (team.projection.first_submission || '').slice(0, 10) }) }}</span>
              </div>
            </section>
          </template>

          <!-- ===== Modos TABLA y MAPA DE CALOR: filas=equipos, columnas=valores ===== -->
          <section v-else-if="view === 'table' || view === 'heatmap'" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <div class="overflow-x-auto">
              <table class="w-full whitespace-nowrap text-left text-sm">
                <thead class="text-xs uppercase tracking-wider text-slate-400">
                  <tr>
                    <th class="py-1.5 pr-4">{{ $t('sample.teamHeader') }}</th>
                    <th v-for="v in tableValues" :key="v.value" class="py-1.5 pr-4">{{ v.label }}</th>
                    <th class="py-1.5">{{ $t('sample.totalHeader') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in tableRows" :key="row.team.key" class="border-t border-slate-100">
                    <td class="py-2 pr-4 font-medium text-slate-700">
                      {{ row.team.name }}
                      <span v-if="row.team.out_of_plan" class="ml-1 text-[0.65rem] uppercase tracking-wide text-amber-600">{{ $t('sample.outOfPlan') }}</span>
                    </td>
                    <td v-for="v in tableValues" :key="v.value" class="py-2 pr-4 tabular-nums align-top">
                      <template v-if="row.map[v.value]">
                        <!-- Mapa de calor: chip coloreado por % de cumplimiento -->
                        <span
                          v-if="view === 'heatmap'"
                          class="inline-block min-w-14 rounded-md px-2 py-1 text-center text-xs font-semibold"
                          :class="heatClass(row.map[v.value])"
                          :title="row.map[v.value].pct != null ? pct(row.map[v.value].pct) : $t('sample.trafficNoTarget')"
                        >
                          {{ num(row.map[v.value].done) }}<template v-if="row.map[v.value].target != null">/{{ num(row.map[v.value].target) }}</template>
                        </span>
                        <!-- Tabla: hecho / objetivo + faltan -->
                        <template v-else>
                          <span class="font-semibold text-slate-700">{{ num(row.map[v.value].done) }}</span><span v-if="row.map[v.value].target != null" class="text-slate-400"> / {{ num(row.map[v.value].target) }}</span>
                          <div class="text-xs">
                            <span v-if="row.map[v.value].target == null" class="text-amber-600">{{ $t('sample.outOfPlan') }}</span>
                            <span v-else-if="row.map[v.value].done >= row.map[v.value].target" class="text-success-700 dark:text-success-400">{{ row.map[v.value].done > row.map[v.value].target ? '+' + num(row.map[v.value].done - row.map[v.value].target) : '✓' }}</span>
                            <span v-else class="text-slate-400">{{ $t('sample.cellRemaining', { n: num(row.map[v.value].target - row.map[v.value].done) }) }}</span>
                          </div>
                        </template>
                      </template>
                      <span v-else class="text-slate-300">—</span>
                    </td>
                    <td class="py-2 tabular-nums align-top">
                      <span class="font-semibold text-slate-700">{{ num(row.team.done) }}</span><span class="text-slate-400"> / {{ num(row.team.target) }}</span>
                      <div v-if="view === 'table' && row.team.target > 0" class="text-xs">
                        <span v-if="row.team.done >= row.team.target" class="text-success-700 dark:text-success-400">{{ row.team.done > row.team.target ? '+' + num(row.team.done - row.team.target) : '✓' }}</span>
                        <span v-else class="text-slate-400">{{ $t('sample.cellRemaining', { n: num(row.team.target - row.team.done) }) }}</span>
                      </div>
                    </td>
                  </tr>
                </tbody>
                <tfoot>
                  <tr class="border-t-2 border-slate-200">
                    <td class="py-2 pr-4 text-xs font-medium uppercase tracking-wider text-slate-400">{{ $t('sample.totalHeader') }}</td>
                    <td v-for="(ct, i) in colTotals" :key="tableValues[i].value" class="py-2 pr-4 tabular-nums">
                      <span class="font-semibold text-slate-700">{{ num(ct.done) }}</span><span v-if="ct.target != null" class="text-slate-400"> / {{ num(ct.target) }}</span>
                    </td>
                    <td class="py-2 tabular-nums">
                      <span class="font-semibold text-slate-900">{{ num(data.grand.done) }}</span><span class="text-slate-400"> / {{ num(data.grand.target) }}</span>
                    </td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </section>

          <!-- ===== Modo BARRAS: hecho vs objetivo por equipo (Chart.js) ===== -->
          <section v-else-if="view === 'bars'" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <div class="h-72"><StatsChart type="bar" :data="barsData" :options="barsOptions" /></div>
          </section>

          <!-- ===== Modo SEMÁFORO: rejilla sin cifras, estado por celda ===== -->
          <section v-else-if="view === 'traffic'" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <div class="overflow-x-auto">
              <table class="whitespace-nowrap text-left text-sm">
                <thead class="text-xs uppercase tracking-wider text-slate-400">
                  <tr>
                    <th class="py-1.5 pr-4">{{ $t('sample.teamHeader') }}</th>
                    <th v-for="v in tableValues" :key="v.value" class="py-1.5 pr-3 font-medium">{{ v.label }}</th>
                    <th class="py-1.5">{{ $t('sample.totalHeader') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in tableRows" :key="row.team.key" class="border-t border-slate-100">
                    <td class="py-2 pr-4 font-medium text-slate-700">
                      {{ row.team.name }}
                      <span v-if="row.team.out_of_plan" class="ml-1 text-[0.65rem] uppercase tracking-wide text-amber-600">{{ $t('sample.outOfPlan') }}</span>
                    </td>
                    <td v-for="v in tableValues" :key="v.value" class="py-2 pr-3">
                      <span
                        class="inline-block h-6 w-6 rounded-md"
                        :class="trafficClass(row.map[v.value])"
                        :title="trafficTitle(row.map[v.value], v.label)"
                      ></span>
                    </td>
                    <td class="py-2">
                      <span
                        class="inline-block h-6 w-6 rounded-md"
                        :class="trafficClass(row.team.target > 0 ? row.team : null)"
                        :title="trafficTitle(row.team.target > 0 ? row.team : null, row.team.name)"
                      ></span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <!-- Leyenda -->
            <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-slate-100 pt-3 text-xs text-slate-500">
              <span class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded bg-success-500"></span>{{ $t('sample.trafficMet') }}</span>
              <span class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded bg-amber-400"></span>{{ $t('sample.trafficOnPace') }}</span>
              <span class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded bg-red-500"></span>{{ $t('sample.trafficBehind') }}</span>
              <span class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded bg-slate-200"></span>{{ $t('sample.trafficNoTarget') }}</span>
            </div>
            <p class="mt-1 text-xs text-slate-400">{{ $t('sample.trafficLegendHint', { pct: pct(globalPct) }) }}</p>
          </section>

          <!-- ===== Modo RESUMEN: doughnut global + lista compacta de equipos ===== -->
          <section v-else-if="view === 'summary'" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <div class="grid items-center gap-6 sm:grid-cols-2">
              <div class="h-64"><StatsChart type="doughnut" :data="summaryData" :options="summaryOptions" /></div>
              <div class="space-y-2">
                <div v-for="team in data.teams" :key="team.key" class="flex items-baseline justify-between gap-3 text-sm">
                  <span class="min-w-0 truncate text-slate-700">
                    {{ team.name }}
                    <span v-if="team.out_of_plan" class="ml-1 text-[0.65rem] uppercase tracking-wide text-amber-600">{{ $t('sample.outOfPlan') }}</span>
                  </span>
                  <span class="shrink-0 tabular-nums text-slate-500">
                    <span class="font-semibold text-slate-900">{{ num(team.done) }} / {{ num(team.target) }}</span>
                    <template v-if="team.pct != null"> · {{ pct(team.pct) }}</template>
                  </span>
                </div>
              </div>
            </div>
          </section>
        </template>

        <p v-else class="rounded-xl bg-white p-6 text-center text-sm text-slate-400 shadow-sm ring-1 ring-slate-200">
          {{ $t('sample.noData') }}
        </p>

        <!-- Distribución observada de campos secundarios -->
        <section v-if="data.secondary && data.secondary.length" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h2 class="font-semibold text-slate-900">{{ $t('sample.secondaryTitle') }}</h2>
          <p class="mt-1 text-sm text-slate-500">{{ $t('sample.secondaryHint') }}</p>
          <div class="mt-4 space-y-5">
            <div v-for="sec in data.secondary" :key="sec.field">
              <div class="flex items-baseline justify-between">
                <h3 class="text-sm font-medium text-slate-700">{{ sec.label }}</h3>
                <span class="text-xs text-slate-400">{{ $t('sample.answered', { n: num(sec.answered) }) }}</span>
              </div>
              <div class="mt-2 space-y-1.5">
                <div v-for="opt in sec.options" :key="opt.label" class="flex items-center gap-2">
                  <span class="w-32 shrink-0 truncate text-xs text-slate-600" :title="opt.label">{{ opt.label }}</span>
                  <div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full rounded-full bg-primary-400" :style="{ width: Math.min(100, opt.pct) + '%' }"></div>
                  </div>
                  <span class="w-20 shrink-0 text-right text-xs tabular-nums text-slate-500">{{ num(opt.count) }} · {{ pct(opt.pct) }}</span>
                </div>
                <p v-if="sec.others" class="text-xs text-slate-400">{{ $t('sample.others') }}: {{ num(sec.others) }}</p>
              </div>
            </div>
          </div>
        </section>

        <p v-if="data.generated_at" class="text-right text-xs text-slate-400">
          {{ $t('sample.generatedAt', { date: data.generated_at }) }} · {{ $t('sample.projectionNote') }}
        </p>
      </template>
    </template>
  </div>
</template>
