<script setup>
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../services/api'
import { useAuthStore, apiError } from '../stores/auth'
import { setLocale } from '../i18n'

const { t } = useI18n()
const auth = useAuthStore()

const forms = ref([]) // [{ form_id, name, account_label, daily_summary }]
const loading = ref(true)
const error = ref('')
const saving = ref(false)
const saved = ref(false)

// Idioma personal
const localePref = ref('')      // '' = seguir el predeterminado del sistema
const defaultLocale = ref('es')
const validLocales = ref(['es', 'en'])
const langSaving = ref(false)

// Cambio de contraseña
const pwCurrent = ref('')
const pwNew = ref('')
const pwConfirm = ref('')
const pwSaving = ref(false)
const pwError = ref('')
const pwSaved = ref(false)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [notif, profile] = await Promise.all([
      api.get('/notifications'),
      api.get('/profile'),
    ])
    forms.value = notif.data.data
    localePref.value = profile.data.data.locale_pref ?? ''
    defaultLocale.value = profile.data.data.default_locale
    validLocales.value = profile.data.data.valid_locales
  } catch (e) {
    error.value = apiError(e, t('profile.loadError'))
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  saved.value = false
  error.value = ''
  try {
    const enabled = forms.value.filter((f) => f.daily_summary).map((f) => f.form_id)
    await api.put('/notifications', { enabled })
    saved.value = true
  } catch (e) {
    error.value = apiError(e, t('profile.saveError'))
  } finally {
    saving.value = false
  }
}

async function changeLocale() {
  langSaving.value = true
  error.value = ''
  try {
    const { data } = await api.put('/profile', { locale: localePref.value || null })
    if (auth.user) {
      auth.user.locale_pref = data.data.locale_pref
      auth.user.locale = data.data.locale
    }
    setLocale(data.data.locale)
  } catch (e) {
    error.value = apiError(e, t('profile.saveError'))
  } finally {
    langSaving.value = false
  }
}

async function changePassword() {
  pwError.value = ''
  pwSaved.value = false
  if (pwNew.value.length < 8) {
    pwError.value = t('profile.pwTooShort')
    return
  }
  if (pwNew.value !== pwConfirm.value) {
    pwError.value = t('profile.pwMismatch')
    return
  }
  pwSaving.value = true
  try {
    await api.post('/profile/password', {
      current_password: pwCurrent.value,
      new_password: pwNew.value,
    })
    pwSaved.value = true
    pwCurrent.value = ''
    pwNew.value = ''
    pwConfirm.value = ''
  } catch (e) {
    pwError.value = apiError(e, t('profile.pwError'))
  } finally {
    pwSaving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $t('profile.title') }}</h1>
      <p class="mt-1 text-sm text-slate-500">{{ auth.user?.name }} · {{ auth.user?.email }}</p>
    </header>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">
      {{ error }}
    </div>

    <!-- Idioma -->
    <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 space-y-3">
      <div>
        <h2 class="font-semibold text-slate-900">{{ $t('profile.language') }}</h2>
        <p class="mt-0.5 text-sm text-slate-500">{{ $t('profile.languageDesc') }}</p>
      </div>
      <select
        v-model="localePref"
        :disabled="langSaving"
        class="w-72 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/30"
        @change="changeLocale"
      >
        <option value="">{{ $t('profile.systemDefault', { locale: $t('lang.' + defaultLocale) }) }}</option>
        <option v-for="l in validLocales" :key="l" :value="l">{{ $t('lang.' + l) }}</option>
      </select>
    </section>

    <!-- Contraseña -->
    <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 space-y-3">
      <div>
        <h2 class="font-semibold text-slate-900">{{ $t('profile.password') }}</h2>
        <p class="mt-0.5 text-sm text-slate-500">{{ $t('profile.passwordDesc') }}</p>
      </div>

      <div v-if="pwError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">
        {{ pwError }}
      </div>

      <form class="space-y-3" @submit.prevent="changePassword">
        <div class="space-y-1">
          <label class="text-sm font-medium text-slate-700" for="pw-current">{{ $t('profile.currentPassword') }}</label>
          <input
            id="pw-current"
            v-model="pwCurrent"
            type="password"
            autocomplete="current-password"
            required
            class="w-72 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/30"
          />
        </div>
        <div class="space-y-1">
          <label class="text-sm font-medium text-slate-700" for="pw-new">{{ $t('profile.newPassword') }}</label>
          <input
            id="pw-new"
            v-model="pwNew"
            type="password"
            autocomplete="new-password"
            required
            class="w-72 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/30"
          />
          <p class="text-xs text-slate-400">{{ $t('profile.pwHint') }}</p>
        </div>
        <div class="space-y-1">
          <label class="text-sm font-medium text-slate-700" for="pw-confirm">{{ $t('profile.confirmPassword') }}</label>
          <input
            id="pw-confirm"
            v-model="pwConfirm"
            type="password"
            autocomplete="new-password"
            required
            class="w-72 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/30"
          />
        </div>
        <div class="flex items-center gap-3 pt-1">
          <button
            type="submit"
            :disabled="pwSaving"
            class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60"
          >
            {{ pwSaving ? $t('common.saving') : $t('profile.changePassword') }}
          </button>
          <span v-if="pwSaved" class="text-sm text-green-600">{{ $t('profile.pwChanged') }}</span>
        </div>
      </form>
    </section>

    <!-- Notificaciones -->
    <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
      <div class="border-b border-slate-100 px-5 py-3">
        <h2 class="font-semibold text-slate-900">{{ $t('profile.notifications') }}</h2>
        <p class="mt-0.5 text-sm text-slate-500">{{ $t('profile.notificationsDesc') }}</p>
      </div>

      <div v-if="loading" class="px-5 py-4 text-sm text-slate-500">{{ $t('common.loading') }}</div>

      <template v-else>
        <ul class="divide-y divide-slate-100">
          <li v-for="f in forms" :key="f.form_id" class="flex items-center justify-between px-5 py-3">
            <div>
              <p class="text-sm font-medium text-slate-900">{{ f.name }}</p>
              <p class="text-xs text-slate-400">{{ f.account_label }}</p>
            </div>
            <input v-model="f.daily_summary" type="checkbox" class="h-4 w-4" @change="saved = false" />
          </li>
          <li v-if="!forms.length" class="px-5 py-6 text-center text-sm text-slate-400">
            {{ $t('profile.noForms') }}
          </li>
        </ul>

        <div v-if="forms.length" class="flex items-center gap-3 border-t border-slate-100 px-5 py-4">
          <button
            :disabled="saving"
            class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60"
            @click="save"
          >
            {{ saving ? $t('common.saving') : $t('profile.savePrefs') }}
          </button>
          <span v-if="saved" class="text-sm text-green-600">{{ $t('common.saved') }}</span>
        </div>
      </template>
    </section>
  </div>
</template>
