<script setup>
/**
 * Ajustes por formulario: desglose de estadísticas «por equipo → encuestador» y
 * umbrales del control de calidad. Accesible para admins y para usuarios con el
 * permiso «Ajustes» sobre ese formulario (la API responde 403 si no lo tienen).
 */
import { ref, computed, onMounted } from 'vue'
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
const fields = ref([])           // [{ key, label, type, ... }]
const teamField = ref('')        // '' = sin desglose
const enumField = ref('')        // '' = _submitted_by
// Umbrales del control de calidad (minutos; '' = comprobación desactivada).
const qcMinDuration = ref('')
const qcMaxDuration = ref('')
const qcMinGap = ref('')
// Sensibilidad de la señal de duplicados (nº de respuestas de contenido; '' = desactivada).
const qcDupMinAnswers = ref('')

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
    qcMinDuration.value = cfg.data.data.qc_min_duration ?? ''
    qcMaxDuration.value = cfg.data.data.qc_max_duration ?? ''
    qcMinGap.value = cfg.data.data.qc_min_gap ?? ''
    qcDupMinAnswers.value = cfg.data.data.qc_dup_min_answers ?? ''
    fields.value = sf.data.data.fields || []
  } catch (e) {
    error.value = apiError(e, t('formSettings.loadError'))
  } finally {
    loading.value = false
  }
}

// Umbral de la UI ('' | número) → minutos (entero) o null.
const minutes = (v) => (v === '' || v === null ? null : Number(v))

// ---- Sugerencia de umbrales a partir de las duraciones reales (p5/p95) ----
// Solo RELLENA los inputs de duración mín/máx: el usuario revisa y guarda.
const suggesting = ref(false)
const suggestHint = ref('')

async function suggest() {
  suggesting.value = true
  suggestHint.value = ''
  error.value = ''
  try {
    const { data } = await api.get(`/forms/${formId.value}/quality/suggest`)
    const d = data.data
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
      qc_min_duration: minutes(qcMinDuration.value),
      qc_max_duration: minutes(qcMaxDuration.value),
      qc_min_gap: minutes(qcMinGap.value),
      qc_dup_min_answers: minutes(qcDupMinAnswers.value),
    })
    flash.value = t('formSettings.saved')
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
        :to="{ name: auth.isAdmin ? 'admin-forms' : 'forms' }"
        class="text-sm text-primary-600 hover:underline"
      >
        {{ auth.isAdmin ? $t('formSettings.back') : $t('formSettings.backForms') }}
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
            <option v-for="f in fields" :key="f.key" :value="f.key">{{ f.label }}</option>
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
            <option v-for="f in fields" :key="f.key" :value="f.key">{{ f.label }}</option>
          </select>
          <span class="mt-1 block text-xs text-slate-400">{{ $t('formSettings.enumFieldHint') }}</span>
        </label>
      </div>

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

    <div class="flex justify-end">
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
