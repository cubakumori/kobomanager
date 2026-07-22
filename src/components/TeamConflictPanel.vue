<script setup>
/**
 * Incongruencias equipo ↔ meta-equipo en la página de Control de calidad:
 * tarjeta con los conflictos (equipos cuyos envíos apuntan a >1 meta-equipo) y el
 * flujo de resolución según el modo del formulario (GET/POST /forms/{id}/team-conflicts).
 *
 * Salvaguardas del diseño (ROADMAP jul-2026): el disparo es SIEMPRE manual desde
 * la tarjeta; los modos automáticos muestran un RESUMEN por tanda (la lista de
 * cambios, un solo OK — nunca escritura invisible); lo no resuelto cae a la
 * confirmación caso a caso, donde el desempate por encuestador solo PREselecciona.
 * La escritura va por el flujo de edición real (permisos de edición incluidos).
 */
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import api from '../services/api'
import { useAuthStore, apiError } from '../stores/auth'
import Modal from './Modal.vue'

const props = defineProps({ formId: { type: Number, required: true } })
const emit = defineEmits(['changed'])
const { t } = useI18n()
const auth = useAuthStore()

const data = ref(null)
const error = ref('')
const flash = ref('')

async function load() {
  error.value = ''
  try {
    const { data: res } = await api.get(`/forms/${props.formId}/team-conflicts`)
    data.value = res.data
  } catch (e) {
    // Sin permiso «Ajustes» (u otro fallo) la tarjeta simplemente no aparece:
    // es una sección accesoria del QC, no bloquea la página.
    data.value = null
  }
}
onMounted(load)

// La tarjeta solo existe con la cadena configurada Y conflictos reales — o con el
// aviso de la última tanda pendiente de leer (resolver el último conflicto no debe
// tragarse el «N correcciones aplicadas»).
const show = computed(() => !!data.value?.enabled && (data.value.conflict_teams.length > 0 || !!flash.value))
const cases = computed(() => data.value?.cases ?? [])
const mode = computed(() => data.value?.mode ?? 'approx')
const isAuto = computed(() => ['approx', 'first', 'least'].includes(mode.value))
const canApply = computed(() => !!data.value?.can_apply && cases.value.length > 0)

// Equipos candidatos del meta-equipo de un caso (para los modales).
const groupsByMeta = computed(() => {
  const m = {}
  for (const g of data.value?.meta_groups ?? []) m[g.value] = g
  return m
})

// ---- Aplicación de tandas (troceo corto: cada cambio es un PATCH real a Kobo) ----
const busy = ref(false)
const progress = ref('')
let totalApplied = 0
let totalFailed = 0

async function postChanges(changes) {
  for (let i = 0; i < changes.length; i += 10) {
    progress.value = t('teamConflicts.applying', { i: Math.min(i + 10, changes.length), n: changes.length })
    const { data: res } = await api.post(`/forms/${props.formId}/team-conflicts`, {
      changes: changes.slice(i, i + 10).map((c) => ({ uid: c.uid, field: c.field, value: c.value })),
    })
    totalApplied += res.data.applied
    totalFailed += res.data.failed.length
  }
}

// Cierre del flujo completo: aviso, recarga y notificación al padre (la página
// de QC recalcula — los nombres de equipo del análisis acaban de cambiar).
async function finish() {
  busy.value = false
  progress.value = ''
  if (totalApplied || totalFailed) {
    flash.value = totalFailed
      ? t('teamConflicts.doneFailed', { n: totalApplied, f: totalFailed })
      : t('teamConflicts.done', { n: totalApplied })
    emit('changed')
    await load()
  }
}

function startResolve() {
  flash.value = ''
  error.value = ''
  totalApplied = 0
  totalFailed = 0
  if (mode.value === 'confirm_group') startGroupQueue()
  else if (mode.value === 'confirm_each') startCaseQueue(cases.value)
  else summaryOpen.value = true
}

// ---- Modos automáticos: resumen por tanda (un solo OK) ----------------------
const summaryOpen = ref(false)
const resolved = computed(() => cases.value.filter((c) => c.suggestion))
const unresolved = computed(() => cases.value.filter((c) => !c.suggestion))

async function applySummary() {
  busy.value = true
  error.value = ''
  const rest = unresolved.value
  try {
    await postChanges(resolved.value.map((c) => ({ uid: c.uid, field: 'team', value: c.suggestion.value })))
    summaryOpen.value = false
    busy.value = false
    progress.value = ''
    // Lo no resuelto cae a la confirmación caso a caso (diseño del modo 1).
    if (rest.length) startCaseQueue(rest)
    else await finish()
  } catch (e) {
    error.value = apiError(e, t('teamConflicts.applyError'))
    summaryOpen.value = false
    await finish()
  }
}

