<script setup>
/**
 * Ajustes por formulario: desglose de estadísticas «por equipo → encuestador» y
 * umbrales del control de calidad. Accesible para admins y para usuarios con el
 * permiso «Ajustes» sobre ese formulario (la API responde 403 si no lo tienen).
 */
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, RouterLink } from 'vue-router'
import api from '../../services/api'
import { useAuthStore, apiError } from '../../stores/auth'
import Skeleton from '../../components/Skeleton.vue'
import { useDemoMode } from '../../composables/appConfig'

const { t, locale } = useI18n()
const route = useRoute()
const auth = useAuthStore()
const { demoMode } = useDemoMode()
const formId = computed(() => Number(route.params.id))

const loading = ref(true)
const saving = ref(false)
const error = ref('')
const flash = ref('')

const formName = ref('')
const fields = ref([])           // [{ key, label, type, multi, ... }]
// Ejes de agrupación (equipo/encuestador): monovaluados. Se excluye select_multiple
// (una fila caería en varios grupos); se sigue admitiendo texto/metadatos monovaluados.
const groupFields = computed(() => fields.value.filter(f => !f.multi))
const teamField = ref('')        // '' = sin desglose
const enumField = ref('')        // '' = _submitted_by
// Meta-equipo: campo que agrupa equipos bajo un nivel padre (región, provincia…).
// Opcional; exige campo de equipo y debe ser distinto de equipo y encuestador.
const groupField = ref('')       // '' = sin agrupación
// Umbrales del control de calidad (minutos; '' = comprobación desactivada).
const qcMinDuration = ref('')
const qcMaxDuration = ref('')
const qcMinGap = ref('')
// Sensibilidad de la señal de duplicados (nº de respuestas de contenido; '' = desactivada).
const qcDupMinAnswers = ref('')
// Índice de riesgo: N mínimo de encuestas por encuestador/equipo ('' = índice desactivado, opt-in).
const riskMinN = ref('')
// Plan de muestra (página propia): permiso jerárquico «Muestra» del usuario, nº de
// objetivos del plan vigente y el campo de equipo GUARDADO (para avisar de que
// cambiarlo desalinea el plan: los objetivos están clavados a los códigos de equipo).
const canSample = ref(false)
const sampleTargetCount = ref(0)
const savedTeamField = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    // El listado de campos: la variante admin (completa) o la de usuario, que
    // respeta sus columnas ocultas (un usuario con «Ajustes» no elige campos que
    // no ve). Ambas devuelven la misma forma { fields: [...] }.
    const scopeFieldsUrl = auth.isAdmin
      ? `/admin/forms/${formId.value}/scope-fields`
      : `/forms/${formId.value}/scope-fields`
    const [cfg, sf] = await Promise.all([
      api.get(`/admin/forms/${formId.value}`),
      api.get(scopeFieldsUrl),
    ])
    formName.value = cfg.data.data.name
    teamField.value = cfg.data.data.stats_team_field || ''
    enumField.value = cfg.data.data.stats_enumerator_field || ''
    groupField.value = cfg.data.data.team_group_field || ''
    qcMinDuration.value = cfg.data.data.qc_min_duration ?? ''
    qcMaxDuration.value = cfg.data.data.qc_max_duration ?? ''
    qcMinGap.value = cfg.data.data.qc_min_gap ?? ''
    qcDupMinAnswers.value = cfg.data.data.qc_dup_min_answers ?? ''
    riskMinN.value = cfg.data.data.risk_min_n ?? ''
    canSample.value = !!cfg.data.data.can_sample
    sampleTargetCount.value = cfg.data.data.sample_target_count ?? 0
    savedTeamField.value = teamField.value
    fields.value = sf.data.data.fields || []
  } catch (e) {
    error.value = apiError(e, t('formSettings.loadError'))
  } finally {
    loading.value = false
  }
}

// Umbral de la UI ('' | número) → minutos (entero) o null.
const minutes = (v) => (v === '' || v === null ? null : Number(v))

// Aviso: cambiar el campo de equipo con un plan de muestra vigente desalinea TODOS
// sus objetivos (quedan clavados a los códigos del campo anterior → «fuera de plan»).
// Se muestra mientras el cambio esté sin guardar; también lo ve un usuario de solo
// «Ajustes», que puede provocar el destrozo sin poder editar el plan.
const teamChangeWarning = computed(() =>
  savedTeamField.value !== '' && teamField.value !== savedTeamField.value && sampleTargetCount.value > 0)

