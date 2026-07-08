<script setup>
/**
 * Control de calidad por equipo/encuestador: encuestas fuera de los umbrales
 * admisibles del formulario (duración mín/máx y consecutividad mínima), con
 * drill-down a las infractoras y marcado en lote «en espera» sobre el flujo de
 * revisión existente. El análisis lo sirve GET /forms/{id}/quality (lib/Quality).
 */
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, RouterLink } from 'vue-router'
import api from '../services/api'
import { useAuthStore, apiError } from '../stores/auth'
import { confirmDialog } from '../composables/confirm'
import { fmtDuration } from '../composables/derived'
import { usePctFormat } from '../composables/appConfig'
import Skeleton from '../components/Skeleton.vue'
import ReviewBadge from '../components/ReviewBadge.vue'
import StatsChart from '../components/StatsChart.vue'

const { t, locale } = useI18n()
const route = useRoute()
const auth = useAuthStore()
const formId = computed(() => Number(route.params.id))

const q = ref(null)
const loading = ref(true)
const error = ref('')
const flash = ref('')
const batchBusy = ref(false)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get(`/forms/${formId.value}/quality`)
    q.value = data.data
  } catch (e) {
    error.value = apiError(e, t('stats.qualityLoadError'))
  } finally {
    loading.value = false
  }
}

// Un formulario archivado es de solo lectura para la revisión (misma regla que la
// tabla de envíos): se ve el análisis, pero no se marca nada.
const canReview = computed(() => q.value?.can_validate && q.value?.deployment_status !== 'archived')

// Infractoras que aún NO están «en espera» (las ya marcadas no se re-marcan).
const pendingHold = computed(() => {
  const uids = []
  for (const tm of q.value?.teams ?? [])
    for (const e of tm.enumerators)
      for (const v of e.violations) if (v.review_status !== 'on_hold') uids.push(v.uid)
  return uids
})
// Infractoras que YA están «en espera»: explica la resta entre la tarjeta
// «No admitidas» y el número del botón de lote.
const heldCount = computed(() => (q.value?.flagged ?? 0) - pendingHold.value.length)

// Tasa de no admitidas. Regla ÚNICA en toda la página: no admitidas sobre el
// TOTAL de encuestas recibidas (del formulario, del equipo o del encuestador),
// para que el «x / y» y el % compartan siempre el mismo denominador. El formato
// (entero o dos decimales) es el ajuste global «Valores porcentuales».
const { formatRatio, formatPctNumber } = usePctFormat()
const fmtRate = (n, d) => formatRatio(n, d, locale.value)

async function holdAll() {
  const uids = pendingHold.value
  if (!uids.length) return
  const ok = await confirmDialog({
    title: t('stats.qualityBatchTitle'),
    message: t('stats.qualityBatchConfirm', { n: uids.length }),
    confirmText: t('stats.qualityBatchTitle'),
  })
  if (!ok) return
  batchBusy.value = true
  flash.value = ''
  error.value = ''
  try {
    // El endpoint de revisión en lote admite 1000 uids por petición: se trocea.
    let applied = 0
    for (let i = 0; i < uids.length; i += 1000) {
      const { data } = await api.post(`/forms/${formId.value}/review`, {
        uids: uids.slice(i, i + 1000),
        status: 'on_hold',
        comment: t('stats.qualityBatchComment'),
      })
      applied += data.data.applied
    }
    flash.value = t('stats.qualityBatchDone', { n: applied })
    await load()
  } catch (e) {
    error.value = apiError(e, t('stats.qualityBatchError'))
  } finally {
    batchBusy.value = false
  }
}

// Las banderas, en el orden canónico del backend.
const FLAGS = ['short', 'long', 'short_gap', 'overlap', 'duplicate', 'gps']
const FLAG_KEY = {
  short: 'qualityFlagShort',
  long: 'qualityFlagLong',
  short_gap: 'qualityFlagShortGap',
  overlap: 'qualityFlagOverlap',
  duplicate: 'qualityFlagDuplicate',
  gps: 'qualityFlagGps',
}
const FLAG_CLS = {
  short: 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
  long: 'bg-violet-100 text-violet-700 dark:bg-violet-950/50 dark:text-violet-300',
  short_gap: 'bg-orange-100 text-orange-700 dark:bg-orange-950/50 dark:text-orange-300',
  overlap: 'bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300',
  duplicate: 'bg-pink-100 text-pink-700 dark:bg-pink-950/50 dark:text-pink-300',
  gps: 'bg-teal-100 text-teal-700 dark:bg-teal-950/50 dark:text-teal-300',
}

