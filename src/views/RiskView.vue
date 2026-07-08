<script setup>
/**
 * Índice de riesgo por equipo/encuestador: detección HEURÍSTICA de fabricación
 * («curbstoning»), siempre desglosada en componentes con su valor, la mediana del
 * equipo y una frase llana. Es una señal para PRIORIZAR back-checks, NO una prueba.
 * Opt-in: si el formulario no define `risk_min_n`, se muestra el estado vacío que
 * invita a configurarlo. Lo sirve GET /forms/{id}/risk (lib/Risk).
 */
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, RouterLink } from 'vue-router'
import api from '../services/api'
import { useAuthStore, apiError } from '../stores/auth'
import { usePctFormat } from '../composables/appConfig'
import Skeleton from '../components/Skeleton.vue'

const { t, locale } = useI18n()
const route = useRoute()
const auth = useAuthStore()
const formId = computed(() => Number(route.params.id))

const r = ref(null)
const loading = ref(true)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get(`/forms/${formId.value}/risk`)
    r.value = data.data
  } catch (e) {
    error.value = apiError(e, t('risk.loadError'))
  } finally {
    loading.value = false
  }
}

const { formatPctNumber } = usePctFormat()
const hasTeams = computed(() => !!r.value?.team_field)

// Componentes por-encuestador (en orden del backend) + señal de equipo.
const PCT_METRICS = ['percentmatch', 'straightlining', 'distribution', 'skip_rate', 'benford', 'team_distribution']

function fmtValue(key, val) {
  if (val == null) return '—'
  if (PCT_METRICS.includes(key)) return formatPctNumber(val * 100, locale.value) + ' %'
  if (key === 'gps_cluster') return t('risk.meters', { n: Math.round(val) })
  if (key === 'productivity') return t('risk.perDay', { n: val.toLocaleString(locale.value, { maximumFractionDigits: 1 }) })
  return String(val)
}

// Nivel (chip de color) a partir del z de un componente o del índice.
function levelOf(z) {
  if (z == null) return 'low'
  if (z >= 2) return 'high'
  if (z >= 1) return 'elevated'
  return 'low'
}
const LEVEL_CLS = {
  high: 'bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300',
  elevated: 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
  low: 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
}
const idxCls = (idx) => LEVEL_CLS[levelOf(idx)]

// Estados de revisión: contexto compacto (en espera / rechazadas) por encuestador.
const attention = (status) => (status?.on_hold ?? 0) + (status?.rejected ?? 0)

// Señales activas / inactivas (con su motivo), para explicar qué se está usando.
const signals = computed(() => r.value?.signals ?? [])

