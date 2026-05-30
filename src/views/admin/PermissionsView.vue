<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '../../services/api'
import { apiError } from '../../stores/auth'

const users = ref([])
const selectedUserId = ref('')
const perms = ref([]) // [{ form_id, name, account_id, account_label, can_view, can_edit, can_validate }]

const loadingPerms = ref(false)
const error = ref('')
const saving = ref(false)
const saved = ref(false)

// Filtro por cuenta (solo afecta a lo mostrado; al guardar se envían todos).
const selectedAccount = ref('')
const accounts = computed(() => {
  const map = new Map()
  for (const p of perms.value) map.set(p.account_id, p.account_label)
  return [...map].map(([id, label]) => ({ id, label }))
})
const visiblePerms = computed(() =>
  selectedAccount.value === ''
    ? perms.value
    : perms.value.filter((p) => p.account_id === Number(selectedAccount.value)),
)

async function loadUsers() {
  try {
    const { data } = await api.get('/admin/users')
    users.value = data.data
  } catch (e) {
    error.value = apiError(e, 'No se pudieron cargar los usuarios')
  }
}

async function loadPerms() {
  saved.value = false
  if (!selectedUserId.value) {
    perms.value = []
    return
  }
  loadingPerms.value = true
  error.value = ''
  try {
    const { data } = await api.get('/admin/permissions', { params: { user_id: selectedUserId.value } })
    perms.value = data.data
  } catch (e) {
    error.value = apiError(e, 'No se pudieron cargar los permisos')
  } finally {
    loadingPerms.value = false
  }
}

async function onSave() {
  saving.value = true
  saved.value = false
  error.value = ''
  try {
    await api.put('/admin/permissions', {
      user_id: Number(selectedUserId.value),
      permissions: perms.value,
    })
    saved.value = true
  } catch (e) {
    error.value = apiError(e, 'No se pudieron guardar los permisos')
  } finally {
    saving.value = false
  }
}

// Marcar can_view automáticamente si se concede edit o validate (no tiene sentido editar sin ver).
function onToggle(p) {
  if (p.can_edit || p.can_validate) p.can_view = true
  saved.value = false
}

onMounted(loadUsers)
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Permisos</h1>
      <p class="mt-1 text-sm text-slate-500">
        Asigna qué formularios puede ver, editar o validar cada usuario.
      </p>
    </header>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">
      {{ error }}
    </div>

    <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
      <div class="flex items-center gap-2">
        <label class="text-sm text-slate-600">Usuario:</label>
        <select
          v-model="selectedUserId"
          class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/30"
          @change="loadPerms"
        >
          <option value="">— Selecciona un usuario —</option>
          <option v-for="u in users" :key="u.id" :value="u.id">
            {{ u.name }} ({{ u.email }}) · {{ u.role }}
          </option>
        </select>
      </div>
      <div v-if="selectedUserId && accounts.length" class="flex items-center gap-2">
        <label class="text-sm text-slate-600">Cuenta:</label>
        <select
          v-model="selectedAccount"
          class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/30"
        >
          <option value="">Todas las cuentas</option>
          <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.label }}</option>
        </select>
      </div>
    </div>

    <div
      v-if="selectedUserId"
      class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200"
    >
      <div v-if="loadingPerms" class="p-4 text-sm text-slate-500">Cargando…</div>
      <template v-else>
        <table class="w-full text-left text-sm">
          <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
            <tr>
              <th class="px-4 py-3">Formulario</th>
              <th class="px-4 py-3 text-center">Ver</th>
              <th class="px-4 py-3 text-center">Editar</th>
              <th class="px-4 py-3 text-center">Validar</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="p in visiblePerms" :key="p.form_id">
              <td class="px-4 py-3">
                <p class="font-medium text-slate-900">{{ p.name }}</p>
                <p class="text-xs text-slate-400">{{ p.account_label }}</p>
              </td>
              <td class="px-4 py-3 text-center">
                <input type="checkbox" v-model="p.can_view" @change="saved = false" />
              </td>
              <td class="px-4 py-3 text-center">
                <input type="checkbox" v-model="p.can_edit" @change="onToggle(p)" />
              </td>
              <td class="px-4 py-3 text-center">
                <input type="checkbox" v-model="p.can_validate" @change="onToggle(p)" />
              </td>
            </tr>
            <tr v-if="!visiblePerms.length">
              <td colspan="4" class="px-4 py-6 text-center text-slate-400">
                No hay formularios. Sincroniza primero en «Formularios».
              </td>
            </tr>
          </tbody>
        </table>

        <div v-if="perms.length" class="flex items-center gap-3 border-t border-slate-100 p-4">
          <button
            :disabled="saving"
            class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60"
            @click="onSave"
          >
            {{ saving ? 'Guardando…' : 'Guardar permisos' }}
          </button>
          <span v-if="saved" class="text-sm text-green-600">Guardado ✓</span>
        </div>
      </template>
    </div>
  </div>
</template>
