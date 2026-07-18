<script setup>
/**
 * Panel de cumplimiento de la MUESTRA por equipo: hecho/objetivo por celda
 * `equipo × valor`, totales y proyección por equipo. Solo lectura; respeta el
 * scoping por filas del usuario (un jefe de equipo ve el suyo). El plan se edita
 * en los ajustes del formulario (SamplePlanEditor).
 */
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, RouterLink } from 'vue-router'
import api from '../services/api'
import { apiError, useAuthStore } from '../stores/auth'
import { usePctFormat } from '../composables/appConfig'
import Skeleton from '../components/Skeleton.vue'

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

        <!-- Por equipo -->
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

        <p v-if="!data.teams.length" class="rounded-xl bg-white p-6 text-center text-sm text-slate-400 shadow-sm ring-1 ring-slate-200">
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
