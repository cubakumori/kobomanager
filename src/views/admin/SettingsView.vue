<script setup>
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../../services/api'
import { apiError } from '../../stores/auth'
import { useAuthStore } from '../../stores/auth'
import { setLocale } from '../../i18n'

const { t } = useI18n()
const auth = useAuthStore()

const STATUS_KEYS = ['deployed', 'draft', 'archived']

const selected = ref([])
const defaultLocale = ref('es')
const validLocales = ref(['es', 'en'])
const labelMode = ref('labels')
const validLabelModes = ref(['labels', 'raw'])
const passwordResetEnabled = ref(false)
const mailConfigured = ref(false)
const viewerActions = ref({ enketo: false, update: false, resync: false, login: false })
const VIEWER_ACTION_KEYS = ['enketo', 'update', 'resync', 'login']
const sharePasswordPolicy = ref('optional')
const validSharePolicies = ref(['off', 'optional', 'required'])
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const saved = ref(false)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/admin/settings')
    selected.value = data.data.sync_deployment_statuses
    defaultLocale.value = data.data.default_locale
    validLocales.value = data.data.valid_locales
    labelMode.value = data.data.label_mode
    validLabelModes.value = data.data.valid_label_modes
    passwordResetEnabled.value = data.data.password_reset_enabled
    mailConfigured.value = data.data.mail_configured
    if (data.data.viewer_actions) viewerActions.value = data.data.viewer_actions
    sharePasswordPolicy.value = data.data.share_password_policy
    if (data.data.valid_share_password_policies) validSharePolicies.value = data.data.valid_share_password_policies
  } catch (e) {
    error.value = apiError(e, t('settings.loadError'))
  } finally {
    loading.value = false
  }
}

function toggle(value) {
  saved.value = false
  const i = selected.value.indexOf(value)
  if (i === -1) selected.value.push(value)
  else selected.value.splice(i, 1)
}

