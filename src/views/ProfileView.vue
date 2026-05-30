<script setup>
import { ref, onMounted } from 'vue'
import api from '../services/api'
import { useAuthStore, apiError } from '../stores/auth'

const auth = useAuthStore()

const forms = ref([]) // [{ form_id, name, account_label, daily_summary }]
const loading = ref(true)
const error = ref('')
const saving = ref(false)
const saved = ref(false)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/notifications')
    forms.value = data.data
  } catch (e) {
    error.value = apiError(e, 'No se pudo cargar la configuración')
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  saved.value = false
  error.value = ''
  try {
    const enabled = forms.value.filter((f) => f.daily_summary).map((f) => f.form_id)
    await api.put('/notifications', { enabled })
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
      <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Mi perfil</h1>
      <p class="mt-1 text-sm text-slate-500">{{ auth.user?.name }} · {{ auth.user?.email }}</p>
    </header>

    <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
      <div class="border-b border-slate-100 px-5 py-3">
        <h2 class="font-semibold text-slate-900">Resumen diario por email</h2>
        <p class="mt-0.5 text-sm text-slate-500">
          Recibe cada mañana un correo con los nuevos envíos de los formularios elegidos.
        </p>
      </div>

      <div v-if="error" class="bg-red-50 px-5 py-2 text-sm text-red-700">{{ error }}</div>
      <div v-if="loading" class="px-5 py-4 text-sm text-slate-500">Cargando…</div>

      <template v-else>
        <ul class="divide-y divide-slate-100">
          <li v-for="f in forms" :key="f.form_id" class="flex items-center justify-between px-5 py-3">
            <div>
              <p class="text-sm font-medium text-slate-900">{{ f.name }}</p>
              <p class="text-xs text-slate-400">{{ f.account_label }}</p>
            </div>
            <input v-model="f.daily_summary" type="checkbox" class="h-4 w-4" @change="saved = false" />
          </li>
          <li v-if="!forms.length" class="px-5 py-6 text-center text-sm text-slate-400">
            No tienes formularios asignados.
          </li>
        </ul>

        <div v-if="forms.length" class="flex items-center gap-3 border-t border-slate-100 px-5 py-4">
          <button
            :disabled="saving"
            class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60"
            @click="save"
          >
            {{ saving ? 'Guardando…' : 'Guardar preferencias' }}
          </button>
          <span v-if="saved" class="text-sm text-green-600">Guardado ✓</span>
        </div>
      </template>
    </section>
  </div>
</template>