// Sin datos geo la señal «GPS clavado» está inactiva: su tarjeta/columna se oculta.
const visibleFlags = computed(() => FLAGS.filter((f) => f !== 'gps' || q.value?.gps_enabled))

// ---- Tendencia semanal: % de encuestas no admitidas sobre TODO lo recibido ----
const byWeek = computed(() => q.value?.by_week ?? [])
const showTrend = computed(() => byWeek.value.length >= 2)
const trendData = computed(() => ({
  labels: byWeek.value.map((w) => w.week),
  datasets: [{ data: byWeek.value.map((w) => w.pct), backgroundColor: '#dc2626', borderRadius: 4 }],
}))
const trendOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    tooltip: {
      callbacks: {
        label: (ctx) => {
          const w = byWeek.value[ctx.dataIndex]
          return `${t('stats.qualityTeamFlagged', { flagged: w.flagged, total: w.total })} · ${formatPctNumber(w.pct, locale.value)} %`
        },
      },
    },
  },
  scales: { y: { beginAtZero: true, ticks: { callback: (v) => v + ' %' } } },
}))

// Hueco con signo: negativo = solape (se muestra con «−»).
const fmtGap = (s) => (s == null ? '—' : s < 0 ? '−' + fmtDuration(-s) : fmtDuration(s))
const fmtMin = (m) => (m == null ? '—' : t('stats.qualityMinutes', { n: m }))

// Umbrales legibles para la línea de contexto bajo el título.
const thresholds = computed(() => {
  const th = q.value?.thresholds
  if (!th) return []
  return [
    { label: t('stats.qualityThrMin'), value: fmtMin(th.min_duration) },
    { label: t('stats.qualityThrMax'), value: fmtMin(th.max_duration) },
    { label: t('stats.qualityThrGap'), value: fmtMin(th.min_gap) },
    {
      label: t('stats.qualityThrDup'),
      value: th.dup_min_answers == null ? t('stats.qualityThrDupOff') : String(th.dup_min_answers),
    },
  ]
})

// Solo hay nivel de equipo si el formulario lo tiene configurado (y es visible).
const hasTeams = computed(() => !!q.value?.team_field)

// ---- Resumen de estado de revisión por equipo/encuestador (todos los envíos) ----
// Cuenta TODOS los estados y NO depende del alcance: un encuestador ya revisado sigue
// apareciendo (a diferencia de la lista de infracciones, que solo muestra el alcance).
const REVIEW_STATUSES = ['pending', 'on_hold', 'approved', 'rejected']
const REVIEW_CLS = {
  pending: 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
  on_hold: 'bg-sky-100 text-sky-700 dark:bg-sky-950/50 dark:text-sky-300',
  approved: 'bg-success-100 text-success-700 dark:bg-success-900/40 dark:text-success-300',
  rejected: 'bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300',
}
const reviewSummary = computed(() => q.value?.review_summary ?? [])
const pctOf = (n, total) => (total > 0 ? formatPctNumber((n * 100) / total, locale.value) + ' %' : '—')

