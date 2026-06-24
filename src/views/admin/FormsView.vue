<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, RouterLink } from 'vue-router'
import api from '../../services/api'
import { apiError } from '../../stores/auth'
import { confirmDialog } from '../../composables/confirm'
import Skeleton from '../../components/Skeleton.vue'
import { useTableFreeze, useDemoMode } from '../../composables/appConfig'

const { t } = useI18n()
const { freezeFirst } = useTableFreeze()
// En demo la sync manual contra Kobo está bloqueada (cuota de la cuenta demo).
const { demoMode } = useDemoMode()
const route = useRoute()

const forms = ref([])
const accountsList = ref([])
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
    const [formsRes, accRes] = await Promise.all([
      api.get('/admin/forms'),
      api.get('/admin/accounts'),
    ])
    forms.value = formsRes.data.data
    accountsList.value = accRes.data.data
  } catch (e) {
    listError.value = apiError(e, t('forms.loadError'))
  } finally {
    loading.value = false
  }
}

// Indicador global: estado de sincronización agregado por cuenta (a partir de los
// formularios ya cargados). Una cuenta con algún formulario en error → error;
// si no tiene formularios sincronizados → "nunca".
const syncStatus = computed(() =>
  accountsList.value.map((a) => {
    const fs = forms.value.filter((f) => f.account_id === a.id)
    const lastSync = fs.reduce((max, f) => (f.last_synced_at && f.last_synced_at > max ? f.last_synced_at : max), '')
    return {
      id: a.id,
      label: a.label,
      active: a.active,
      total: fs.length,
      inactive: fs.filter((f) => !f.active).length,
      hasError: fs.some((f) => f.sync_status === 'error'),
      lastSync,
    }
  }),
)

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
  success: 'bg-success-100 text-success-700 dark:bg-success-900/40 dark:text-success-300',
  error: 'bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300',
  pending: 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
}
const statusBadge = {
  deployed: 'bg-success-100 text-success-700 dark:bg-success-900/40 dark:text-success-300',
  draft: 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
  archived: 'bg-slate-200 text-slate-600',
}
const statusKey = { deployed: 'forms.typeDeployed', draft: 'forms.typeDraft', archived: 'forms.typeArchived' }

