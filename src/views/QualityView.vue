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
import Skeleton from '../components/Skeleton.vue'
import ReviewBadge from '../components/ReviewBadge.vue'

const { t } = useI18n()
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

// Las cuatro banderas, en el orden canónico del backend.
const FLAGS = ['short', 'long', 'short_gap', 'overlap']
const FLAG_KEY = {
  short: 'qualityFlagShort',
  long: 'qualityFlagLong',
  short_gap: 'qualityFlagShortGap',
  overlap: 'qualityFlagOverlap',
}
const FLAG_CLS = {
  short: 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
  long: 'bg-violet-100 text-violet-700 dark:bg-violet-950/50 dark:text-violet-300',
  short_gap: 'bg-orange-100 text-orange-700 dark:bg-orange-950/50 dark:text-orange-300',
  overlap: 'bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300',
}

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
  ]
})

// Solo hay nivel de equipo si el formulario lo tiene configurado (y es visible).
const hasTeams = computed(() => !!q.value?.team_field)

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
      <RouterLink
        :to="{ name: 'submissions', params: { id: formId } }"
        class="text-sm text-primary-600 hover:underline"
      >
        {{ $t('stats.back') }}
      </RouterLink>
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
      <!-- Umbrales activos (con atajo del admin a los ajustes del formulario) -->
      <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
        <span v-for="th in thresholds" :key="th.label">
          {{ th.label }}: <span class="font-semibold text-slate-700">{{ th.value }}</span>
        </span>
        <RouterLink
          v-if="auth.isAdmin"
          :to="{ name: 'admin-form-settings', params: { id: formId } }"
          class="font-medium text-primary-600 hover:underline"
        >
          {{ $t('stats.qualityAdjust') }}
        </RouterLink>
      </div>

      <!-- Recuento general: evaluadas, no admitidas y las cuatro banderas -->
      <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
          <p class="text-xs text-slate-400">{{ $t('stats.qualityCardTotal') }}</p>
          <p class="mt-1 text-2xl font-semibold text-slate-900">{{ q.total - q.untimed }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
          <p class="text-xs text-slate-400">{{ $t('stats.qualityCardFlagged') }}</p>
          <p class="mt-1 text-2xl font-semibold" :class="q.flagged ? 'text-red-600 dark:text-red-400' : 'text-success-600 dark:text-success-400'">
            {{ q.flagged }}
          </p>
        </div>
        <div v-for="f in FLAGS" :key="f" class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
          <p class="text-xs text-slate-400">{{ $t('stats.' + FLAG_KEY[f], 2) }}</p>
          <p class="mt-1 text-2xl font-semibold text-slate-900">{{ q.flags[f] }}</p>
        </div>
      </div>
      <p v-if="q.untimed" class="text-xs text-slate-400">{{ $t('stats.qualityUntimed', { n: q.untimed }) }}</p>

      <!-- Marcado en lote sobre el flujo de revisión existente -->
      <div
        v-if="canReview && q.flagged"
        class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-accent-50 px-4 py-3 ring-1 ring-accent-200 dark:bg-accent-900/25 dark:ring-accent-800"
      >
        <span class="text-sm text-accent-900 dark:text-accent-200">
          {{ pendingHold.length ? $t('stats.qualityBatchHint') : $t('stats.qualityAllHeld') }}
        </span>
        <button
          v-if="pendingHold.length"
          :disabled="batchBusy"
          class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-700 disabled:opacity-60"
          @click="holdAll"
        >
          {{ batchBusy ? $t('stats.qualityBatchBusy') : $t('stats.qualityBatchBtn', { n: pendingHold.length }) }}
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
            <span class="text-sm text-slate-500">{{ $t('stats.qualityTeamCount', { n: team.count }) }}</span>
            <span class="ml-auto flex gap-1.5 text-xs font-medium">
              <span
                v-for="f in FLAGS"
                :key="f"
                v-show="team.flags[f]"
                class="rounded-full px-2 py-0.5"
                :class="FLAG_CLS[f]"
                :title="$t('stats.' + FLAG_KEY[f], 2)"
              >{{ team.flags[f] }}</span>
              <span v-if="!team.flagged" class="text-slate-300">—</span>
            </span>
          </summary>

          <div class="border-t border-slate-100 px-5 py-3">
            <div class="overflow-x-auto">
              <table class="w-full whitespace-nowrap text-left text-sm">
                <thead class="text-xs uppercase tracking-wider text-slate-400">
                  <tr>
                    <th class="py-1 pr-3">{{ $t('stats.colEnumerator') }}</th>
                    <th class="py-1 pr-3 text-right">{{ $t('stats.colVolume') }}</th>
                    <th v-for="f in FLAGS" :key="f" class="py-1 pr-3 text-right">{{ $t('stats.' + FLAG_KEY[f], 2) }}</th>
                    <th class="py-1 text-right">{{ $t('stats.qualityCardFlagged') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <template v-for="(e, j) in team.enumerators" :key="j">
                    <tr>
                      <td class="py-1.5 pr-3 font-medium text-slate-700">{{ e.name }}</td>
                      <td class="py-1.5 pr-3 text-right text-slate-600">{{ e.count }}</td>
                      <td v-for="f in FLAGS" :key="f" class="py-1.5 pr-3 text-right" :class="e.flags[f] ? 'font-semibold text-slate-700' : 'text-slate-300'">
                        {{ e.flags[f] || '—' }}
                      </td>
                      <td class="py-1.5 text-right font-semibold" :class="e.flagged ? 'text-red-600 dark:text-red-400' : 'text-slate-300'">
                        {{ e.flagged || '—' }}
                      </td>
                    </tr>
                    <!-- Drill-down: las encuestas infractoras del encuestador -->
                    <tr v-if="e.violations.length">
                      <td colspan="8" class="bg-slate-50/60 px-3 pb-3 pt-1 dark:bg-slate-800/30">
                        <div class="overflow-x-auto">
                          <table class="w-full whitespace-nowrap text-left text-xs">
                            <thead class="uppercase tracking-wider text-slate-400">
                              <tr>
                                <th class="py-1 pr-3">{{ $t('stats.qualityColStart') }}</th>
                                <th class="py-1 pr-3">{{ $t('stats.qualityColEnd') }}</th>
                                <th class="py-1 pr-3 text-right">{{ $t('stats.qualityColDuration') }}</th>
                                <th class="py-1 pr-3 text-right">{{ $t('stats.qualityColGap') }}</th>
                                <th class="py-1 pr-3">{{ $t('stats.qualityColFlags') }}</th>
                                <th class="py-1 pr-3">{{ $t('stats.qualityColReview') }}</th>
                                <th class="py-1"></th>
                              </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                              <tr v-for="v in e.violations" :key="v.uid">
                                <td class="py-1.5 pr-3 text-slate-600">{{ v.start_at ?? '—' }}</td>
                                <td class="py-1.5 pr-3 text-slate-600">{{ v.end_at ?? '—' }}</td>
                                <td class="py-1.5 pr-3 text-right text-slate-600">{{ fmtDuration(v.duration_s) }}</td>
                                <td class="py-1.5 pr-3 text-right" :class="v.gap_s != null && v.gap_s < 0 ? 'font-semibold text-red-600 dark:text-red-400' : 'text-slate-600'">
                                  {{ fmtGap(v.gap_s) }}
                                </td>
                                <td class="py-1.5 pr-3">
                                  <span
                                    v-for="f in v.flags"
                                    :key="f"
                                    class="mr-1 inline-block rounded-full px-2 py-0.5 font-medium"
                                    :class="FLAG_CLS[f]"
                                  >{{ $t('stats.' + FLAG_KEY[f], 1) }}</span>
                                </td>
                                <td class="py-1.5 pr-3"><ReviewBadge :status="v.review_status" /></td>
                                <td class="py-1.5 text-right">
                                  <RouterLink
                                    :to="{ name: 'submission-detail', params: { id: formId, subId: v.uid } }"
                                    class="font-medium text-primary-600 hover:underline"
                                  >
                                    {{ $t('stats.qualityView') }}
                                  </RouterLink>
                                </td>
                              </tr>
                            </tbody>
                          </table>
                        </div>
                      </td>
                    </tr>
                  </template>
                </tbody>
              </table>
            </div>
          </div>
        </details>
        <p v-if="tzNote" class="text-xs text-slate-400">{{ tzNote }}</p>
      </section>
    </template>
  </div>
</template>