// Zona horaria de inicio/fin (misma nota que «Actividad por hora» en Estadísticas).
const tzNote = computed(() => {
  const tz = q.value?.timezone
  return tz ? t('stats.qualityTzNote', { label: tz.label, offset: tz.offset }) : ''
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
        <div class="flex items-center gap-3">
          <RouterLink
            :to="{ name: 'risk', params: { id: formId } }"
            class="text-sm font-medium text-primary-600 hover:underline"
          >
            {{ $t('risk.link') }}
          </RouterLink>
          <RouterLink
            :to="{ name: 'stats', params: { id: formId } }"
            class="text-sm font-medium text-primary-600 hover:underline"
          >
            {{ $t('stats.qualityStatsLink') }}
          </RouterLink>
        </div>
      </div>
      <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
        {{ $t('stats.qualityTitle') }}{{ q ? ' · ' + q.form.name : '' }}
      </h1>
      <p class="mt-1 text-sm text-slate-500">{{ $t('stats.qualitySubtitle') }}</p>
    </header>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900">
      {{ error }}
    </div>
    <div v-if="flash" class="rounded-lg bg-success-50 px-3 py-2 text-sm text-success-800 ring-1 ring-success-200 dark:bg-success-900/30 dark:text-success-300 dark:ring-success-800">
      {{ flash }}
    </div>

    <Skeleton v-if="loading && !q" variant="cards" :count="4" />

    <template v-else-if="q">
      <!-- Umbrales y alcance activos (con atajo del admin a los ajustes del formulario) -->
      <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
        <span v-for="th in thresholds" :key="th.label">
          {{ th.label }}: <span class="font-semibold text-slate-700">{{ th.value }}</span>
        </span>
        <RouterLink
          v-if="auth.isAdmin || q.can_settings"
          :to="{ name: 'admin-form-settings', params: { id: formId } }"
          class="font-medium text-primary-600 hover:underline"
        >
          {{ $t('stats.qualityAdjust') }}
        </RouterLink>
        <!-- Alcance por estado de revisión (ajuste global; solo el admin puede cambiarlo) -->
        <span>
          {{ $t('stats.qualityScopeLabel') }}:
          <span class="font-semibold text-slate-700">{{ $t('stats.qualityScope_' + q.scope) }}</span>
          <RouterLink
            v-if="auth.isAdmin"
            :to="{ path: '/admin/settings', query: { tab: 'tables' } }"
            class="ml-1 font-medium text-primary-600 hover:underline"
          >{{ $t('stats.qualityScopeChange') }}</RouterLink>
        </span>
      </div>

      <!-- Recuento general: evaluadas, no admitidas y las banderas activas -->
      <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
          <p class="text-xs text-slate-400">{{ $t('stats.qualityCardTotal') }}</p>
          <p class="mt-1 text-2xl font-semibold text-slate-900">{{ q.total - q.untimed }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
          <p class="text-xs text-slate-400">{{ $t('stats.qualityCardFlagged') }}</p>
          <p class="mt-1 text-2xl font-semibold" :class="q.flagged ? 'text-red-600 dark:text-red-400' : 'text-success-600 dark:text-success-400'">
            {{ q.flagged }}
            <span v-if="q.flagged" class="text-sm font-medium text-slate-400" :title="$t('stats.qualityRateTitle')">· {{ fmtRate(q.flagged, q.received) }}</span>
          </p>
        </div>
        <div v-for="f in visibleFlags" :key="f" class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
          <p class="text-xs text-slate-400">{{ $t('stats.' + FLAG_KEY[f], 2) }}</p>
          <p class="mt-1 text-2xl font-semibold text-slate-900">{{ q.flags[f] }}</p>
        </div>
      </div>
      <p v-if="q.untimed" class="text-xs text-slate-400">{{ $t('stats.qualityUntimed', { n: q.untimed }) }}</p>
      <p v-if="q.gps_enabled" class="text-xs text-slate-400">
        {{ $t('stats.qualityGpsHint', { n: q.gps_min_repeats }) }}
      </p>

      <!-- Tendencia semanal (solo con 2+ semanas: con una sola no hay tendencia) -->
      <div v-if="showTrend" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h2 class="font-semibold text-slate-900">{{ $t('stats.qualityTrend') }}</h2>
        <p class="mb-3 text-xs text-slate-400">{{ $t('stats.qualityTrendDesc') }}</p>
        <div class="h-56"><StatsChart type="bar" :data="trendData" :options="trendOptions" /></div>
      </div>

      <!-- Resumen de estado de revisión por equipo → encuestador (todos los envíos) -->
      <section v-if="reviewSummary.length" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h2 class="font-semibold text-slate-900">{{ $t('stats.qualityReviewSummary') }}</h2>
        <p class="mb-3 text-xs text-slate-400">{{ $t('stats.qualityReviewSummaryDesc') }}</p>
        <div class="space-y-3">
          <details
            v-for="(team, i) in reviewSummary"
            :key="i"
            class="overflow-hidden rounded-lg ring-1 ring-slate-200 dark:ring-slate-700"
            :open="!hasTeams || reviewSummary.length <= 3"
          >
            <summary
              class="flex cursor-pointer list-none flex-wrap items-center gap-x-3 gap-y-1 bg-slate-50 px-4 py-2 hover:bg-slate-100 dark:bg-slate-800/40"
              :class="{ 'pointer-events-none': !hasTeams }"
            >
              <span class="font-medium text-slate-800">{{ hasTeams ? team.name : $t('stats.qualityAllEnumerators') }}</span>
              <span class="text-xs text-slate-500">{{ $t('stats.colVolume') }}: {{ team.total }}</span>
              <span class="ml-auto flex flex-wrap gap-1.5 text-xs">
                <span v-for="s in REVIEW_STATUSES" :key="s" v-show="team.status[s]" class="rounded-full px-2 py-0.5" :class="REVIEW_CLS[s]">
                  {{ $t('review.' + s) }}: <span class="font-semibold">{{ team.status[s] }}</span>
                </span>
              </span>
            </summary>
            <div class="overflow-x-auto border-t border-slate-100 dark:border-slate-700">
              <table class="w-full whitespace-nowrap text-left text-sm">
                <thead class="text-xs uppercase tracking-wider text-slate-400">
                  <tr>
                    <th class="px-4 py-1.5">{{ $t('stats.colEnumerator') }}</th>
                    <th class="px-4 py-1.5">{{ $t('stats.colVolume') }}</th>
                    <th v-for="s in REVIEW_STATUSES" :key="s" class="px-4 py-1.5">{{ $t('review.' + s) }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                  <tr v-for="(e, j) in team.enumerators" :key="j">
                    <td class="px-4 py-1.5 font-medium text-slate-700">{{ e.name }}</td>
                    <td class="px-4 py-1.5 text-slate-600">{{ e.total }}</td>
                    <td v-for="s in REVIEW_STATUSES" :key="s" class="px-4 py-1.5" :class="e.status[s] ? 'text-slate-700' : 'text-slate-300'">
                      <template v-if="e.status[s]">
                        {{ e.status[s] }}
                        <span class="text-xs text-slate-400">· {{ pctOf(e.status[s], e.total) }}</span>
                      </template>
                      <template v-else>—</template>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </details>
        </div>
      </section>

      <!-- Marcado en lote sobre el flujo de revisión existente -->
      <div
        v-if="canReview && q.flagged"
        class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-accent-50 px-4 py-3 ring-1 ring-accent-200 dark:bg-accent-900/25 dark:ring-accent-800"
      >
        <span class="text-sm text-accent-900 dark:text-accent-200">
          {{ !pendingHold.length
            ? $t('stats.qualityAllHeld')
            : heldCount
              ? $t('stats.qualityBatchHintHeld', { total: q.flagged, held: heldCount })
              : $t('stats.qualityBatchHint') }}
        </span>
        <button
          v-if="pendingHold.length"
          :disabled="batchBusy"
          class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-700 disabled:opacity-60"
          @click="holdAll"
        >
          {{ batchBusy
            ? $t('stats.qualityBatchBusy')
            : heldCount
              ? $t('stats.qualityBatchBtnRest', { n: pendingHold.length })
              : $t('stats.qualityBatchBtn', { n: pendingHold.length }) }}
        </button>
      </div>

      <p v-if="!q.flagged" class="rounded-xl bg-white px-5 py-8 text-center text-sm text-slate-400 shadow-sm ring-1 ring-slate-200">
        {{ $t('stats.qualityEmpty') }}
      </p>

      <!-- Por equipo (si está configurado) → encuestador → infractoras -->
      <section v-else class="space-y-3">
        <details
          v-for="(team, i) in q.teams"
          :key="i"
          class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200"
          :open="!hasTeams || q.teams.length <= 3"
        >
          <summary
            class="flex cursor-pointer list-none flex-wrap items-center gap-x-3 gap-y-1 px-5 py-3 hover:bg-slate-50"
            :class="{ 'pointer-events-none': !hasTeams }"
          >
            <span class="font-semibold text-slate-900">{{ hasTeams ? team.name : $t('stats.qualityAllEnumerators') }}</span>
            <!-- No admitidas / total recibido del equipo, y su % (mismo denominador) -->
            <span class="text-sm text-slate-500" :title="$t('stats.qualityRateTitle')">
              {{ $t('stats.qualityTeamFlagged', { flagged: team.flagged, total: team.total }) }}
            </span>
            <span
              v-if="team.flagged"
              class="text-sm font-semibold text-red-600 dark:text-red-400"
              :title="$t('stats.qualityRateTitle')"
            >{{ fmtRate(team.flagged, team.total) }}</span>
            <span class="ml-auto flex gap-1.5 text-xs font-medium">
              <span
                v-for="f in visibleFlags"
                :key="f"
                v-show="team.flags[f]"
                class="rounded-full px-2 py-0.5"
                :class="FLAG_CLS[f]"
                :title="$t('stats.' + FLAG_KEY[f], 2)"
              >{{ team.flags[f] }}</span>
              <span v-if="!team.flagged" class="text-slate-300">—</span>
            </span>
          </summary>

          <!-- Un bloque por encuestador, con SU PROPIO encabezado repetido (con muchas
               infractoras del anterior, una fila suelta quedaba «en el aire»).
               Todos los valores alineados a la izquierda. -->
          <div class="space-y-4 border-t border-slate-100 px-5 py-3">
            <div v-for="(e, j) in team.enumerators" :key="j">
              <div class="overflow-x-auto">
                <table class="w-full whitespace-nowrap text-left text-sm">
                  <thead class="text-xs uppercase tracking-wider text-slate-400">
                    <tr>
                      <th class="py-1 pr-4">{{ $t('stats.colEnumerator') }}</th>
                      <th class="py-1 pr-4">{{ $t('stats.colVolume') }}</th>
                      <th v-for="f in visibleFlags" :key="f" class="py-1 pr-4">{{ $t('stats.' + FLAG_KEY[f], 2) }}</th>
                      <th class="py-1">{{ $t('stats.qualityCardFlagged') }}</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr>
                      <td class="py-1.5 pr-4 font-medium text-slate-700">{{ e.name }}</td>
                      <td class="py-1.5 pr-4 text-slate-600">{{ e.total }}</td>
                      <td v-for="f in visibleFlags" :key="f" class="py-1.5 pr-4" :class="e.flags[f] ? 'font-semibold text-slate-700' : 'text-slate-300'">
                        {{ e.flags[f] || '—' }}
                      </td>
                      <td class="py-1.5 font-semibold" :class="e.flagged ? 'text-red-600 dark:text-red-400' : 'text-slate-300'">
                        <template v-if="e.flagged">
                          {{ e.flagged }} <span class="text-xs font-medium text-slate-400" :title="$t('stats.qualityRateTitle')">· {{ fmtRate(e.flagged, e.total) }}</span>
                        </template>
                        <template v-else>—</template>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <!-- Drill-down: las encuestas infractoras del encuestador -->
              <div v-if="e.violations.length" class="mt-1 rounded-lg bg-slate-50/60 px-3 pb-3 pt-1 dark:bg-slate-800/30">
                <div class="overflow-x-auto">
                  <table class="w-full whitespace-nowrap text-left text-xs">
                    <thead class="uppercase tracking-wider text-slate-400">
                      <tr>
                        <th class="py-1 pr-4">{{ $t('stats.qualityColStart') }}</th>
                        <th class="py-1 pr-4">{{ $t('stats.qualityColEnd') }}</th>
                        <th class="py-1 pr-4">{{ $t('stats.qualityColDuration') }}</th>
                        <th class="py-1 pr-4">{{ $t('stats.qualityColGap') }}</th>
                        <th class="py-1 pr-4">{{ $t('stats.qualityColFlags') }}</th>
                        <th class="py-1 pr-4">{{ $t('stats.qualityColReview') }}</th>
                        <th class="py-1"></th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                      <tr v-for="v in e.violations" :key="v.uid">
                        <td class="py-1.5 pr-4 text-slate-600">{{ v.start_at ?? '—' }}</td>
                        <td class="py-1.5 pr-4 text-slate-600">{{ v.end_at ?? '—' }}</td>
                        <td class="py-1.5 pr-4 text-slate-600">{{ fmtDuration(v.duration_s) }}</td>
                        <td class="py-1.5 pr-4" :class="v.gap_s != null && v.gap_s < 0 ? 'font-semibold text-red-600 dark:text-red-400' : 'text-slate-600'">
                          {{ fmtGap(v.gap_s) }}
                        </td>
                        <td class="py-1.5 pr-4">
                          <span
                            v-for="f in v.flags"
                            :key="f"
                            class="mr-1 inline-block rounded-full px-2 py-0.5 font-medium"
                            :class="FLAG_CLS[f]"
                          >{{ $t('stats.' + FLAG_KEY[f], 1) }}</span>
                        </td>
                        <td class="py-1.5 pr-4"><ReviewBadge :status="v.review_status" /></td>
                        <td class="py-1.5">
                          <RouterLink
                            :to="{ name: 'submission-detail', params: { id: formId, subId: v.uid }, query: { from: 'quality' } }"
                            class="font-medium text-primary-600 hover:underline"
                          >
                            {{ $t('stats.qualityView') }}
                          </RouterLink>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        </details>
        <p v-if="tzNote" class="text-xs text-slate-400">{{ tzNote }}</p>
      </section>
    </template>
  </div>
</template>