// El SELECT del meta-equipo lo llenan TODOS los candidatos monovaluados (el admin
// sabe la intención; el algoritmo propone/valida, no dicta) — solo se apartan el
// campo de equipo y el de encuestador, que el servidor rechaza (misma cadena).
const groupCandidates = computed(() =>
  groupFields.value.filter(f => f.key !== teamField.value && f.key !== enumField.value))
// Si el usuario mueve equipo/encuestador ENCIMA del meta-equipo elegido, este se
// vacía (el servidor rechazaría el guardado: son eslabones de la misma cadena).
watch([teamField, enumField], () => {
  if (groupField.value && (groupField.value === teamField.value || groupField.value === enumField.value)) {
    groupField.value = ''
  }
})

// ---- Botones del meta-equipo: detección (propone/valida, nunca condiciona el SELECT) ----
// Corren contra la config GUARDADA del campo de equipo (el servidor no ve los cambios
// sin guardar), así que se deshabilitan si no hay campo de equipo guardado.
const detecting = ref('')            // '' | 'suggest' | 'check'
const suggestResult = ref(null)      // respuesta de «Detectar meta-equipos»
const checkResult = ref(null)        // respuesta de «Detectar problemas»
const detectEnabled = computed(() => savedTeamField.value !== '')

async function detectGroups() {
  detecting.value = 'suggest'
  checkResult.value = null
  error.value = ''
  try {
    const { data } = await api.get(`/admin/forms/${formId.value}/team-group`)
    suggestResult.value = data.data
  } catch (e) {
    error.value = apiError(e, t('formSettings.groupDetectError'))
  } finally {
    detecting.value = ''
  }
}

async function checkGroup() {
  if (!groupField.value) return
  detecting.value = 'check'
  suggestResult.value = null
  error.value = ''
  try {
    const { data } = await api.get(`/admin/forms/${formId.value}/team-group`, { params: { field: groupField.value } })
    checkResult.value = data.data
  } catch (e) {
    error.value = apiError(e, t('formSettings.groupDetectError'))
  } finally {
    detecting.value = ''
  }
}

// Un clic en un candidato sugerido lo pone en el SELECT (guardar sigue siendo del usuario).
function useCandidate(key) {
  groupField.value = key
  checkResult.value = null
}

// Botón «Configurar la muestra»: deshabilitado sin permiso «Muestra» o sin campo de
// equipo (el plan se organiza por equipo). El tooltip explica cuál de las dos causas.
const sampleBtnEnabled = computed(() => (auth.isAdmin || canSample.value) && !!teamField.value)
const sampleBtnHint = computed(() => {
  if (!auth.isAdmin && !canSample.value) return t('formSettings.sampleBtnNoPerm')
  if (!teamField.value) return t('formSettings.sampleBtnNeedsTeam')
  return ''
})

// ---- Sugerencia de umbrales a partir de las duraciones reales (p5/p95) ----
// Solo RELLENA los inputs de duración mín/máx: el usuario revisa y guarda.
const suggesting = ref(false)
const suggestHint = ref('')
// Pista para el «Índice de riesgo»: mediana de encuestas por encuestador (la calcula
// el mismo endpoint de sugerencia). No rellena nada; solo informa el mínimo a fijar.
const riskHint = ref('')

async function suggest() {
  suggesting.value = true
  suggestHint.value = ''
  riskHint.value = ''
  error.value = ''
  try {
    const { data } = await api.get(`/forms/${formId.value}/quality/suggest`)
    const d = data.data
    if (d.enumerator_median != null) {
      riskHint.value = t('formSettings.riskSuggestHint', { n: d.enumerator_median, e: d.enumerators })
    }
    if (!d.suggested) {
      suggestHint.value = t('formSettings.qcSuggestNotEnough', { n: d.min_needed })
      return
    }
    qcMinDuration.value = d.suggested.min_duration
    qcMaxDuration.value = d.suggested.max_duration
    // Segundos → minutos con 1 decimal, en el formato del idioma.
    const min1 = (s) => (s / 60).toLocaleString(locale.value, { maximumFractionDigits: 1 })
    suggestHint.value = t('formSettings.qcSuggestHint', {
      p5: min1(d.p5_s),
      p95: min1(d.p95_s),
      n: d.count,
    })
  } catch (e) {
    error.value = apiError(e, t('formSettings.qcSuggestError'))
  } finally {
    suggesting.value = false
  }
}

