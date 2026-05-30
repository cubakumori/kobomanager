<script setup>
import { ref, onMounted } from 'vue'
import api from '../../services/api'
import { apiError } from '../../stores/auth'

const ALL = [
  { value: 'deployed', label: 'Desplegados', hint: 'Formularios publicados y activos.' },
  { value: 'draft', label: 'Borradores', hint: 'Aún no desplegados.' },
  { value: 'archived', label: 'Archivados', hint: 'Desplegados pero archivados.' },
]

const selected = ref([])
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const saved = ref(false)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/admin/settings')
    selected.value = data.data.sync_deployment_statuses
  } catch (e) {
    error.value = apiError(e, 'No se pudo cargar la configuración')
  } finally {
    loading.value = false
  }
}

function toggle(value) {
  saved.value = false
  const i = selected.value.indexOf(value)
  if (i === -1) selected.value.push(value)
  else selected.value.splice(i, 1)
}

async function save() {
  if (!selected.value.length) {
    error.value = 'Selecciona al menos un estado.'
    return
  }
  saving.value = true
  error.value = ''
  saved.value = false
  try {
    const { data } = await api.put('/admin/settings', { sync_deployment_statuses: selected.value })
    selected.value = data.data.sync_deployment_statuses
    saved.value = true
  } catch (e) {
    error.value = apiError(e, 'No se pudo guardar')
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Configuración</h1>
      <p class="mt-1 text-sm text-slate-500">Ajustes generales, aplicables a todas las cuentas Kobo.</p>
    </header>

    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-4">
      <div>
        <h2 class="font-semibold text-slate-900">Tipos de formulario a sincronizar</h2>
        <p class="mt-0.5 text-sm text-slate-500">
          Al sincronizar una cuenta, solo se traerán los formularios en los estados marcados.
        </p>
      </div>

      <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">
        {{ error }}
      </div>
      <div v-if="loading" class="text-sm text-slate-500">Cargando…</div>

      <template v-else>
        <label
          v-for="opt in ALL"
          :key="opt.value"
          class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 hover:bg-slate-50"
        >
          <input
            type="checkbox"
            class="mt-0.5 h-4 w-4"
            :checked="selected.includes(opt.value)"
            @change="toggle(opt.value)"
          />
          <span>
            <span class="block text-sm font-medium text-slate-800">{{ opt.label }}</span>
            <span class="block text-xs text-slate-400">{{ opt.hint }}</span>
          </span>
        </label>

        <div class="flex items-center gap-3 pt-1">
          <button
            :disabled="saving"
            class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60"
            @click="save"
          >
            {{ saving ? 'Guardando…' : 'Guardar' }}
          </button>
          <span v-if="saved" class="text-sm text-green-600">Guardado ✓</span>
        </div>
        <p class="text-xs text-slate-400">
          Por defecto: solo «Desplegados». El cambio se aplica en la próxima sincronización.
        </p>
      </template>
    </section>
  </div>
</template>
