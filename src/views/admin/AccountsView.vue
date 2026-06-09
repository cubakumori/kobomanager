<script setup>
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../../services/api'
import { apiError } from '../../stores/auth'
import Modal from '../../components/Modal.vue'
import { confirmDialog } from '../../composables/confirm'
import Skeleton from '../../components/Skeleton.vue'

const { t } = useI18n()

const accounts = ref([])
const loading = ref(true)
const listError = ref('')

const creating = ref(false)
const form = ref({ label: '', server_url: 'https://eu.kobotoolbox.org', email: '', api_token: '' })
const formError = ref('')
const saving = ref(false)

function startCreate() {
  formError.value = ''
  form.value = { label: '', server_url: 'https://eu.kobotoolbox.org', email: '', api_token: '' }
  creating.value = true
}

// Edición
const editing = ref(null)
const editForm = ref({ label: '', server_url: '', email: '', api_token: '' })
const editError = ref('')
const savingEdit = ref(false)

// Sincronización por cuenta
const syncingId = ref(null)
const syncFlash = ref('')
const syncError = ref('')

async function load() {
  loading.value = true
  listError.value = ''
  try {
    const { data } = await api.get('/admin/accounts')
    accounts.value = data.data
  } catch (e) {
    listError.value = apiError(e, t('accounts.loadError'))
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
    creating.value = false
    await load()
  } catch (e) {
    formError.value = apiError(e, t('accounts.createError'))
  } finally {
    saving.value = false
  }
}

function startEdit(a) {
  editError.value = ''
  editing.value = a
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
    editError.value = apiError(e, t('accounts.createError'))
  } finally {
    savingEdit.value = false
  }
}

async function syncAccount(a) {
  syncingId.value = a.id
  syncFlash.value = ''
  syncError.value = ''
  try {
    const { data } = await api.post('/admin/forms/sync', { account_id: a.id })
    const r = data.data[0]
    if (r && r.status === 'success') {
      syncFlash.value = t('accounts.syncOk', { label: a.label, forms: r.forms }) +
        (r.skipped ? t('accounts.syncSkipped', { n: r.skipped }) : '') +
        (r.removed ? t('accounts.syncRemoved', { n: r.removed }) : '') + '.'
      await load()
    } else {
      syncError.value = `«${a.label}»: ${r?.error ?? t('accounts.syncError')}`
    }
  } catch (e) {
    syncError.value = `«${a.label}»: ${apiError(e, t('accounts.syncError'))}`
  } finally {
    syncingId.value = null
  }
}

