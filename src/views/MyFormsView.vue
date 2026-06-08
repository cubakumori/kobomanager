<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import api from '../services/api'
import { useAuthStore, apiError } from '../stores/auth'
import { confirmDialog } from '../composables/confirm'

const { t } = useI18n()
const auth = useAuthStore()

const forms = ref([])
const loading = ref(true)
const error = ref('')

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

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">
      {{ error }}
    </div>
    <div v-if="actionError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">
      {{ actionError }}
    </div>
    <div v-if="flash" class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800 ring-1 ring-green-200">
      {{ flash }}
    </div>

    <div v-if="loading" class="text-sm text-slate-500">{{ $t('common.loading') }}</div>

    <div v-else-if="!forms.length" class="rounded-xl bg-white p-6 text-sm text-slate-400 shadow-sm ring-1 ring-slate-200">
      {{ $t('myForms.empty') }}
    </div>

    <div v-else class="grid gap-4 sm:grid-cols-2">
      <div
        v-for="f in forms"
        :key="f.id"
        class="flex flex-col rounded-xl bg-accent-50 p-5 shadow-sm ring-1 ring-accent-200 transition hover:ring-accent-400"
      >
        <RouterLink :to="{ name: 'submissions', params: { id: f.id } }" class="block">
          <p class="text-xs uppercase tracking-wider text-accent-600">{{ f.account_label }}</p>
          <h2 class="mt-1 font-semibold text-accent-900">{{ f.name }}</h2>
          <p v-if="f.submissions_synced === false" class="mt-2 text-sm italic text-accent-900/50">{{ $t('myForms.notSynced') }}</p>
          <p v-else class="mt-2 text-sm text-accent-900/70">{{ $t('myForms.count', { n: f.submission_count }) }}</p>
        </RouterLink>

        <div v-if="anyAction" class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-accent-200/70 pt-3 text-sm">
          <RouterLink
            :to="{ name: 'submissions', params: { id: f.id } }"
            class="font-medium text-accent-800 hover:underline"
          >
            {{ $t('myForms.viewSubmissions') }}
          </RouterLink>
          <button
            v-if="can('enketo') && f.deployment_status === 'deployed'"
            :disabled="enketoId === f.id"
            class="font-medium text-accent-800 hover:underline disabled:opacity-50"
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
            class="font-medium text-accent-800 hover:underline"
            :title="$t('forms.loginTitle')"
          >
            {{ $t('forms.login') }}
          </a>
          <button
            v-if="can('update')"
            :disabled="busyId === f.id"
            class="font-medium text-accent-800 hover:underline disabled:opacity-50"
            :title="$t('forms.updateTitle')"
            @click="onUpdate(f, false)"
          >
            {{ busyId === f.id ? $t('forms.updating') : $t('forms.update') }}
          </button>
          <button
            v-if="can('resync')"
            :disabled="busyId === f.id"
            class="font-medium text-accent-800 hover:underline disabled:opacity-50"
            :title="$t('forms.resyncTitle')"
            @click="onUpdate(f, true)"
          >
            {{ busyId === f.id ? $t('forms.resyncing') : $t('forms.resync') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
