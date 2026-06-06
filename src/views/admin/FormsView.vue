<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../../services/api'
import { apiError } from '../../stores/auth'
import { confirmDialog } from '../../composables/confirm'

const { t } = useI18n()

const forms = ref([])
const loading = ref(true)
const listError = ref('')

const syncing = ref(false)
const syncResult = ref(null) // resumen por cuenta tras sincronizar
const syncError = ref('')

const updatingId = ref(null)     // formulario que está actualizando sus envíos
const fullSyncId = ref(null)     // formulario en resincronización completa
const enketoId = ref(null)   // formulario cuyo enlace Enketo se está resolviendo
const flash = ref('')        // mensaje breve de resultado de "Actualizar"

const selectedAccount = ref('') // '' = todas

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
    listError.value = apiError(e, t('forms.loadError'))
  } finally {
    loading.value = false
  }
}

async function onSync() {
  syncing.value = true
  syncError.value = ''
  syncResult.value = null
  try {
    const body = selectedAccount.value ? { account_id: Number(selectedAccount.value) } : {}
    const { data } = await api.post('/admin/forms/sync', body)
    syncResult.value = data.data
    await load()
  } catch (e) {
    syncError.value = apiError(e, t('forms.syncErr'))
  } finally {
    syncing.value = false
  }
}

async function removeForm(f) {
  const ok = await confirmDialog({
    title: t('forms.confirmDeleteTitle'),
    message: t('forms.confirmDelete', { name: f.name }),
    confirmText: t('common.delete'),
    danger: true,
  })
  if (!ok) return
  flash.value = ''
  syncError.value = ''
  try {
    await api.delete(`/admin/forms/${f.id}`)
    flash.value = t('forms.deletedFlash', { name: f.name })
    await load()
  } catch (e) {
    syncError.value = `«${f.name}»: ${apiError(e, t('forms.deleteErr'))}`
  }
}

async function openEnketo(f) {
  // Abrir la pestaña de forma síncrona evita el bloqueo de pop-ups.
  const win = window.open('', '_blank')
  enketoId.value = f.id
  syncError.value = ''
  try {
    const { data } = await api.get(`/admin/forms/${f.id}/enketo`)
    if (data.data.url && win) win.location = data.data.url
    else if (win) win.close()
  } catch (e) {
    if (win) win.close()
    syncError.value = `«${f.name}»: ${apiError(e, t('forms.enketoErr'))}`
  } finally {
    enketoId.value = null
  }
}

function syncFlash(name, d) {
  let msg = t('forms.updatedFlash', { name, n: d.submissions })
  if (d.removed) msg += t('forms.removedFlash', { n: d.removed })
  return msg
}

async function onUpdateForm(f) {
  updatingId.value = f.id
  flash.value = ''
  syncError.value = ''
  try {
    const { data } = await api.post(`/admin/forms/${f.id}/sync`)
    flash.value = syncFlash(f.name, data.data)
    await load()
  } catch (e) {
    syncError.value = `«${f.name}»: ${apiError(e, t('forms.updateErr'))}`
  } finally {
    updatingId.value = null
  }
}

async function onFullResync(f) {
  const ok = await confirmDialog({
    title: t('forms.confirmResyncTitle'),
    message: t('forms.confirmResync', { name: f.name }),
    confirmText: t('forms.resync'),
  })
  if (!ok) return
  fullSyncId.value = f.id
  flash.value = ''
  syncError.value = ''
  try {
    const { data } = await api.post(`/admin/forms/${f.id}/sync`, { full: true })
    flash.value = syncFlash(f.name, data.data)
    await load()
  } catch (e) {
    syncError.value = `«${f.name}»: ${apiError(e, t('forms.updateErr'))}`
  } finally {
    fullSyncId.value = null
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
const statusKey = { deployed: 'forms.typeDeployed', draft: 'forms.typeDraft', archived: 'forms.typeArchived' }

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $t('forms.title') }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ $t('forms.subtitle') }}</p>
      </div>
      <button
        :disabled="syncing"
        class="shrink-0 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-60"
        @click="onSync"
      >
        {{ syncing ? $t('forms.syncing') : (selectedAccount ? $t('forms.syncAccount') : $t('forms.syncAll')) }}
      </button>
    </header>

    <!-- Filtro por cuenta -->
    <div class="flex items-center gap-2">
      <label class="text-sm text-slate-600">{{ $t('forms.accountFilter') }}</label>
      <select
        v-model="selectedAccount"
        class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
      >
        <option value="">{{ $t('forms.allAccounts') }}</option>
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
          {{ $t('forms.syncResultOk', { forms: r.forms }) }}<template v-if="r.skipped">{{ $t('forms.syncResultSkipped', { n: r.skipped }) }}</template><template v-if="r.removed">{{ $t('forms.syncResultRemoved', { n: r.removed }) }}</template>.
        </template>
        <template v-else>{{ $t('forms.syncResultError', { error: r.error, code: r.error_code }) }}</template>
      </div>
    </div>

    <!-- Listado -->
    <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
      <div v-if="listError" class="p-4 text-sm text-red-700">{{ listError }}</div>
      <div v-else-if="loading" class="p-4 text-sm text-slate-500">{{ $t('common.loading') }}</div>
      <table v-else class="w-full text-left text-sm">
        <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
          <tr>
            <th class="px-4 py-3">{{ $t('forms.colForm') }}</th>
            <th class="px-4 py-3">{{ $t('forms.colAccount') }}</th>
            <th class="px-4 py-3">{{ $t('forms.colType') }}</th>
            <th class="px-4 py-3">{{ $t('forms.colSync') }}</th>
            <th class="px-4 py-3">{{ $t('forms.colLastSync') }}</th>
            <th class="px-4 py-3 text-right">{{ $t('common.actions') }}</th>
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
              >{{ statusKey[f.deployment_status] ? $t(statusKey[f.deployment_status]) : f.deployment_status }}</span>
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
                  v-if="f.deployment_status === 'deployed'"
                  :disabled="enketoId === f.id"
                  class="font-medium text-primary-600 hover:underline disabled:opacity-50"
                  :title="$t('forms.viewTitle')"
                  @click="openEnketo(f)"
                >
                  {{ enketoId === f.id ? '…' : $t('forms.view') }}
                </button>
                <a
                  :href="`${f.server_url}/#/forms/${f.kobo_asset_uid}`"
                  target="_blank"
                  rel="noopener"
                  class="font-medium text-primary-600 hover:underline"
                  :title="$t('forms.loginTitle')"
                >
                  {{ $t('forms.login') }}
                </a>
                <button
                  :disabled="updatingId === f.id"
                  class="font-medium text-primary-600 hover:underline disabled:opacity-50"
                  :title="$t('forms.updateTitle')"
                  @click="onUpdateForm(f)"
                >
                  {{ updatingId === f.id ? $t('forms.updating') : $t('forms.update') }}
                </button>
                <button
                  :disabled="fullSyncId === f.id"
                  class="font-medium text-primary-600 hover:underline disabled:opacity-50"
                  :title="$t('forms.resyncTitle')"
                  @click="onFullResync(f)"
                >
                  {{ fullSyncId === f.id ? $t('forms.resyncing') : $t('forms.resync') }}
                </button>
                <button
                  class="font-medium text-red-600 hover:underline"
                  :title="$t('forms.deleteTitle')"
                  @click="removeForm(f)"
                >
                  {{ $t('common.delete') }}
                </button>
              </div>
            </td>
          </tr>
          <tr v-if="!filteredForms.length">
            <td colspan="6" class="px-4 py-6 text-center text-slate-400">{{ $t('forms.empty') }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