onMounted(async () => {
  await load()
  // Deep-link desde admin/accounts → «Formularios»: ?account=ID preselecciona el filtro.
  const qAcc = route.query.account
  if (qAcc && accountsList.value.some((a) => String(a.id) === String(qAcc))) {
    selectedAccount.value = String(qAcc)
  }
})
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $t('forms.title') }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ $t('forms.subtitle') }}</p>
      </div>
      <button
        :disabled="demoMode || syncing"
        class="shrink-0 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-60"
        :title="demoMode ? $t('common.demoDisabled') : undefined"
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
    <div v-if="syncError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900">
      {{ syncError }}
    </div>
    <div v-if="flash" class="rounded-lg bg-success-50 px-3 py-2 text-sm text-success-800 ring-1 ring-success-200 dark:bg-success-900/30 dark:text-success-300 dark:ring-success-800">
      {{ flash }}
    </div>
    <div v-if="syncResult" class="space-y-2">
      <div
        v-for="r in syncResult"
        :key="r.account_id"
        class="rounded-lg px-3 py-2 text-sm ring-1"
        :class="r.status === 'success'
          ? 'bg-success-50 text-success-800 ring-success-200'
          : 'bg-red-50 text-red-800 ring-red-200'"
      >
        <span class="font-medium">{{ r.account_label }}:</span>
        <template v-if="r.status === 'success'">
          {{ $t('forms.syncResultOk', { forms: r.forms }) }}<template v-if="r.skipped">{{ $t('forms.syncResultSkipped', { n: r.skipped }) }}</template><template v-if="r.deactivated">{{ $t('forms.syncResultDeactivated', { n: r.deactivated }) }}</template><template v-if="r.removed">{{ $t('forms.syncResultRemoved', { n: r.removed }) }}</template>.
        </template>
        <template v-else>{{ $t('forms.syncResultError', { error: r.error, code: r.error_code }) }}</template>
      </div>
    </div>

    <!-- Listado -->
    <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
      <div v-if="listError" class="p-4 text-sm text-red-700 dark:text-red-400">{{ listError }}</div>
      <Skeleton v-else-if="loading" variant="table" :rows="6" />
      <table v-else class="w-full whitespace-nowrap text-left text-sm">
        <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
          <tr>
            <th class="px-4 py-3" :class="freezeFirst() ? 'sticky left-0 z-10 bg-slate-50' : ''">{{ $t('forms.colForm') }}</th>
            <th class="px-4 py-3">{{ $t('forms.colAccount') }}</th>
            <th class="px-4 py-3">{{ $t('forms.colType') }}</th>
            <th class="px-4 py-3">{{ $t('forms.colSync') }}</th>
            <th class="px-4 py-3">{{ $t('forms.colLastSync') }}</th>
            <th class="px-4 py-3 text-right">{{ $t('common.actions') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="f in filteredForms" :key="f.id" :class="{ 'bg-slate-50/60': !f.active }">
            <td
              class="px-4 py-3 font-medium text-slate-900"
              :class="freezeFirst() ? ['sticky left-0 z-10', f.active ? 'bg-white' : 'bg-slate-50'] : ''"
            >
              <div class="max-w-[calc(40vw-2rem)] truncate sm:max-w-none" :title="f.name">
                {{ f.name }}
                <span
                  v-if="!f.active"
                  class="ml-1 rounded-full bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-600"
                  :title="$t('forms.inactiveTitle')"
                >{{ $t('forms.inactive') }}</span>
              </div>
            </td>
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
              <p v-if="f.sync_status === 'error' && f.last_sync_error" class="mt-1 text-xs text-red-600 dark:text-red-400">
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
                  :disabled="demoMode || updatingId === f.id"
                  class="font-medium text-primary-600 hover:underline disabled:opacity-50 disabled:no-underline"
                  :title="demoMode ? $t('common.demoDisabled') : $t('forms.updateTitle')"
                  @click="onUpdateForm(f)"
                >
                  {{ updatingId === f.id ? $t('forms.updating') : $t('forms.update') }}
                </button>
                <button
                  :disabled="demoMode || fullSyncId === f.id"
                  class="font-medium text-primary-600 hover:underline disabled:opacity-50 disabled:no-underline"
                  :title="demoMode ? $t('common.demoDisabled') : $t('forms.resyncTitle')"
                  @click="onFullResync(f)"
                >
                  {{ fullSyncId === f.id ? $t('forms.resyncing') : $t('forms.resync') }}
                </button>
                <RouterLink
                  :to="{ name: 'admin-form-settings', params: { id: f.id } }"
                  class="font-medium text-primary-600 hover:underline"
                  :title="$t('forms.settingsTitle')"
                >
                  {{ $t('forms.settings') }}
                </RouterLink>
                <button
                  class="font-medium text-red-600 dark:text-red-400 hover:underline"
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

    <!-- Indicador global del estado de sincronización por cuenta -->
    <section v-if="!loading && syncStatus.length" class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
      <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('forms.syncStatusTitle') }}</h2>
      <ul class="grid gap-2 sm:grid-cols-2">
        <li
          v-for="s in syncStatus"
          :key="s.id"
          class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 px-3 py-2"
        >
          <div class="flex min-w-0 items-center gap-2">
            <span
              class="h-2 w-2 shrink-0 rounded-full"
              :class="s.hasError ? 'bg-red-500' : (s.lastSync ? 'bg-success-500' : 'bg-slate-300')"
              :title="s.hasError ? $t('forms.syncStateError') : (s.lastSync ? $t('forms.syncStateOk') : $t('forms.syncStateNever'))"
            ></span>
            <span class="truncate text-sm font-medium text-slate-800">{{ s.label }}</span>
          </div>
          <div class="shrink-0 text-right text-xs text-slate-500">
            <span>{{ s.lastSync || $t('forms.lastSyncNever') }}</span>
            <span class="ml-2 text-slate-400">{{ $t('forms.formsSummary', { n: s.total, inactive: s.inactive }) }}</span>
          </div>
        </li>
      </ul>
    </section>
  </div>
</template>
