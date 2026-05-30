<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '../../services/api'
import { apiError } from '../../stores/auth'

const forms = ref([])
const loading = ref(true)
const listError = ref('')

const syncing = ref(false)
const syncResult = ref(null) // resumen por cuenta tras sincronizar
const syncError = ref('')

const updatingId = ref(null) // formulario que está actualizando sus envíos
const flash = ref('')        // mensaje breve de resultado de "Actualizar"

const selectedAccount = ref('') // '' = todas

// Cuentas únicas presentes en los formularios, para el filtro.
const accounts = computed(() => {
  const map = new Map()
  for (const f of forms.value) map.set(f.account_id, f.account_label)
  return [...map].map(([id, label]) => ({ id, label }))
})

const filteredForms = computed(() =>
  selectedAccount.value === ''
    ? forms.value
    : forms.value.filter((f) => f.account_id === Number(selectedAccount.value)),
)

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
    // Si hay una cuenta seleccionada, sincroniza solo esa.
    const body = selectedAccount.value ? { account_id: Number(selectedAccount.value) } : {}
    const { data } = await api.post('/admin/forms/sync', body)
    syncResult.value = data.data
    await load()
  } catch (e) {
    syncError.value = apiError(e, 'No se pudo sincronizar')
  } finally {
    syncing.value = false
  }
}

async function removeForm(f) {
  if (!confirm(`¿Eliminar «${f.name}» de KoboManager y su caché de envíos? No se borra nada en KoboToolbox; si sigue cumpliendo el filtro, volverá al sincronizar.`)) return
  flash.value = ''
  syncError.value = ''
  try {
    await api.delete(`/admin/forms/${f.id}`)
    flash.value = `«${f.name}» eliminado.`
    await load()
  } catch (e) {
    syncError.value = `«${f.name}»: ${apiError(e, 'no se pudo eliminar')}`
  }
}

async function onUpdateForm(f) {
  updatingId.value = f.id
  flash.value = ''
  syncError.value = ''
  try {
    const { data } = await api.post(`/admin/forms/${f.id}/sync`)
    flash.value = `«${f.name}»: ${data.data.submissions} envío(s) actualizados.`
    await load()
  } catch (e) {
    syncError.value = `«${f.name}»: ${apiError(e, 'no se pudo actualizar')}`
  } finally {
    updatingId.value = null
  }
}

const badge = {
  success: 'bg-green-100 text-green-700',
  error: 'bg-red-100 text-red-700',
  pending: 'bg-amber-100 text-amber-700',
}
const statusBadge = {
  deployed: 'bg-green-100 text-green-700',
  draft: 'bg-amber-100 text-amber-700',
  archived: 'bg-slate-200 text-slate-600',
}
const statusLabel = { deployed: 'desplegado', draft: 'borrador', archived: 'archivado' }

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
        {{ syncing ? 'Sincronizando…' : (selectedAccount ? 'Sincronizar esta cuenta' : 'Sincronizar todas') }}
      </button>
    </header>

    <!-- Filtro por cuenta -->
    <div class="flex items-center gap-2">
      <label class="text-sm text-slate-600">Cuenta:</label>
      <select
        v-model="selectedAccount"
        class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/30"
      >
        <option value="">Todas las cuentas</option>
        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.label }}</option>
      </select>
    </div>

    <!-- Resultado del sync / flash -->
    <div v-if="syncError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">
      {{ syncError }}
    </div>
    <div v-if="flash" class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800 ring-1 ring-green-200">
      {{ flash }}
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
        <template v-if="r.status === 'success'">
          {{ r.forms }} formulario(s) sincronizado(s)<template v-if="r.skipped"> · {{ r.skipped }} omitido(s) por estado</template>.
        </template>
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
            <th class="px-4 py-3">Tipo</th>
            <th class="px-4 py-3">Sync</th>
            <th class="px-4 py-3">Última sync</th>
            <th class="px-4 py-3 text-right">Acciones</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="f in filteredForms" :key="f.id">
            <td class="px-4 py-3 font-medium text-slate-900">{{ f.name }}</td>
            <td class="px-4 py-3 text-slate-600">{{ f.account_label }}</td>
            <td class="px-4 py-3">
              <span
                v-if="f.deployment_status"
                class="rounded-full px-2 py-0.5 text-xs font-medium"
                :class="statusBadge[f.deployment_status] || 'bg-slate-100 text-slate-600'"
              >{{ statusLabel[f.deployment_status] || f.deployment_status }}</span>
              <span v-else class="text-xs text-slate-300">—</span>
            </td>
            <td class="px-4 py-3">
              <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="badge[f.sync_status]">
                {{ f.sync_status }}
              </span>
              <p v-if="f.sync_status === 'error' && f.last_sync_error" class="mt-1 text-xs text-red-600">
                {{ f.last_sync_error }}
              </p>
            </td>
            <td class="px-4 py-3 text-slate-500">{{ f.last_synced_at ?? '—' }}</td>
            <td class="px-4 py-3">
              <div class="flex items-center justify-end gap-3">
                <button
                  :disabled="updatingId === f.id"
                  class="font-medium text-blue-600 hover:underline disabled:opacity-50"
                  title="Traer los envíos nuevos de este formulario"
                  @click="onUpdateForm(f)"
                >
                  {{ updatingId === f.id ? 'Actualizando…' : 'Actualizar' }}
                </button>
                <button
                  class="font-medium text-red-600 hover:underline"
                  title="Eliminar este formulario y su caché de KoboManager"
                  @click="removeForm(f)"
                >
                  Eliminar
                </button>
              </div>
            </td>
          </tr>
          <tr v-if="!filteredForms.length">
            <td colspan="6" class="px-4 py-6 text-center text-slate-400">
              Sin formularios. Pulsa «Sincronizar».
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