async function removeAccount(a) {
  const ok = await confirmDialog({
    title: t('accounts.confirmDeleteTitle'),
    message: t('accounts.confirmDelete', { label: a.label }),
    confirmText: t('common.delete'),
    danger: true,
  })
  if (!ok) return
  syncError.value = ''
  syncFlash.value = ''
  try {
    await api.delete(`/admin/accounts/${a.id}`)
    syncFlash.value = t('accounts.deleted', { label: a.label })
    await load()
  } catch (e) {
    syncError.value = apiError(e, t('accounts.deleteError'))
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $t('accounts.title') }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ $t('accounts.subtitle') }}</p>
      </div>
      <button
        class="shrink-0 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700"
        @click="startCreate"
      >
        {{ $t('accounts.newAccount') }}
      </button>
    </header>

    <!-- Alta (modal) -->
    <Modal v-if="creating" :title="$t('accounts.newAccount')" @close="creating = false">
      <form class="space-y-4" @submit.prevent="onCreate">
        <div
          v-if="formError"
          class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200"
        >
          {{ formError }}
        </div>
        <label class="block space-y-1">
          <span class="block text-sm font-medium text-slate-700">{{ $t('accounts.label') }}</span>
          <input v-model="form.label" required class="km-input" />
        </label>
        <label class="block space-y-1">
          <span class="block text-sm font-medium text-slate-700">{{ $t('accounts.serverUrl') }}</span>
          <input v-model="form.server_url" type="url" required class="km-input" />
        </label>
        <label class="block space-y-1">
          <span class="block text-sm font-medium text-slate-700">{{ $t('accounts.accountEmail') }}</span>
          <input v-model="form.email" type="email" required class="km-input" />
        </label>
        <label class="block space-y-1">
          <span class="block text-sm font-medium text-slate-700">{{ $t('accounts.apiToken') }}</span>
          <input v-model="form.api_token" type="password" required class="km-input" />
          <RouterLink :to="{ name: 'about-kobo' }" class="text-xs text-primary-600 hover:underline">
            {{ $t('accounts.tokenHelp') }}
          </RouterLink>
        </label>
        <div class="flex items-center gap-3 pt-1">
          <button
            type="submit"
            :disabled="saving"
            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-60"
          >
            {{ saving ? $t('common.saving') : $t('accounts.addAccount') }}
          </button>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-700" @click="creating = false">
            {{ $t('common.cancel') }}
          </button>
        </div>
      </form>
    </Modal>

    <!-- Resultado de sincronización por cuenta -->
    <div v-if="syncError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">
      {{ syncError }}
    </div>
    <div v-if="syncFlash" class="rounded-lg bg-success-50 px-3 py-2 text-sm text-success-800 ring-1 ring-success-200">
      {{ syncFlash }}
    </div>

    <!-- Listado -->
    <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
      <div v-if="listError" class="p-4 text-sm text-red-700">{{ listError }}</div>
      <Skeleton v-else-if="loading" variant="table" :rows="4" />
      <table v-else class="w-full whitespace-nowrap text-left text-sm">
        <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
          <tr>
            <th class="px-4 py-3">{{ $t('accounts.label') }}</th>
            <th class="px-4 py-3">{{ $t('accounts.serverUrl') }}</th>
            <th class="px-4 py-3">{{ $t('common.email') }}</th>
            <th class="px-4 py-3">{{ $t('common.status') }}</th>
            <th class="px-4 py-3 text-right">{{ $t('common.actions') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="a in accounts" :key="a.id">
            <td class="px-4 py-3 font-medium text-slate-900">{{ a.label }}</td>
            <td class="px-4 py-3 text-slate-600">{{ a.server_url }}</td>
            <td class="px-4 py-3 text-slate-600">{{ a.email }}</td>
            <td class="px-4 py-3">
              <span :class="a.active ? 'text-success-600' : 'text-slate-400'">
                {{ a.active ? $t('accounts.active') : $t('accounts.inactive') }}
              </span>
            </td>
            <td class="px-4 py-3">
              <div class="flex items-center justify-end gap-3">
                <button
                  :disabled="syncingId === a.id"
                  class="font-medium text-primary-600 hover:underline disabled:opacity-50"
                  @click="syncAccount(a)"
                >
                  {{ syncingId === a.id ? $t('accounts.syncing') : $t('accounts.sync') }}
                </button>
                <RouterLink
                  :to="{ name: 'admin-forms', query: { account: a.id } }"
                  class="font-medium text-primary-600 hover:underline"
                >
                  {{ $t('accounts.forms') }}
                </RouterLink>
                <button class="font-medium text-primary-600 hover:underline" @click="startEdit(a)">
                  {{ $t('common.edit') }}
                </button>
                <button
                  v-if="a.forms_count === 0"
                  class="font-medium text-red-600 hover:underline"
                  @click="removeAccount(a)"
                >
                  {{ $t('common.delete') }}
                </button>
                <span
                  v-else
                  class="cursor-help text-slate-300"
                  :title="$t('accounts.cantDeleteTooltip', { n: a.forms_count })"
                >
                  {{ $t('common.delete') }}
                </span>
              </div>
            </td>
          </tr>
          <tr v-if="!accounts.length">
            <td colspan="5" class="px-4 py-6 text-center text-slate-400">{{ $t('accounts.empty') }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Modal de edición -->
    <Modal v-if="editing" :title="$t('accounts.editTitle', { label: editing.label })" @close="editing = null">
      <form class="space-y-4" @submit.prevent="saveEdit">
        <div v-if="editError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
          {{ editError }}
        </div>
        <label class="block space-y-1">
          <span class="block text-sm font-medium text-slate-700">{{ $t('accounts.label') }}</span>
          <input v-model="editForm.label" required class="km-input" />
        </label>
        <label class="block space-y-1">
          <span class="block text-sm font-medium text-slate-700">{{ $t('accounts.serverUrl') }}</span>
          <input v-model="editForm.server_url" type="url" required class="km-input" />
        </label>
        <label class="block space-y-1">
          <span class="block text-sm font-medium text-slate-700">{{ $t('accounts.accountEmail') }}</span>
          <input v-model="editForm.email" type="email" required class="km-input" />
        </label>
        <label class="block space-y-1">
          <span class="block text-sm font-medium text-slate-700">{{ $t('accounts.apiToken') }}</span>
          <input
            v-model="editForm.api_token"
            type="password"
            :placeholder="$t('accounts.tokenKeepPlaceholder')"
            class="km-input"
          />
          <span class="text-xs text-slate-400">{{ $t('accounts.tokenKeepHint') }}</span>
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
