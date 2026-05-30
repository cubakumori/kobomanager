<script setup>
import { ref, onMounted } from 'vue'
import api from '../../services/api'
import { apiError } from '../../stores/auth'
import Modal from '../../components/Modal.vue'

const accounts = ref([])
const loading = ref(true)
const listError = ref('')

const form = ref({ label: '', server_url: 'https://eu.kobotoolbox.org', email: '', api_token: '' })
const formError = ref('')
const saving = ref(false)

// Edición
const editing = ref(null) // cuenta en edición (o null)
const editForm = ref({ label: '', server_url: '', email: '', api_token: '' })
const editError = ref('')
const savingEdit = ref(false)

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

function startEdit(a) {
  editError.value = ''
  editing.value = a
  // El token nunca se muestra; se deja vacío y solo se envía si el admin escribe uno nuevo.
  editForm.value = { label: a.label, server_url: a.server_url, email: a.email, api_token: '' }
}

async function saveEdit() {
  savingEdit.value = true
  editError.value = ''
  try {
    const payload = { ...editForm.value }
    if (!payload.api_token) delete payload.api_token
    await api.put(`/admin/accounts/${editing.value.id}`, payload)
    editing.value = null
    await load()
  } catch (e) {
    editError.value = apiError(e, 'No se pudo guardar')
  } finally {
    savingEdit.value = false
  }
}

async function removeAccount(a) {
  if (!confirm(`¿Eliminar la cuenta "${a.label}"? Esta acción no se puede deshacer.`)) return
  try {
    await api.delete(`/admin/accounts/${a.id}`)
    await load()
  } catch (e) {
    alert(apiError(e, 'No se pudo eliminar'))
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
            <th class="px-4 py-3 text-right">Acciones</th>
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
            <td class="px-4 py-3">
              <div class="flex items-center justify-end gap-3">
                <button class="font-medium text-blue-600 hover:underline" @click="startEdit(a)">
                  Editar
                </button>
                <button
                  v-if="a.forms_count === 0"
                  class="font-medium text-red-600 hover:underline"
                  @click="removeAccount(a)"
                >
                  Eliminar
                </button>
                <span
                  v-else
                  class="cursor-help text-slate-300"
                  :title="`No se puede eliminar: tiene ${a.forms_count} formulario(s) sincronizado(s)`"
                >
                  Eliminar
                </span>
              </div>
            </td>
          </tr>
          <tr v-if="!accounts.length">
            <td colspan="5" class="px-4 py-6 text-center text-slate-400">Sin cuentas todavía.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Modal de edición -->
    <Modal v-if="editing" :title="`Editar: ${editing.label}`" @close="editing = null">
      <form class="space-y-4" @submit.prevent="saveEdit">
        <div v-if="editError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
          {{ editError }}
        </div>
        <label class="block space-y-1">
          <span class="text-sm font-medium text-slate-700">Etiqueta</span>
          <input v-model="editForm.label" required class="km-input" />
        </label>
        <label class="block space-y-1">
          <span class="text-sm font-medium text-slate-700">URL del servidor</span>
          <input v-model="editForm.server_url" type="url" required class="km-input" />
        </label>
        <label class="block space-y-1">
          <span class="text-sm font-medium text-slate-700">Email de la cuenta</span>
          <input v-model="editForm.email" type="email" required class="km-input" />
        </label>
        <label class="block space-y-1">
          <span class="text-sm font-medium text-slate-700">API token</span>
          <input
            v-model="editForm.api_token"
            type="password"
            placeholder="Dejar vacío para no cambiarlo"
            class="km-input"
          />
          <span class="text-xs text-slate-400">Solo escríbelo si quieres reemplazar el token actual.</span>
        </label>
        <div class="flex items-center gap-3 pt-1">
          <button
            type="submit"
            :disabled="savingEdit"
            class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60"
          >
            {{ savingEdit ? 'Guardando…' : 'Guardar' }}
          </button>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-700" @click="editing = null">
            Cancelar
          </button>
        </div>
      </form>
    </Modal>
  </div>
</template>

<style scoped>
@reference "tailwindcss";
.km-input {
  @apply w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/30;
}
</style>
