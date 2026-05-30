<script setup>
import { ref, onMounted } from 'vue'
import api from '../../services/api'
import { apiError } from '../../stores/auth'

const forms = ref([])
const loading = ref(true)
const listError = ref('')

const syncing = ref(false)
const syncResult = ref(null) // resumen por cuenta tras sincronizar
const syncError = ref('')

async function load() {
  loading.value = true
  listError.value = ''
  try {
    const { data } = await api.get('/admin/forms')
    forms.value = data.data
  } catch (e) {
    listError.value = apiError(e, 'No se pudieron cargar los formularios')
  } finally {
    loading.value = false
  }
}

async function onSync() {
  syncing.value = true
  syncError.value = ''
  syncResult.value = null
  try {
    const { data } = await api.post('/admin/forms/sync', {})
    syncResult.value = data.data
    await load()
  } catch (e) {
    syncError.value = apiError(e, 'No se pudo sincronizar')
  } finally {
    syncing.value = false
  }
}

const badge = {
  success: 'bg-green-100 text-green-700',
  error: 'bg-red-100 text-red-700',
  pending: 'bg-amber-100 text-amber-700',
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Formularios</h1>
        <p class="mt-1 text-sm text-slate-500">
          Sincroniza los formularios desde las cuentas de KoboToolbox.
        </p>
      </div>
      <button
        :disabled="syncing"
        class="shrink-0 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60"
        @click="onSync"
      >
        {{ syncing ? 'Sincronizando…' : 'Sincronizar ahora' }}
      </button>
    </header>

    <!-- Resultado del sync -->
    <div v-if="syncError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">
      {{ syncError }}
    </div>
    <div v-if="syncResult" class="space-y-2">
      <div
        v-for="r in syncResult"
        :key="r.account_id"
        class="rounded-lg px-3 py-2 text-sm ring-1"
        :class="r.status === 'success'
          ? 'bg-green-50 text-green-800 ring-green-200'
          : 'bg-red-50 text-red-800 ring-red-200'"
      >
        <span class="font-medium">{{ r.account_label }}:</span>
        <template v-if="r.status === 'success'">{{ r.forms }} formulario(s) sincronizado(s).</template>
        <template v-else>error — {{ r.error }} ({{ r.error_code }})</template>
      </div>
    </div>

    <!-- Listado -->
    <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
      <div v-if="listError" class="p-4 text-sm text-red-700">{{ listError }}</div>
      <div v-else-if="loading" class="p-4 text-sm text-slate-500">Cargando…</div>
      <table v-else class="w-full text-left text-sm">
        <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
          <tr>
            <th class="px-4 py-3">Formulario</th>
            <th class="px-4 py-3">Cuenta</th>
            <th class="px-4 py-3">Estado</th>
            <th class="px-4 py-3">Última sync</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="f in forms" :key="f.id">
            <td class="px-4 py-3 font-medium text-slate-900">{{ f.name }}</td>
            <td class="px-4 py-3 text-slate-600">{{ f.account_label }}</td>
            <td class="px-4 py-3">
              <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="badge[f.sync_status]">
                {{ f.sync_status }}
              </span>
              <p v-if="f.sync_status === 'error' && f.last_sync_error" class="mt-1 text-xs text-red-600">
                {{ f.last_sync_error }}
              </p>
            </td>
            <td class="px-4 py-3 text-slate-500">{{ f.last_synced_at ?? '—' }}</td>
          </tr>
          <tr v-if="!forms.length">
            <td colspan="4" class="px-4 py-6 text-center text-slate-400">
              Sin formularios. Pulsa «Sincronizar ahora».
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
