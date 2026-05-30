<script setup>
import { ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import api from '../services/api'
import { apiError } from '../stores/auth'

const forms = ref([])
const loading = ref(true)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/forms')
    forms.value = data.data
  } catch (e) {
    error.value = apiError(e, 'No se pudieron cargar los formularios')
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Mis formularios</h1>
      <p class="mt-1 text-sm text-slate-500">Formularios a los que tienes acceso.</p>
    </header>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">
      {{ error }}
    </div>
    <div v-else-if="loading" class="text-sm text-slate-500">Cargando…</div>

    <div v-else-if="!forms.length" class="rounded-xl bg-white p-6 text-sm text-slate-400 shadow-sm ring-1 ring-slate-200">
      No tienes formularios asignados todavía.
    </div>

    <div v-else class="grid gap-4 sm:grid-cols-2">
      <RouterLink
        v-for="f in forms"
        :key="f.id"
        :to="{ name: 'submissions', params: { id: f.id } }"
        class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 transition hover:ring-blue-300"
      >
        <p class="text-xs uppercase tracking-wider text-slate-400">{{ f.account_label }}</p>
        <h2 class="mt-1 font-semibold text-slate-900">{{ f.name }}</h2>
        <p class="mt-2 text-sm text-slate-500">
          {{ f.submission_count }} envío{{ f.submission_count === 1 ? '' : 's' }}
        </p>
      </RouterLink>
    </div>
  </div>
</template>