// ---- Confirmación general: UN equipo para todos los casos del meta-equipo ----
const groupQueue = ref([])
const groupIdx = ref(0)
const groupPick = ref('')
const groupOpen = ref(false)
const curGroup = computed(() => groupQueue.value[groupIdx.value] ?? null)
const groupCases = computed(() =>
  curGroup.value ? cases.value.filter((c) => c.meta.value === curGroup.value.value) : [])

function startGroupQueue() {
  groupQueue.value = (data.value?.meta_groups ?? []).filter((g) => g.cases > 0)
  if (!groupQueue.value.length) return
  groupIdx.value = 0
  groupPick.value = ''
  groupOpen.value = true
}

async function nextGroup() {
  if (groupIdx.value + 1 < groupQueue.value.length) {
    groupIdx.value++
    groupPick.value = ''
  } else {
    groupOpen.value = false
    await finish()
  }
}

async function applyGroup() {
  if (!groupPick.value) return
  busy.value = true
  error.value = ''
  try {
    await postChanges(groupCases.value.map((c) => ({ uid: c.uid, field: 'team', value: groupPick.value })))
  } catch (e) {
    error.value = apiError(e, t('teamConflicts.applyError'))
  }
  busy.value = false
  await nextGroup()
}

// ---- Confirmación particular: modal por caso (también cola de no resueltos) ----
const caseQueue = ref([])
const caseIdx = ref(0)
const casePick = ref('') // 'team:<valor>' | 'meta' | ''
const caseOpen = ref(false)
const curCase = computed(() => caseQueue.value[caseIdx.value] ?? null)
const caseTeams = computed(() => (curCase.value ? groupsByMeta.value[curCase.value.meta.value]?.teams ?? [] : []))

function startCaseQueue(list) {
  caseQueue.value = list
  if (!list.length) return
  caseIdx.value = 0
  presetCase()
  caseOpen.value = true
}

// El desempate por encuestador solo PREselecciona: el usuario decide.
function presetCase() {
  const c = caseQueue.value[caseIdx.value]
  casePick.value = c?.suggestion ? 'team:' + c.suggestion.value : ''
}

async function nextCase() {
  if (caseIdx.value + 1 < caseQueue.value.length) {
    caseIdx.value++
    presetCase()
  } else {
    caseOpen.value = false
    await finish()
  }
}

async function applyCase() {
  const c = curCase.value
  if (!c || !casePick.value) return
  busy.value = true
  error.value = ''
  const change = casePick.value === 'meta'
    ? { uid: c.uid, field: 'meta', value: c.dominant.value }
    : { uid: c.uid, field: 'team', value: casePick.value.slice(5) }
  try {
    await postChanges([change])
  } catch (e) {
    error.value = apiError(e, t('teamConflicts.applyError'))
  }
  busy.value = false
  await nextCase()
}

// Cerrar cualquier modal a media cola = terminar la tanda con lo ya aplicado.
async function abortFlow() {
  summaryOpen.value = false
  groupOpen.value = false
  caseOpen.value = false
  await finish()
}

const VIA_KEY = { enumerator: 'viaEnumerator', first: 'viaFirst', least: 'viaLeast' }
const fmtDate = (s) => (s ? String(s).slice(0, 16) : '—')
</script>