async function save() {
  saving.value = true
  error.value = ''
  flash.value = ''
  try {
    await api.patch(`/admin/forms/${formId.value}`, {
      stats_team_field: teamField.value || null,
      stats_enumerator_field: enumField.value || null,
      team_group_field: (teamField.value && groupField.value) || null,
      qc_min_duration: minutes(qcMinDuration.value),
      qc_max_duration: minutes(qcMaxDuration.value),
      qc_min_gap: minutes(qcMinGap.value),
      qc_dup_min_answers: minutes(qcDupMinAnswers.value),
      risk_min_n: minutes(riskMinN.value),
    })
    flash.value = t('formSettings.saved')
    savedTeamField.value = teamField.value
  } catch (e) {
    error.value = apiError(e, t('formSettings.saveError'))
  } finally {
    saving.value = false
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
        {{ $t('formSettings.backToForm') }}
      </RouterLink>
      <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
        {{ $t('formSettings.title') }}{{ formName ? ' · ' + formName : '' }}
      </h1>
      <p class="mt-1 text-sm text-slate-500">{{ $t('formSettings.subtitle') }}</p>
    </header>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900">
      {{ error }}
    </div>
    <div v-if="flash" class="rounded-lg bg-success-50 px-3 py-2 text-sm text-success-800 ring-1 ring-success-200 dark:bg-success-900/30 dark:text-success-300 dark:ring-success-800">
      {{ flash }}
    </div>

    <Skeleton v-if="loading" variant="cards" :count="2" />

    <template v-else>
    <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <h2 class="font-semibold text-slate-900">{{ $t('formSettings.teamSection') }}</h2>
      <p class="mt-1 text-sm text-slate-500">{{ $t('formSettings.teamSectionDesc') }}</p>

      <p v-if="!fields.length" class="mt-4 text-sm text-slate-400">{{ $t('formSettings.noSchema') }}</p>

      <div v-else class="mt-4 grid gap-4 sm:grid-cols-2">
        <label class="block">
          <span class="text-sm font-medium text-slate-700">{{ $t('formSettings.teamField') }}</span>
          <select
            v-model="teamField"
            class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
          >
            <option value="">{{ $t('formSettings.teamNone') }}</option>
            <option v-for="f in groupFields" :key="f.key" :value="f.key">{{ f.label }}</option>
          </select>
          <span class="mt-1 block text-xs text-slate-400">{{ $t('formSettings.teamFieldHint') }}</span>
        </label>

        <label class="block">
          <span class="text-sm font-medium text-slate-700">{{ $t('formSettings.enumField') }}</span>
          <select
            v-model="enumField"
            :disabled="!teamField"
            class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30 disabled:bg-slate-100 disabled:text-slate-400"
          >
            <option value="">{{ $t('formSettings.enumDefault') }}</option>
            <option v-for="f in groupFields" :key="f.key" :value="f.key">{{ f.label }}</option>
          </select>
          <span class="mt-1 block text-xs text-slate-400">{{ $t('formSettings.enumFieldHint') }}</span>
        </label>
      </div>

      <!-- Cambiar el campo de equipo con un plan vigente desalinea sus objetivos -->
      <div v-if="teamChangeWarning" class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900">
        {{ $t('formSettings.teamChangeSampleWarning', { n: sampleTargetCount }) }}
      </div>

      <!-- Meta-equipo: agrupación de equipos bajo un nivel padre (roll-up de presentación) -->
      <div v-if="fields.length" class="mt-6 border-t border-slate-100 pt-4">
        <h3 class="text-sm font-semibold text-slate-900">{{ $t('formSettings.groupSection') }}</h3>
        <p class="mt-1 text-sm text-slate-500">{{ $t('formSettings.groupSectionDesc') }}</p>

        <div class="mt-3 grid items-start gap-4 sm:grid-cols-2">
          <label class="block">
            <span class="text-sm font-medium text-slate-700">{{ $t('formSettings.groupField') }}</span>
            <select
              v-model="groupField"
              :disabled="!teamField"
              class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30 disabled:bg-slate-100 disabled:text-slate-400"
            >
              <option value="">{{ $t('formSettings.groupNone') }}</option>
              <option v-for="f in groupCandidates" :key="f.key" :value="f.key">{{ f.label }}</option>
            </select>
            <span class="mt-1 block text-xs text-slate-400">
              {{ teamField ? $t('formSettings.groupFieldHint') : $t('formSettings.groupNeedsTeam') }}
            </span>
          </label>

          <!-- El algoritmo propone/valida, no dicta: nunca condiciona el SELECT -->
          <div class="flex flex-wrap gap-2 sm:mt-6">
            <button
              type="button"
              :disabled="!detectEnabled || !!detecting"
              :title="!detectEnabled ? $t('formSettings.groupDetectNeedsSaved') : undefined"
              class="rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm ring-1 ring-slate-300 hover:bg-slate-50 disabled:opacity-60"
              @click="detectGroups"
            >
              {{ detecting === 'suggest' ? $t('formSettings.groupDetectBusy') : $t('formSettings.groupDetectBtn') }}
            </button>
            <button
              type="button"
              :disabled="!detectEnabled || !groupField || !!detecting"
              :title="!groupField ? $t('formSettings.groupCheckNeedsField') : undefined"
              class="rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm ring-1 ring-slate-300 hover:bg-slate-50 disabled:opacity-60"
              @click="checkGroup"
            >
              {{ detecting === 'check' ? $t('formSettings.groupDetectBusy') : $t('formSettings.groupCheckBtn') }}
            </button>
          </div>
        </div>

        <!-- Resultado de «Detectar meta-equipos»: candidatos rankeados, clic = elegir -->
        <div v-if="suggestResult" class="mt-3 rounded-lg bg-slate-50 p-3 text-sm ring-1 ring-slate-200 dark:bg-slate-800/40 dark:ring-slate-700">
          <p v-if="suggestResult.insufficient" class="text-slate-500">{{ $t('formSettings.groupInsufficient') }}</p>
          <template v-else>
            <p class="text-xs text-slate-500">{{ $t('formSettings.groupSuggestIntro', { teams: suggestResult.teams, rows: suggestResult.rows }) }}</p>
            <ul class="mt-2 space-y-1">
              <li v-for="c in suggestResult.candidates.slice(0, 5)" :key="c.field" class="flex flex-wrap items-baseline gap-x-2">
                <button type="button" class="font-medium text-primary-600 hover:underline" @click="useCandidate(c.field)">
                  {{ c.label }}
                </button>
                <span class="text-xs text-slate-500">
                  {{ $t('formSettings.groupSuggestMetrics', { pct: Math.round(c.consistency_pct), values: c.distinct_values, teams: c.teams_covered }) }}
                </span>
                <span v-if="!c.coarser" class="text-xs text-amber-600">{{ $t('formSettings.groupSuggestNotCoarser') }}</span>
              </li>
            </ul>
            <p class="mt-2 text-xs text-slate-400">{{ $t('formSettings.groupSuggestHint') }}</p>
          </template>
        </div>

        <!-- Resultado de «Detectar problemas»: conflictos por equipo (aviso de calidad de dato) -->
        <div v-if="checkResult" class="mt-3 rounded-lg bg-slate-50 p-3 text-sm ring-1 ring-slate-200 dark:bg-slate-800/40 dark:ring-slate-700">
          <p v-if="checkResult.insufficient" class="text-slate-500">{{ $t('formSettings.groupInsufficient') }}</p>
          <template v-else>
            <p v-if="!checkResult.conflicts.length" class="font-medium text-success-700 dark:text-success-400">
              {{ $t('formSettings.groupCheckClean', { teams: checkResult.teams }) }}
            </p>
            <template v-else>
              <p class="text-xs text-slate-500">{{ $t('formSettings.groupCheckConflictsIntro', { n: checkResult.conflicts.length }) }}</p>
              <ul class="mt-2 space-y-1">
                <li v-for="c in checkResult.conflicts" :key="c.team" class="text-xs text-slate-600">
                  <span class="font-medium text-slate-800 dark:text-slate-200">{{ c.name }}</span>:
                  <span v-for="(v, i) in c.values" :key="v.value">
                    <template v-if="i > 0"> · </template>{{ v.label }} ({{ v.count }})
                  </span>
                </li>
              </ul>
            </template>
            <p v-if="checkResult.unassigned.length" class="mt-2 text-xs text-amber-600">
              {{ $t('formSettings.groupCheckUnassigned', { n: checkResult.unassigned.length, teams: checkResult.unassigned.map(u => u.name).join(', ') }) }}
            </p>
          </template>
        </div>
      </div>

    </section>

    <!-- Plan de muestra por equipo: página propia (permiso jerárquico «Muestra») -->
    <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('formSettings.sampleSection') }}</h2>
          <p class="mt-1 text-sm text-slate-500">{{ $t('formSettings.sampleSectionDesc') }}</p>
        </div>
        <div :title="sampleBtnHint || undefined">
          <RouterLink
            v-if="sampleBtnEnabled"
            :to="{ name: 'admin-form-sample-plan', params: { id: formId } }"
            class="inline-block rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700"
          >
            {{ $t('formSettings.sampleConfigureBtn') }}
          </RouterLink>
          <button
            v-else
            type="button"
            disabled
            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white opacity-50"
          >
            {{ $t('formSettings.sampleConfigureBtn') }}
          </button>
        </div>
      </div>
      <p v-if="!sampleBtnEnabled && sampleBtnHint" class="mt-2 text-xs text-amber-600 dark:text-amber-400">{{ sampleBtnHint }}</p>
    </section>

    <!-- Umbrales del control de calidad (página «Control de calidad» del formulario) -->
    <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('formSettings.qcSection') }}</h2>
          <p class="mt-1 text-sm text-slate-500">{{ $t('formSettings.qcSectionDesc') }}</p>
        </div>
        <!-- Propone mín/máx a partir de los p5/p95 de las duraciones reales; solo
             rellena los inputs (guardar sigue siendo decisión del usuario). -->
        <button
          type="button"
          :disabled="suggesting"
          class="rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm ring-1 ring-slate-300 hover:bg-slate-50 disabled:opacity-60"
          @click="suggest"
        >
          {{ suggesting ? $t('formSettings.qcSuggestBusy') : $t('formSettings.qcSuggest') }}
        </button>
      </div>
      <p v-if="suggestHint" class="mt-2 text-xs text-slate-500">{{ suggestHint }}</p>

      <div class="mt-4 grid gap-4 sm:grid-cols-3">
        <label class="block">
          <span class="text-sm font-medium text-slate-700">{{ $t('formSettings.qcMinDuration') }}</span>
          <input
            v-model="qcMinDuration"
            type="number"
            min="0"
            max="10080"
            class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
          />
          <span class="mt-1 block text-xs text-slate-400">{{ $t('formSettings.qcEmptyHint') }}</span>
        </label>

        <label class="block">
          <span class="text-sm font-medium text-slate-700">{{ $t('formSettings.qcMaxDuration') }}</span>
          <input
            v-model="qcMaxDuration"
            type="number"
            min="0"
            max="10080"
            class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
          />
          <span class="mt-1 block text-xs text-slate-400">{{ $t('formSettings.qcMaxDurationHint') }}</span>
        </label>

        <label class="block">
          <span class="text-sm font-medium text-slate-700">{{ $t('formSettings.qcMinGap') }}</span>
          <input
            v-model="qcMinGap"
            type="number"
            min="0"
            max="10080"
            class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
          />
          <span class="mt-1 block text-xs text-slate-400">{{ $t('formSettings.qcMinGapHint') }}</span>
        </label>

        <label class="block">
          <span class="text-sm font-medium text-slate-700">{{ $t('formSettings.qcDupMinAnswers') }}</span>
          <input
            v-model="qcDupMinAnswers"
            type="number"
            min="0"
            max="50"
            class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
          />
          <span class="mt-1 block text-xs text-slate-400">{{ $t('formSettings.qcDupMinAnswersHint') }}</span>
        </label>
      </div>
    </section>

    <!-- Índice de riesgo (opt-in): N mínimo por encuestador/equipo para puntuar -->
    <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <h2 class="font-semibold text-slate-900">{{ $t('formSettings.riskSection') }}</h2>
      <p class="mt-1 text-sm text-slate-500">{{ $t('formSettings.riskSectionDesc') }}</p>
      <div class="mt-4 max-w-xs">
        <label class="block">
          <span class="text-sm font-medium text-slate-700">{{ $t('formSettings.riskMinN') }}</span>
          <input
            v-model="riskMinN"
            type="number"
            min="0"
            max="100000"
            class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
          />
          <span class="mt-1 block text-xs text-slate-400">{{ $t('formSettings.riskMinNHint') }}</span>
        </label>
        <p v-if="riskHint" class="mt-2 text-xs text-slate-500">{{ riskHint }}</p>
      </div>
    </section>

    <div class="flex items-center justify-end gap-3">
      <!-- Confirmación junto al botón: el aviso de arriba puede quedar fuera de vista
           al guardar desde el final de la página. -->
      <span v-if="flash" class="text-sm font-medium text-success-700 dark:text-success-400">✓ {{ flash }}</span>
      <button
        :disabled="demoMode || saving"
        class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-60"
        :title="demoMode ? $t('common.demoDisabled') : undefined"
        @click="save"
      >
        {{ saving ? $t('formSettings.saving') : $t('formSettings.save') }}
      </button>
    </div>
    </template>
  </div>
</template>
