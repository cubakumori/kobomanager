<script setup>
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../../services/api'
import { useAuthStore, apiError } from '../../stores/auth'
import Modal from '../../components/Modal.vue'
import { confirmDialog } from '../../composables/confirm'

const { t } = useI18n()
const auth = useAuthStore()

const users = ref([])
const loading = ref(true)
const listError = ref('')

const creating = ref(false)
const form = ref({ name: '', email: '', password: '', role: 'viewer' })
const formError = ref('')
const saving = ref(false)
const actionError = ref('')

function startCreate() {
  formError.value = ''
  form.value = { name: '', email: '', password: '', role: 'viewer' }
  creating.value = true
}

// Edición
const editing = ref(null)
const editForm = ref({ name: '', email: '', role: 'viewer', password: '' })
const editError = ref('')
const savingEdit = ref(false)

async function load() {
  loading.value = true
  listError.value = ''
  try {
    const { data } = await api.get('/admin/users')
    users.value = data.data
  } catch (e) {
    listError.value = apiError(e, t('users.loadError'))
  } finally {
    loading.value = false
  }
}

async function onCreate() {
  formError.value = ''
  saving.value = true
  try {
    await api.post('/admin/users', form.value)
    form.value = { name: '', email: '', password: '', role: 'viewer' }
    creating.value = false
    await load()
  } catch (e) {
    formError.value = apiError(e, t('users.createError'))
  } finally {
    saving.value = false
  }
}

function startEdit(u) {
  editError.value = ''
  editing.value = u
  editForm.value = { name: u.name, email: u.email, role: u.role, password: '' }
}

async function saveEdit() {
  savingEdit.value = true
  editError.value = ''
  try {
    const payload = {
      name: editForm.value.name,
      email: editForm.value.email,
      role: editForm.value.role,
      active: editing.value.active,
    }
    if (editForm.value.password) payload.password = editForm.value.password
    await api.put(`/admin/users/${editing.value.id}`, payload)
    editing.value = null
    await load()
  } catch (e) {
    editError.value = apiError(e, t('users.saveError'))
  } finally {
    savingEdit.value = false
  }
}

async function revokeSessions(u) {
  const ok = await confirmDialog({
    title: t('users.confirmRevokeTitle'),
    message: t('users.confirmRevoke', { name: u.name }),
    confirmText: t('users.revokeSessions'),
    danger: true,
  })
  if (!ok) return
  actionError.value = ''
  try {
    const { data } = await api.delete(`/admin/users/${u.id}/sessions`)
    // Si el admin cerró sus propias sesiones, la siguiente petición dará 401 → login.
    if (u.id === auth.user?.id) {
      await auth.logout()
      return
    }
    u.active_sessions = 0
    void data
  } catch (e) {
    actionError.value = apiError(e, t('users.revokeError'))
  }
}