const canConfigure = computed(() => auth.isAdmin || r.value?.can_settings)

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header>
      <div class="flex items-center justify-between gap-3">
        <RouterLink :to="{ name: 'submissions', params: { id: formId } }" class="text-sm text-primary-600 hover:underline">
          {{ $t('stats.back') }}
        </RouterLink>
        <div class="flex items-center gap-3">
          <RouterLink :to="{ name: 'quality', params: { id: formId } }" class="text-sm font-medium text-primary-600 hover:underline">
            {{ $t('risk.qualityLink') }}
          </RouterLink>
          <RouterLink :to="{ name: 'stats', params: { id: formId } }" class="text-sm font-medium text-primary-600 hover:underline">
            {{ $t('stats.qualityStatsLink') }}
          </RouterLink>
        </div>
      </div>
      <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
        {{ $t('risk.title') }}{{ r ? ' · ' + r.form.name : '' }}
      </h1>
      <p class="mt-1 text-sm text-slate-500">{{ $t('risk.subtitle') }}</p>
    </header>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900">
      {{ error }}
    </div>

    <!-- Aviso metodológico: destacado y permanente -->
    <div class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200 dark:bg-amber-950/30 dark:text-amber-200 dark:ring-amber-900">
      <p class="font-semibold">{{ $t('risk.warningTitle') }}</p>
      <p class="mt-0.5 text-amber-800 dark:text-amber-300/90">{{ $t('risk.warningBody') }}</p>
    </div>

    <Skeleton v-if="loading && !r" variant="cards" :count="3" />

    <template v-else-if="r">
      <!-- Estado vacío: el índice no está activado (opt-in) -->
      <div v-if="!r.enabled" class="rounded-xl bg-white px-5 py-10 text-center shadow-sm ring-1 ring-slate-200">
        <p class="text-sm text-slate-500">{{ $t('risk.disabled') }}</p>
        <RouterLink
          v-if="canConfigure"
          :to="{ name: 'admin-form-settings', params: { id: formId } }"
          class="mt-3 inline-block rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700"
        >
          {{ $t('risk.configure') }}
        </RouterLink>
      </div>

      <template v-else>
        <!-- Resumen + alcance -->
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
          <span>{{ $t('risk.minN') }}: <span class="font-semibold text-slate-700">{{ r.min_n }}</span></span>
          <span>{{ $t('risk.scored') }}: <span class="font-semibold text-slate-700">{{ r.scored }}</span></span>
          <span v-if="r.insufficient">{{ $t('risk.insufficient', { n: r.insufficient }) }}</span>
          <span>{{ $t('stats.qualityScopeLabel') }}: <span class="font-semibold text-slate-700">{{ $t('stats.qualityScope_' + r.scope) }}</span></span>
          <RouterLink
            v-if="canConfigure"
            :to="{ name: 'admin-form-settings', params: { id: formId } }"
            class="font-medium text-primary-600 hover:underline"
          >{{ $t('stats.qualityAdjust') }}</RouterLink>
        </div>

        <!-- Qué señales se están usando -->
        <div class="flex flex-wrap gap-1.5 text-xs">
          <span
            v-for="s in signals"
            :key="s.key"
            class="rounded-full px-2 py-0.5"
            :class="s.active ? 'bg-primary-100 text-primary-700 dark:bg-primary-950/50 dark:text-primary-300' : 'bg-slate-100 text-slate-400 dark:bg-slate-800'"
            :title="s.active ? '' : $t('risk.reason.' + s.reason)"
          >{{ $t('risk.metric.' + s.key) }}</span>
        </div>

        <p v-if="!r.teams.length" class="rounded-xl bg-white px-5 py-8 text-center text-sm text-slate-400 shadow-sm ring-1 ring-slate-200">
          {{ $t('risk.noData') }}
        </p>

        <!-- Por equipo → encuestador (expandible) -->
        <section v-else class="space-y-3">
          <details
            v-for="(team, i) in r.teams"
            :key="i"
            class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200"
            :open="!hasTeams || r.teams.length <= 3"
          >
            <summary
              class="flex cursor-pointer list-none flex-wrap items-center gap-x-3 gap-y-1 px-5 py-3 hover:bg-slate-50"
              :class="{ 'pointer-events-none': !hasTeams }"
            >
              <span class="font-semibold text-slate-900">{{ hasTeams ? team.name : $t('stats.qualityAllEnumerators') }}</span>
              <span class="text-xs text-slate-500">{{ $t('risk.scored') }}: {{ team.scored }}</span>
              <span v-if="team.harbors.over_threshold" class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-950/50 dark:text-red-300">
                {{ $t('risk.harbors', { n: team.harbors.over_threshold }) }}
              </span>
              <span v-if="team.index != null" class="ml-auto rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="idxCls(team.index)">
                {{ $t('risk.index') }}: {{ team.index.toFixed(1) }}
              </span>
            </summary>

            <div class="border-t border-slate-100 px-5 py-3 dark:border-slate-700">
              <!-- Señal de nivel de equipo (distribución vs pool de equipos) -->
              <div v-if="team.components.length" class="mb-3 space-y-1">
                <div v-for="c in team.components" :key="c.key" class="flex flex-wrap items-baseline gap-x-2 text-sm">
                  <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="LEVEL_CLS[c.level]">{{ $t('risk.metric.' + c.key) }}</span>
                  <span class="text-slate-700">{{ fmtValue(c.key, c.value) }}</span>
                  <span class="text-xs text-slate-400">{{ $t('risk.peerMedian') }}: {{ fmtValue(c.key, c.peer_median) }}</span>
                  <span class="text-xs text-slate-400">· {{ $t('risk.explain.' + c.key) }}</span>
                </div>
              </div>

              <!-- Encuestadores: puntuables (expandibles con sus componentes) + insuficientes -->
              <div class="space-y-2">
                <details
                  v-for="(e, j) in team.enumerators"
                  :key="j"
                  class="rounded-lg ring-1 ring-slate-200 dark:ring-slate-700"
                  :class="{ 'opacity-70': e.insufficient }"
                >
                  <summary class="flex cursor-pointer list-none flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2 hover:bg-slate-50 dark:hover:bg-slate-800/40">
                    <span class="font-medium text-slate-800">{{ e.name }}</span>
                    <span class="text-xs text-slate-500">{{ $t('stats.colVolume') }}: {{ e.count }}</span>
                    <span v-if="attention(e.status)" class="text-xs text-slate-400">
                      {{ $t('review.on_hold') }}/{{ $t('review.rejected') }}: {{ e.status.on_hold }}/{{ e.status.rejected }}
                    </span>
                    <span v-if="e.insufficient" class="ml-auto rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-slate-800">
                      {{ $t('risk.insufficientChip') }}
                    </span>
                    <span v-else class="ml-auto rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="idxCls(e.index)">
                      {{ $t('risk.index') }}: {{ e.index.toFixed(1) }}
                    </span>
                  </summary>

                  <div v-if="!e.insufficient && e.components.length" class="border-t border-slate-100 dark:border-slate-700">
                    <table class="w-full text-left text-sm">
                      <thead class="text-xs uppercase tracking-wider text-slate-400">
                        <tr>
                          <th class="px-4 py-1.5">{{ $t('risk.colComponent') }}</th>
                          <th class="px-4 py-1.5">{{ $t('risk.colValue') }}</th>
                          <th class="px-4 py-1.5">{{ $t('risk.peerMedian') }}</th>
                          <th class="px-4 py-1.5">{{ $t('risk.colLevel') }}</th>
                        </tr>
                      </thead>
                      <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="c in e.components" :key="c.key" class="align-top">
                          <td class="px-4 py-2">
                            <p class="font-medium text-slate-700">{{ $t('risk.metric.' + c.key) }}</p>
                            <p class="mt-0.5 max-w-md whitespace-normal text-xs text-slate-400">{{ $t('risk.explain.' + c.key) }}</p>
                          </td>
                          <td class="px-4 py-2 font-semibold text-slate-700">{{ fmtValue(c.key, c.value) }}</td>
                          <td class="px-4 py-2 text-slate-500">{{ fmtValue(c.key, c.peer_median) }}</td>
                          <td class="px-4 py-2">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="LEVEL_CLS[c.level]">
                              {{ $t('risk.level.' + c.level) }}
                            </span>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                  <p v-else-if="!e.insufficient" class="px-4 py-2 text-xs text-slate-400">{{ $t('risk.noComponents') }}</p>
                </details>
              </div>
            </div>
          </details>
        </section>
      </template>
    </template>
  </div>
</template>
