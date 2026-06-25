<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, RouterLink } from 'vue-router'
import api from '../services/api'
import { apiError } from '../stores/auth'
import StatsPanels from '../components/StatsPanels.vue'
import Skeleton from '../components/Skeleton.vue'

const { t } = useI18n()
const route = useRoute()
const formId = computed(() => Number(route.params.id))

const stats = ref(null)
const loading = ref(true)
const error = ref('')

// Filtro de estado activo (null = dejar que el backend aplique el alcance por
// defecto configurado). Tras cargar, se sincroniza con el filtro realmente aplicado.
const selectedStatus = ref(null)
// Equipos seleccionados (null = todos; array = subconjunto marcado). Persiste al
// cambiar de estado: ambos filtros se componen.
const selectedTeams = ref(null)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const params = {}
    if (selectedStatus.value) params.status = selectedStatus.value
    if (selectedTeams.value) params.teams = selectedTeams.value.join(',')
    const { data } = await api.get(`/forms/${formId.value}/stats`, { params })
    stats.value = data.data
    selectedStatus.value = data.data.filter // refleja la tarjeta activa
    selectedTeams.value = data.data.team_selection ?? null
  } catch (e) {
    error.value = apiError(e, t('stats.loadError'))
  } finally {
    loading.value = false
  }
}

function selectStatus(status) {
  if (status === selectedStatus.value) return
  selectedStatus.value = status
  load()
}

function selectTeams(keys) {
  selectedTeams.value = keys // null = todos; array = subconjunto (puede ser vacío)
  load()
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
        {{ $t('stats.back') }}
      </RouterLink>
      <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
        {{ $t('stats.title') }}{{ stats ? ' · ' + stats.form.name : '' }}
      </h1>
      <p v-if="stats?.last_submission" class="mt-1 text-sm text-slate-500">
        {{ $t('stats.lastSubmission', { date: stats.last_submission }) }}
      </p>
    </header>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900">
      {{ error }}
    </div>
    <Skeleton v-else-if="loading && !stats" variant="cards" :count="4" />

    <StatsPanels
      v-else-if="stats"
      :stats="stats"
      interactive
      :reloading="loading"
      :selected-teams="selectedTeams"
      @select="selectStatus"
      @select-teams="selectTeams"
    />
  </div>
</template>
