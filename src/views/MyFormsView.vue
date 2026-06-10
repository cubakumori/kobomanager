<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import api from '../services/api'
import { useAuthStore, apiError } from '../stores/auth'
import { confirmDialog } from '../composables/confirm'
import { useDemoMode } from '../composables/appConfig'
import Skeleton from '../components/Skeleton.vue'

const { t } = useI18n()
const auth = useAuthStore()
// En demo la sync manual contra Kobo está bloqueada (cuota de la cuenta demo).
const { demoMode } = useDemoMode()

const forms = ref([])
const loading = ref(true)
const error = ref('')

// Filtro por cuenta Kobo (igual que admin/forms). Solo se ofrece si hay 2+ cuentas.
const selectedAccount = ref('') // '' = todas
const accounts = computed(() => {
  const map = new Map()
  for (const f of forms.value) if (f.account_id) map.set(f.account_id, f.account_label)
  return [...map].map(([id, label]) => ({ id, label }))
})
const filteredForms = computed(() =>
  selectedAccount.value === ''
    ? forms.value
    : forms.value.filter((f) => f.account_id === Number(selectedAccount.value)),
)

// Acciones que el admin habilitó para los viewers (los admin las tienen siempre).
const actions = ref({ enketo: false, update: false, resync: false, login: false })
const can = (a) => auth.isAdmin || !!actions.value[a]
const anyAction = computed(() => ['enketo', 'update', 'resync', 'login'].some(can))

const enketoId = ref(null)
const busyId = ref(null) // formulario en actualización/resync
const flash = ref('')
const actionError = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [formsRes, cfg] = await Promise.all([api.get('/forms'), api.get('/config')])
    forms.value = formsRes.data.data
    if (cfg.data.data.viewer_actions) actions.value = cfg.data.data.viewer_actions
  } catch (e) {
    error.value = apiError(e, t('myForms.loadError'))
  } finally {
    loading.value = false
  }
}

function loginUrl(f) {
  return `${f.server_url}/#/forms/${f.kobo_asset_uid}`
}

async function openEnketo(f) {
  const win = window.open('', '_blank') // abrir síncrono evita el bloqueo de pop-ups
  enketoId.value = f.id
  actionError.value = ''
  try {
    const { data } = await api.get(`/forms/${f.id}/enketo`)
    if (data.data.url && win) win.location = data.data.url
    else if (win) win.close()
  } catch (e) {
    if (win) win.close()
    actionError.value = `«${f.name}»: ${apiError(e, t('forms.enketoErr'))}`
  } finally {
    enketoId.value = null
  }
}

async function onUpdate(f, full = false) {
  if (full) {
    const ok = await confirmDialog({
      title: t('forms.confirmResyncTitle'),
      message: t('forms.confirmResync', { name: f.name }),
      confirmText: t('forms.resync'),
    })
    if (!ok) return
  }
  busyId.value = f.id
  flash.value = ''
  actionError.value = ''
  try {
    const { data } = await api.post(`/forms/${f.id}/sync`, full ? { full: true } : {})
    let msg = t('forms.updatedFlash', { name: f.name, n: data.data.submissions })
    if (data.data.removed) msg += t('forms.removedFlash', { n: data.data.removed })
    flash.value = msg
    await load()
  } catch (e) {
    actionError.value = `«${f.name}»: ${apiError(e, t('forms.updateErr'))}`
  } finally {
    busyId.value = null
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $t('myForms.title') }}</h1>
      <p class="mt-1 text-sm text-slate-500">{{ $t('myForms.subtitle') }}</p>
    </header>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900">
      {{ error }}
    </div>
    <div v-if="actionError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900">
      {{ actionError }}
    </div>
    <div v-if="flash" class="rounded-lg bg-success-50 px-3 py-2 text-sm text-success-800 ring-1 ring-success-200 dark:bg-success-900/30 dark:text-success-300 dark:ring-success-800">
      {{ flash }}
    </div>

    <div v-if="loading" class="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
      <Skeleton variant="table" :rows="6" />
    </div>

    <div v-else-if="!forms.length" class="rounded-xl bg-white p-6 text-sm text-slate-400 shadow-sm ring-1 ring-slate-200">
      {{ $t('myForms.empty') }}
    </div>

    <template v-else>
      <label v-if="accounts.length > 1" class="flex items-center gap-2 text-sm text-slate-600">
        {{ $t('forms.accountFilter') }}
        <select
          v-model="selectedAccount"
          class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
        >
          <option value="">{{ $t('forms.allAccounts') }}</option>
          <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.label }}</option>
        </select>
      </label>

    <div class="grid gap-4 sm:grid-cols-2">
      <div
        v-for="f in filteredForms"
        :key="f.id"
        class="flex flex-col rounded-xl bg-accent-50 p-5 shadow-sm ring-1 ring-accent-200 transition hover:ring-accent-400 dark:bg-accent-900/25 dark:ring-accent-800 dark:hover:ring-accent-600"
      >
        <RouterLink :to="{ name: 'submissions', params: { id: f.id } }" class="block">
          <p class="text-xs uppercase tracking-wider text-accent-600 dark:text-accent-400">{{ f.account_label }}</p>
          <h2 class="mt-1 font-semibold text-accent-900 dark:text-accent-100">{{ f.name }}</h2>
          <p v-if="f.submissions_synced === false" class="mt-2 text-sm italic text-accent-900/50 dark:text-accent-200/50">{{ $t('myForms.notSynced') }}</p>
          <p v-else class="mt-2 text-sm text-accent-900/70 dark:text-accent-200/70">{{ $t('myForms.count', { n: f.submission_count }) }}</p>
        </RouterLink>

        <div v-if="anyAction" class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-accent-200/70 dark:border-accent-800/70 pt-3 text-sm">
          <RouterLink
            :to="{ name: 'submissions', params: { id: f.id } }"
            class="font-medium text-accent-800 hover:underline dark:text-accent-300"
          >
            {{ $t('myForms.viewSubmissions') }}
          </RouterLink>
          <button
            v-if="can('enketo') && f.deployment_status === 'deployed'"
            :disabled="enketoId === f.id"
            class="font-medium text-accent-800 hover:underline dark:text-accent-300 disabled:opacity-50"
            :title="$t('forms.viewTitle')"
            @click="openEnketo(f)"
          >
            {{ enketoId === f.id ? '…' : $t('forms.view') }}
          </button>
          <a
            v-if="can('login')"
            :href="loginUrl(f)"
            target="_blank"
            rel="noopener"
            class="font-medium text-accent-800 hover:underline dark:text-accent-300"
            :title="$t('forms.loginTitle')"
          >
            {{ $t('forms.login') }}
          </a>
          <button
            v-if="can('update')"
            :disabled="demoMode || busyId === f.id"
            class="font-medium text-accent-800 hover:underline dark:text-accent-300 disabled:opacity-50 disabled:no-underline"
            :title="demoMode ? $t('common.demoDisabled') : $t('forms.updateTitle')"
            @click="onUpdate(f, false)"
          >
            {{ busyId === f.id ? $t('forms.updating') : $t('forms.update') }}
          </button>
          <button
            v-if="can('resync')"
            :disabled="demoMode || busyId === f.id"
            class="font-medium text-accent-800 hover:underline dark:text-accent-300 disabled:opacity-50 disabled:no-underline"
            :title="demoMode ? $t('common.demoDisabled') : $t('forms.resyncTitle')"
            @click="onUpdate(f, true)"
          >
            {{ busyId === f.id ? $t('forms.resyncing') : $t('forms.resync') }}
          </button>
        </div>
      </div>
    </div>
    </template>
  </div>
</template>