async function toggleActive(u) {
  const ok = await confirmDialog({
    title: u.active ? t('users.confirmDeactivateTitle') : t('users.confirmActivateTitle'),
    message: u.active ? t('users.confirmDeactivate', { name: u.name }) : t('users.confirmActivate', { name: u.name }),
    confirmText: u.active ? t('users.deactivate') : t('users.activate'),
    danger: u.active,
  })
  if (!ok) return
  actionError.value = ''
  try {
    await api.put(`/admin/users/${u.id}`, { name: u.name, email: u.email, role: u.role, active: !u.active })
    await load()
  } catch (e) {
    actionError.value = apiError(e, t('users.toggleError'))
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $t('users.title') }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ $t('users.subtitle') }}</p>
      </div>
      <button
        class="shrink-0 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700"
        @click="startCreate"
      >
        {{ $t('users.newUser') }}
      </button>
    </header>

    <div v-if="actionError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">
      {{ actionError }}
    </div>

    <!-- Alta (modal) -->
    <Modal v-if="creating" :title="$t('users.newUser')" @close="creating = false">
      <form class="space-y-4" @submit.prevent="onCreate">
        <div
          v-if="formError"
          class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200"
        >
          {{ formError }}
        </div>
        <label class="block space-y-1">
          <span class="block text-sm font-medium text-slate-700">{{ $t('common.name') }}</span>
          <input v-model="form.name" required class="km-input" />
        </label>
        <label class="block space-y-1">
          <span class="block text-sm font-medium text-slate-700">{{ $t('common.email') }}</span>
          <input v-model="form.email" type="email" required class="km-input" />
        </label>
        <label class="block space-y-1">
          <span class="block text-sm font-medium text-slate-700">{{ $t('users.password') }}</span>
          <input v-model="form.password" type="password" required minlength="8" class="km-input" />
        </label>
        <label class="block space-y-1">
          <span class="block text-sm font-medium text-slate-700">{{ $t('common.role') }}</span>
          <select v-model="form.role" class="km-input">
            <option value="viewer">viewer</option>
            <option value="admin">admin</option>
          </select>
        </label>
        <div class="flex items-center gap-3 pt-1">
          <button
            type="submit"
            :disabled="saving"
            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-60"
          >
            {{ saving ? $t('users.creating') : $t('users.createUser') }}
          </button>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-700" @click="creating = false">
            {{ $t('common.cancel') }}
          </button>
        </div>
      </form>
    </Modal>

    <!-- Listado -->
    <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
      <div v-if="listError" class="p-4 text-sm text-red-700">{{ listError }}</div>
      <div v-else-if="loading" class="p-4 text-sm text-slate-500">{{ $t('common.loading') }}</div>
      <table v-else class="w-full text-left text-sm">
        <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
          <tr>
            <th class="px-4 py-3">{{ $t('common.name') }}</th>
            <th class="px-4 py-3">{{ $t('common.email') }}</th>
            <th class="px-4 py-3">{{ $t('common.role') }}</th>
            <th class="px-4 py-3">{{ $t('common.status') }}</th>
            <th class="px-4 py-3">{{ $t('users.sessions') }}</th>
            <th class="px-4 py-3 text-right">{{ $t('common.actions') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="u in users" :key="u.id">
            <td class="px-4 py-3 font-medium text-slate-900">
              {{ u.name }}
              <span v-if="u.id === auth.user?.id" class="ml-1 text-xs text-slate-400">{{ $t('users.you') }}</span>
            </td>
            <td class="px-4 py-3 text-slate-600">{{ u.email }}</td>
            <td class="px-4 py-3">
              <span
                class="rounded-full px-2 py-0.5 text-xs font-medium"
                :class="u.role === 'admin' ? 'bg-purple-100 text-purple-700' : 'bg-slate-100 text-slate-600'"
              >{{ u.role }}</span>
            </td>
            <td class="px-4 py-3">
              <span :class="u.active ? 'text-green-600' : 'text-slate-400'">
                {{ u.active ? $t('users.active') : $t('users.inactive') }}
              </span>
            </td>
            <td class="px-4 py-3 text-slate-600">
              <span v-if="u.active_sessions">{{ u.active_sessions }}</span>
              <span v-else class="text-slate-300">—</span>
            </td>
            <td class="px-4 py-3">
              <div class="flex items-center justify-end gap-3">
                <button class="font-medium text-primary-600 hover:underline" @click="startEdit(u)">
                  {{ $t('common.edit') }}
                </button>
                <button
                  v-if="u.active_sessions"
                  class="font-medium text-amber-600 hover:underline"
                  :title="$t('users.revokeSessionsTitle')"
                  @click="revokeSessions(u)"
                >
                  {{ $t('users.revokeSessions') }}
                </button>
                <button
                  v-if="u.id !== auth.user?.id"
                  class="font-medium hover:underline"
                  :class="u.active ? 'text-red-600' : 'text-green-600'"
                  @click="toggleActive(u)"
                >
                  {{ u.active ? $t('users.deactivate') : $t('users.activate') }}
                </button>
                <span v-else class="text-slate-300" :title="$t('users.cantDeactivateSelf')">
                  {{ $t('users.deactivate') }}
                </span>
              </div>
            </td>
          </tr>
          <tr v-if="!users.length">
            <td colspan="6" class="px-4 py-6 text-center text-slate-400">{{ $t('users.empty') }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Modal de edición -->
    <Modal v-if="editing" :title="$t('users.editTitle', { name: editing.name })" @close="editing = null">
      <form class="space-y-4" @submit.prevent="saveEdit">
        <div v-if="editError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
          {{ editError }}
        </div>
        <label class="block space-y-1">
          <span class="block text-sm font-medium text-slate-700">{{ $t('common.name') }}</span>
          <input v-model="editForm.name" required class="km-input" />
        </label>
        <label class="block space-y-1">
          <span class="block text-sm font-medium text-slate-700">{{ $t('common.email') }}</span>
          <input v-model="editForm.email" type="email" required class="km-input" />
        </label>
        <label class="block space-y-1">
          <span class="block text-sm font-medium text-slate-700">{{ $t('common.role') }}</span>
          <select v-model="editForm.role" class="km-input">
            <option value="viewer">viewer</option>
            <option value="admin">admin</option>
          </select>
        </label>
        <label class="block space-y-1">
          <span class="block text-sm font-medium text-slate-700">{{ $t('users.newPassword') }}</span>
          <input
            v-model="editForm.password"
            type="password"
            :placeholder="$t('users.keepPassword')"
            class="km-input"
          />
          <span class="text-xs text-slate-400">{{ $t('users.newPasswordHint') }}</span>
        </label>
        <div class="flex items-center gap-3 pt-1">
          <button
            type="submit"
            :disabled="savingEdit"
            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-60"
          >
            {{ savingEdit ? $t('common.saving') : $t('common.save') }}
          </button>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-700" @click="editing = null">
            {{ $t('common.cancel') }}
          </button>
        </div>
      </form>
    </Modal>
  </div>
</template>

<style scoped>
@reference "../../style.css";
.km-input {
  @apply w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30;
}
</style>
