<script setup>
import { ref, onMounted } from 'vue'
import api from '../../services/api'
import { apiError } from '../../stores/auth'

const accounts = ref([])
const loading = ref(true)
const listError = ref('')

const form = ref({ label: '', server_url: 'https://eu.kobotoolbox.org', email: '', api_token: '' })
const formError = ref('')
const saving = ref(false)

async function load() {
  loading.value = true
  listError.value = ''
  try {
    const { data } = await api.get('/admin/accounts')
    accounts.value = data.data
  } catch (e) {
    listError.value = apiError(e, 'No se pudieron cargar las cuentas')
  } finally {
    loading.value = false
  }
}

async function onCreate() {
  formError.value = ''
  saving.value = true
  try {
    await api.post('/admin/accounts', form.value)
    form.value = { label: '', server_url: 'https://eu.kobotoolbox.org', email: '', api_token: '' }
    await load()
  } catch (e) {
    formError.value = apiError(e, 'No se pudo crear la cuenta')
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Cuentas Kobo</h1>
      <p class="mt-1 text-sm text-slate-500">
        Credenciales del administrador en cada servidor de KoboToolbox. El token se almacena
        cifrado y nunca se muestra.
      </p>
    </header>

    <!-- Alta -->
    <form class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200" @submit.prevent="onCreate">
      <h2 class="mb-4 font-semibold text-slate-900">Nueva cuenta</h2>
      <div
        v-if="formError"
        class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200"
      >
        {{ formError }}
      </div>
      <div class="grid gap-4 sm:grid-cols-2">
        <label class="space-y-1">
          <span class="text-sm font-medium text-slate-700">Etiqueta</span>
          <input v-model="form.label" required placeholder="Cuenta HQ Europa" class="km-input" />
        </label>
        <label class="space-y-1">
          <span class="text-sm font-medium text-slate-700">URL del servidor</span>
          <input v-model="form.server_url" type="url" required class="km-input" />
        </label>
        <label class="space-y-1">
          <span class="text-sm font-medium text-slate-700">Email de la cuenta</span>
          <input v-model="form.email" type="email" required class="km-input" />
        </label>
        <label class="space-y-1">
          <span class="text-sm font-medium text-slate-700">API token</span>
          <input v-model="form.api_token" type="password" required class="km-input" />
        </label>
      </div>
      <button
        type="submit"
        :disabled="saving"
        class="mt-4 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60"
      >
        {{ saving ? 'Guardando…' : 'Añadir cuenta' }}
      </button>
    </form>

    <!-- Listado -->
    <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
      <div v-if="listError" class="p-4 text-sm text-red-700">{{ listError }}</div>
      <div v-else-if="loading" class="p-4 text-sm text-slate-500">Cargando…</div>
      <table v-else class="w-full text-left text-sm">
        <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
          <tr>
            <th class="px-4 py-3">Etiqueta</th>
            <th class="px-4 py-3">Servidor</th>
            <th class="px-4 py-3">Email</th>
            <th class="px-4 py-3">Estado</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="a in accounts" :key="a.id">
            <td class="px-4 py-3 font-medium text-slate-900">{{ a.label }}</td>
            <td class="px-4 py-3 text-slate-600">{{ a.server_url }}</td>
            <td class="px-4 py-3 text-slate-600">{{ a.email }}</td>
            <td class="px-4 py-3">
              <span :class="a.active ? 'text-green-600' : 'text-slate-400'">
                {{ a.active ? 'activa' : 'inactiva' }}
              </span>
            </td>
          </tr>
          <tr v-if="!accounts.length">
            <td colspan="4" class="px-4 py-6 text-center text-slate-400">Sin cuentas todavía.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
@reference "tailwindcss";
.km-input {
  @apply w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/30;
}
</style>
