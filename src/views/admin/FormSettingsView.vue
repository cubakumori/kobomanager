<script setup>
/**
 * Ajustes por formulario (solo admin). Hoy alberga la configuración del desglose
 * de estadísticas «por equipo → encuestador»: se eligen dos campos del esquema.
 * Pensada para crecer con futuros ajustes por formulario.
 */
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, RouterLink } from 'vue-router'
import api from '../../services/api'
import { apiError } from '../../stores/auth'
import Skeleton from '../../components/Skeleton.vue'
import { useDemoMode } from '../../composables/appConfig'

const { t } = useI18n()
const route = useRoute()
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

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [cfg, sf] = await Promise.all([
      api.get(`/admin/forms/${formId.value}`),
      api.get(`/admin/forms/${formId.value}/scope-fields`),
    ])
    formName.value = cfg.data.data.name
    teamField.value = cfg.data.data.stats_team_field || ''
    enumField.value = cfg.data.data.stats_enumerator_field || ''
    fields.value = sf.data.data.fields || []
  } catch (e) {
    error.value = apiError(e, t('formSettings.loadError'))
  } finally {
    loading.value = false
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
      <RouterLink :to="{ name: 'admin-forms' }" class="text-sm text-primary-600 hover:underline">
        {{ $t('formSettings.back') }}
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

    <Skeleton v-if="loading" variant="cards" :count="1" />

    <section v-else class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
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

      <div class="mt-5 flex justify-end">
        <button
          :disabled="demoMode || saving || !fields.length"
          class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-60"
          :title="demoMode ? $t('common.demoDisabled') : undefined"
          @click="save"
        >
          {{ saving ? $t('formSettings.saving') : $t('formSettings.save') }}
        </button>
      </div>
    </section>
  </div>
</template>