<template>
  <section
    v-if="show"
    class="rounded-xl bg-amber-50 p-5 shadow-sm ring-1 ring-amber-200 dark:bg-amber-950/25 dark:ring-amber-900"
  >
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="font-semibold text-amber-900 dark:text-amber-200">{{ $t('teamConflicts.title') }}</h2>
        <p class="mt-1 text-sm text-amber-800/90 dark:text-amber-300/90">
          {{ $t('teamConflicts.summary', { teams: data.conflict_teams.length, cases: cases.length }) }}
        </p>
      </div>
      <button
        v-if="canApply"
        type="button"
        :disabled="busy"
        class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700 disabled:opacity-60"
        @click="startResolve"
      >
        {{ busy ? progress || $t('teamConflicts.busy') : $t('teamConflicts.resolveBtn', { n: cases.length }) }}
      </button>
    </div>

    <div v-if="error" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900">
      {{ error }}
    </div>
    <div v-if="flash" class="mt-3 rounded-lg bg-success-50 px-3 py-2 text-sm text-success-800 ring-1 ring-success-200 dark:bg-success-900/30 dark:text-success-300 dark:ring-success-800">
      {{ flash }}
    </div>

    <!-- Los conflictos: equipo → valores del meta-equipo (dominante primero) -->
    <ul class="mt-3 space-y-1">
      <li v-for="c in data.conflict_teams" :key="c.team.value" class="text-sm text-amber-900 dark:text-amber-200">
        <span class="font-medium">{{ c.team.label }}</span>:
        <template v-for="(v, i) in c.values" :key="v.value">
          <template v-if="i > 0"> · </template>
          <span :class="i === 0 ? 'font-semibold' : ''">{{ v.label }} ({{ v.count }})</span>
        </template>
      </li>
    </ul>
    <p class="mt-2 text-xs text-amber-700/80 dark:text-amber-400/80">
      {{ $t('teamConflicts.modeLabel') }}: {{ $t('formSettings.conflictMode_' + mode) }}
      <RouterLink
        v-if="auth.isAdmin"
        :to="{ name: 'admin-form-settings', params: { id: formId } }"
        class="font-medium text-primary-600 hover:underline"
      >{{ $t('teamConflicts.changeMode') }}</RouterLink>
    </p>
    <p v-if="data.cases_truncated" class="mt-1 text-xs text-amber-700/80 dark:text-amber-400/80">
      {{ $t('teamConflicts.truncated', { n: data.cases_truncated }) }}
    </p>

    <!-- Modos automáticos: RESUMEN por tanda (la lista de cambios, un solo OK) -->
    <Modal v-if="summaryOpen" :title="$t('teamConflicts.summaryTitle')" @close="abortFlow">
      <div class="space-y-4">
        <p class="text-sm text-slate-600">{{ $t('teamConflicts.summaryIntro', { n: resolved.length }) }}</p>
        <div v-if="resolved.length" class="max-h-72 overflow-y-auto overflow-x-auto rounded-lg ring-1 ring-slate-200 dark:ring-slate-700">
          <table class="w-full whitespace-nowrap text-left text-xs">
            <thead class="bg-slate-50 uppercase tracking-wider text-slate-400 dark:bg-slate-800/40">
              <tr>
                <th class="px-3 py-1.5">{{ $t('teamConflicts.colDate') }}</th>
                <th class="px-3 py-1.5">{{ $t('teamConflicts.colEnumerator') }}</th>
                <th class="px-3 py-1.5">{{ $t('teamConflicts.colTeam') }}</th>
                <th class="px-3 py-1.5">{{ $t('teamConflicts.colNewTeam') }}</th>
                <th class="px-3 py-1.5">{{ $t('teamConflicts.colVia') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
              <tr v-for="c in resolved" :key="c.uid">
                <td class="px-3 py-1.5 text-slate-600">{{ fmtDate(c.submitted_at) }}</td>
                <td class="px-3 py-1.5 text-slate-600">{{ c.enumerator?.label ?? '—' }}</td>
                <td class="px-3 py-1.5 text-slate-600">{{ c.team.label }}</td>
                <td class="px-3 py-1.5 font-semibold text-slate-800 dark:text-slate-200">{{ c.suggestion.label }}</td>
                <td class="px-3 py-1.5 text-slate-500">{{ $t('teamConflicts.' + VIA_KEY[c.suggestion.via]) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="unresolved.length" class="text-xs text-amber-700 dark:text-amber-400">
          {{ $t('teamConflicts.unresolvedNote', { n: unresolved.length }) }}
        </p>
        <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
          <button type="button" class="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100" @click="abortFlow">
            {{ $t('common.cancel') }}
          </button>
          <button
            type="button"
            :disabled="busy || (!resolved.length && !unresolved.length)"
            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-60"
            @click="resolved.length ? applySummary() : (summaryOpen = false, startCaseQueue(unresolved))"
          >
            {{ busy ? progress : resolved.length
              ? $t('teamConflicts.applyBtn', { n: resolved.length })
              : $t('teamConflicts.reviewRestBtn', { n: unresolved.length }) }}
          </button>
        </div>
      </div>
    </Modal>

    <!-- Confirmación GENERAL: un equipo para todos los casos de ese meta-equipo -->
    <Modal v-if="groupOpen && curGroup" :title="$t('teamConflicts.groupTitle', { label: curGroup.label })" @close="abortFlow">
      <div class="space-y-4">
        <p class="text-sm text-slate-600">
          {{ $t('teamConflicts.groupIntro', { n: groupCases.length, label: curGroup.label }) }}
        </p>
        <p v-if="groupQueue.length > 1" class="text-xs text-slate-400">
          {{ $t('teamConflicts.queuePos', { i: groupIdx + 1, n: groupQueue.length }) }}
        </p>
        <fieldset v-if="curGroup.teams.length" class="max-h-60 space-y-2 overflow-y-auto">
          <label
            v-for="tm in curGroup.teams"
            :key="tm.value"
            class="flex items-center gap-3 rounded-lg border border-slate-200 p-3 hover:bg-slate-50"
          >
            <input type="radio" class="h-4 w-4" name="tc_group_team" :value="tm.value" :checked="groupPick === tm.value" @change="groupPick = tm.value" />
            <span class="text-sm text-slate-800">{{ tm.label }}</span>
            <span class="text-xs text-slate-400">{{ $t('teamConflicts.teamVolume', { n: tm.count }) }}</span>
          </label>
        </fieldset>
        <p v-else class="text-sm text-amber-700 dark:text-amber-400">{{ $t('teamConflicts.noCandidates') }}</p>
        <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
          <button type="button" class="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100" :disabled="busy" @click="nextGroup">
            {{ $t('teamConflicts.skipBtn') }}
          </button>
          <button
            v-if="curGroup.teams.length"
            type="button"
            :disabled="busy || !groupPick"
            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-60"
            @click="applyGroup"
          >
            {{ busy ? progress : $t('teamConflicts.groupApplyBtn', { n: groupCases.length }) }}
          </button>
        </div>
      </div>
    </Modal>

    <!-- Confirmación PARTICULAR: modal por caso, con el desempate preseleccionado -->
    <Modal v-if="caseOpen && curCase" :title="$t('teamConflicts.caseTitle', { i: caseIdx + 1, n: caseQueue.length })" @close="abortFlow">
      <div class="space-y-4">
        <dl class="grid grid-cols-2 gap-x-4 gap-y-1 rounded-lg bg-slate-50 p-3 text-sm ring-1 ring-slate-200 dark:bg-slate-800/40 dark:ring-slate-700">
          <dt class="text-slate-400">{{ $t('teamConflicts.colDate') }}</dt>
          <dd class="text-slate-700">{{ fmtDate(curCase.submitted_at) }}</dd>
          <dt class="text-slate-400">{{ $t('teamConflicts.caseTeam') }}</dt>
          <dd class="font-medium text-slate-700">{{ curCase.team.label }}</dd>
          <dt class="text-slate-400">{{ $t('teamConflicts.caseMeta') }}</dt>
          <dd class="font-medium text-slate-700">{{ curCase.meta.label }}</dd>
          <dt class="text-slate-400">{{ $t('teamConflicts.colEnumerator') }}</dt>
          <dd class="text-slate-700">{{ curCase.enumerator?.label ?? '—' }}</dd>
        </dl>
        <p class="text-xs text-slate-500">
          {{ $t('teamConflicts.caseIntro', { meta: curCase.meta.label }) }}
          <RouterLink
            :to="{ name: 'submission-detail', params: { id: formId, subId: curCase.uid }, query: { from: 'quality' } }"
            class="font-medium text-primary-600 hover:underline"
            target="_blank"
          >{{ $t('stats.qualityView') }}</RouterLink>
        </p>
        <fieldset class="max-h-60 space-y-2 overflow-y-auto">
          <label
            v-for="tm in caseTeams"
            :key="tm.value"
            class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border border-slate-200 p-3 hover:bg-slate-50"
          >
            <input type="radio" class="h-4 w-4" name="tc_case_team" :checked="casePick === 'team:' + tm.value" @change="casePick = 'team:' + tm.value" />
            <span class="text-sm text-slate-800">{{ $t('teamConflicts.optTeam', { team: tm.label }) }}</span>
            <span class="text-xs text-slate-400">{{ $t('teamConflicts.teamVolume', { n: tm.count }) }}</span>
            <span
              v-if="curCase.suggestion && curCase.suggestion.value === tm.value && curCase.suggestion.via === 'enumerator'"
              class="rounded-full bg-success-100 px-2 py-0.5 text-xs font-medium text-success-700 dark:bg-success-900/40 dark:text-success-300"
            >{{ $t('teamConflicts.enumMatch') }}</span>
          </label>
          <p v-if="!caseTeams.length" class="text-sm text-amber-700 dark:text-amber-400">{{ $t('teamConflicts.noCandidates') }}</p>
          <!-- Opción manual rara: dar por bueno el EQUIPO y corregir el meta-equipo
               (mal clic en el select). Escribe el meta dominante del equipo tecleado. -->
          <label class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border border-dashed border-slate-300 p-3 hover:bg-slate-50 dark:border-slate-600">
            <input type="radio" class="h-4 w-4" name="tc_case_team" :checked="casePick === 'meta'" @change="casePick = 'meta'" />
            <span class="text-sm text-slate-700">
              {{ $t('teamConflicts.optFixMeta', { label: curCase.dominant.label, team: curCase.team.label }) }}
            </span>
          </label>
        </fieldset>
        <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
          <button type="button" class="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100" :disabled="busy" @click="nextCase">
            {{ $t('teamConflicts.skipBtn') }}
          </button>
          <button
            type="button"
            :disabled="busy || !casePick"
            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-60"
            @click="applyCase"
          >
            {{ busy ? $t('teamConflicts.busy') : $t('teamConflicts.caseApplyBtn') }}
          </button>
        </div>
      </div>
    </Modal>
  </section>
</template>
