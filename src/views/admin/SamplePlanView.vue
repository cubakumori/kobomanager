<script setup>
/**
 * Página propia del PLAN DE MUESTRA por equipo (antes, sección de Ajustes): la
 * matriz quiere espacio y foco, y se revisita en campaña con otra cadencia que
 * los umbrales de QC. Accesible para admins y usuarios con el permiso «Muestra»
 * (jerárquico: implica «Ajustes»; la API responde 403 si no lo tienen).
 *
 * Carga lo mismo que Ajustes (nombre + campos del esquema + campo de equipo) y
 * delega todo el trabajo en SamplePlanEditor.
 */
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, RouterLink } from 'vue-router'
import api from '../../services/api'
import { useAuthStore, apiError } from '../../stores/auth'
import Skeleton from '../../components/Skeleton.vue'
import SamplePlanEditor from '../../components/SamplePlanEditor.vue'

const { t } = useI18n()
const route = useRoute()
const auth = useAuthStore()
const formId = computed(() => Number(route.params.id))

const loading = ref(true)
const error = ref('')
const formName = ref('')
const fields = ref([])
const teamField = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    // Variante admin (completa) o de usuario (respeta sus columnas ocultas),
    // como en Ajustes: quien edita el plan no elige campos que no ve.
    const scopeFieldsUrl = auth.isAdmin
      ? `/admin/forms/${formId.value}/scope-fields`
      : `/forms/${formId.value}/scope-fields`
    const [cfg, sf] = await Promise.all([
      api.get(`/admin/forms/${formId.value}`),
      api.get(scopeFieldsUrl),
    ])
    formName.value = cfg.data.data.name
    teamField.value = cfg.data.data.stats_team_field || ''
    fields.value = sf.data.data.fields || []
  } catch (e) {
    error.value = apiError(e, t('formSettings.loadError'))
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header>
      <div class="flex items-center justify-between gap-3">
        <RouterLink
          :to="{ name: 'admin-form-settings', params: { id: formId } }"
          class="text-sm text-primary-600 hover:underline"
        >
          {{ $t('formSettings.backToSettings') }}
        </RouterLink>
        <RouterLink
          :to="{ name: 'sample', params: { id: formId } }"
          class="text-sm font-medium text-primary-600 hover:underline"
        >
          {{ $t('formSettings.samplePanelLink') }}
        </RouterLink>
      </div>
      <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
        {{ $t('formSettings.samplePlanTitle') }}{{ formName ? ' · ' + formName : '' }}
      </h1>
      <p class="mt-1 text-sm text-slate-500">{{ $t('formSettings.samplePlanSubtitle') }}</p>
    </header>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900">
      {{ error }}
    </div>

    <Skeleton v-if="loading" variant="cards" :count="1" />

    <template v-else-if="!error">
      <p v-if="!fields.length" class="rounded-xl bg-white p-6 text-center text-sm text-slate-400 shadow-sm ring-1 ring-slate-200">
        {{ $t('formSettings.noSchema') }}
      </p>
      <SamplePlanEditor v-else :form-id="formId" :fields="fields" :team-field="teamField" />
    </template>
  </div>
</template>