async function save() {
  if (!selected.value.length) {
    error.value = t('settings.selectOne')
    return
  }
  saving.value = true
  error.value = ''
  saved.value = false
  try {
    const { data } = await api.put('/admin/settings', {
      sync_deployment_statuses: selected.value,
      default_locale: defaultLocale.value,
      label_mode: labelMode.value,
      password_reset_enabled: passwordResetEnabled.value,
      viewer_actions: viewerActions.value,
      share_password_policy: sharePasswordPolicy.value,
    })
    selected.value = data.data.sync_deployment_statuses
    defaultLocale.value = data.data.default_locale
    labelMode.value = data.data.label_mode
    passwordResetEnabled.value = data.data.password_reset_enabled
    if (data.data.viewer_actions) viewerActions.value = data.data.viewer_actions
    if (data.data.share_password_policy) sharePasswordPolicy.value = data.data.share_password_policy
    saved.value = true
    // Si el usuario sigue el idioma por defecto, refleja el cambio al instante.
    if (!auth.user?.locale_pref) setLocale(defaultLocale.value)
  } catch (e) {
    error.value = apiError(e, t('settings.saveError'))
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $t('settings.title') }}</h1>
      <p class="mt-1 text-sm text-slate-500">{{ $t('settings.subtitle') }}</p>
    </header>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">
      {{ error }}
    </div>
    <div v-if="loading" class="text-sm text-slate-500">{{ $t('common.loading') }}</div>

    <template v-else>
      <!-- Tipos a sincronizar -->
      <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-4">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('settings.syncTypes') }}</h2>
          <p class="mt-0.5 text-sm text-slate-500">{{ $t('settings.syncTypesDesc') }}</p>
        </div>
        <label
          v-for="key in STATUS_KEYS"
          :key="key"
          class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 hover:bg-slate-50"
        >
          <input
            type="checkbox"
            class="mt-0.5 h-4 w-4"
            :checked="selected.includes(key)"
            @change="toggle(key)"
          />
          <span>
            <span class="block text-sm font-medium text-slate-800">{{ $t('settings.' + key) }}</span>
            <span class="block text-xs text-slate-400">{{ $t('settings.' + key + 'Hint') }}</span>
          </span>
        </label>
        <p class="text-xs text-slate-400">{{ $t('settings.syncDefault') }}</p>
      </section>

      <!-- Idioma por defecto -->
      <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-3">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('settings.language') }}</h2>
          <p class="mt-0.5 text-sm text-slate-500">{{ $t('settings.languageDesc') }}</p>
        </div>
        <select
          v-model="defaultLocale"
          class="w-56 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
          @change="saved = false"
        >
          <option v-for="l in validLocales" :key="l" :value="l">{{ $t('lang.' + l) }}</option>
        </select>
      </section>

      <!-- Etiquetas en tabla y detalles -->
      <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-4">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('settings.labels') }}</h2>
          <p class="mt-0.5 text-sm text-slate-500">{{ $t('settings.labelsDesc') }}</p>
        </div>
        <label
          v-for="mode in validLabelModes"
          :key="mode"
          class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 hover:bg-slate-50"
        >
          <input
            type="radio"
            class="mt-0.5 h-4 w-4"
            name="label_mode"
            :value="mode"
            :checked="labelMode === mode"
            @change="labelMode = mode; saved = false"
          />
          <span>
            <span class="block text-sm font-medium text-slate-800">{{ $t('settings.labelMode_' + mode) }}</span>
            <span class="block text-xs text-slate-400">{{ $t('settings.labelMode_' + mode + 'Hint') }}</span>
          </span>
        </label>
      </section>

      <!-- Recuperación de contraseña -->
      <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-4">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('settings.passwordReset') }}</h2>
          <p class="mt-0.5 text-sm text-slate-500">{{ $t('settings.passwordResetDesc') }}</p>
        </div>
        <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 hover:bg-slate-50">
          <input
            type="checkbox"
            class="mt-0.5 h-4 w-4"
            :checked="passwordResetEnabled"
            @change="passwordResetEnabled = !passwordResetEnabled; saved = false"
          />
          <span>
            <span class="block text-sm font-medium text-slate-800">{{ $t('settings.passwordResetToggle') }}</span>
            <span class="block text-xs text-slate-400">{{ $t('settings.passwordResetHint') }}</span>
          </span>
        </label>
        <p
          v-if="passwordResetEnabled && !mailConfigured"
          class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-amber-200"
        >
          {{ $t('settings.passwordResetNoMail') }}
        </p>
      </section>

      <!-- Contraseña de los enlaces de compartir -->
      <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-4">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('settings.sharePassword') }}</h2>
          <p class="mt-0.5 text-sm text-slate-500">{{ $t('settings.sharePasswordDesc') }}</p>
        </div>
        <label
          v-for="pol in validSharePolicies"
          :key="pol"
          class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 hover:bg-slate-50"
        >
          <input
            type="radio"
            class="mt-0.5 h-4 w-4"
            name="share_password_policy"
            :value="pol"
            :checked="sharePasswordPolicy === pol"
            @change="sharePasswordPolicy = pol; saved = false"
          />
          <span>
            <span class="block text-sm font-medium text-slate-800">{{ $t('settings.sharePolicy_' + pol) }}</span>
            <span class="block text-xs text-slate-400">{{ $t('settings.sharePolicy_' + pol + 'Hint') }}</span>
          </span>
        </label>
      </section>

      <!-- Acciones de los viewers sobre formularios -->
      <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-4">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('settings.viewerActions') }}</h2>
          <p class="mt-0.5 text-sm text-slate-500">{{ $t('settings.viewerActionsDesc') }}</p>
        </div>
        <label
          v-for="key in VIEWER_ACTION_KEYS"
          :key="key"
          class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 hover:bg-slate-50"
        >
          <input
            type="checkbox"
            class="mt-0.5 h-4 w-4"
            :checked="viewerActions[key]"
            @change="viewerActions[key] = !viewerActions[key]; saved = false"
          />
          <span>
            <span class="block text-sm font-medium text-slate-800">{{ $t('settings.viewerAction_' + key) }}</span>
            <span class="block text-xs text-slate-400">{{ $t('settings.viewerAction_' + key + 'Hint') }}</span>
          </span>
        </label>
      </section>

      <div class="flex items-center gap-3">
        <button
          :disabled="saving"
          class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-60"
          @click="save"
        >
          {{ saving ? $t('common.saving') : $t('common.save') }}
        </button>
        <span v-if="saved" class="text-sm text-green-600">{{ $t('common.saved') }}</span>
      </div>
    </template>
  </div>
</template>
