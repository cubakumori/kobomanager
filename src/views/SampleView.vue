<script setup>
/**
 * Panel de cumplimiento de la MUESTRA por equipo (vista interna): hecho/objetivo
 * por celda `equipo × valor`, totales y proyección por equipo. Solo lectura;
 * respeta el scoping por filas del usuario (un jefe de equipo ve el suyo). El
 * plan se edita en los ajustes del formulario (SamplePlanEditor).
 *
 * El PANEL en sí (selector de vistas, agrupado por meta-equipo, total general,
 * los seis modos y la distribución observada) vive en el componente compartido
 * `SamplePanel.vue` — la vista pública de los enlaces de solo lectura pinta el
 * mismo panel. Aquí quedan la carga, los permisos, los avisos de configuración
 * y el toggle transitorio del denominador (herramienta del revisor interno).
 */
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, RouterLink } from 'vue-router'
import api from '../services/api'
import { apiError, useAuthStore } from '../stores/auth'
import Skeleton from '../components/Skeleton.vue'
import SamplePanel from '../components/SamplePanel.vue'

const { t } = useI18n()
const route = useRoute()
const auth = useAuthStore()
const formId = computed(() => Number(route.params.id))

const data = ref(null)
const loading = ref(true)
const error = ref('')

// Denominador transitorio (null = el del plan): «¿y si contaran también los
// pendientes?» sin tocar el ajuste — mismo patrón que el `?scope=` del control de
// calidad. El backend lo aplica solo a esa petición y devuelve el EFECTIVO.
const denominatorOverride = ref(null)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const params = denominatorOverride.value ? { denominator: denominatorOverride.value } : {}
    const res = await api.get(`/forms/${formId.value}/sample`, { params })
    data.value = res.data.data
  } catch (e) {
    error.value = apiError(e, t('sample.loadError'))
  } finally {
    loading.value = false
  }
}

// Alterna al denominador contrario del EFECTIVO actual y recarga (todo reacciona:
// celdas, proyecciones, semáforo, resumen y el agrupado por meta-equipo).
function toggleDenominator() {
  if (loading.value || !data.value) return
  denominatorOverride.value = data.value.denominator === 'approved' ? 'approved_pending' : 'approved'
  load()
}

// Configurar el plan pide el permiso jerárquico «Muestra» (no basta «Ajustes»).
const canSample = computed(() => {
  const f = data.value
  return auth.isAdmin || !!(f && f.can_sample)
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
          v-if="canSample"
          :to="{ name: 'admin-form-sample-plan', params: { id: formId } }"
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

        <!-- Retención activa: el panel solo cuenta la ventana retenida en caché -->
        <div v-if="data.retention_days" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900">
          {{ $t('sample.retentionNote', { n: data.retention_days }) }}
        </div>

        <SamplePanel :data="data" :loading="loading" @toggle-denominator="toggleDenominator" />
      </template>
    </template>
  </div>
</template>
