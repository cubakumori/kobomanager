<script setup>
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../services/api'
import { useAuthStore, apiError } from '../stores/auth'
import { setLocale } from '../i18n'
import { confirmDialog } from '../composables/confirm'

const { t } = useI18n()
const auth = useAuthStore()

const error = ref('')

// Sesiones activas
const sessions = ref([])
const sessLoading = ref(false)
const sessClosing = ref(false)
const sessError = ref('')
const sessClosedMsg = ref('')

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
  error.value = ''
  try {
    const { data } = await api.get('/profile')
    localePref.value = data.data.locale_pref ?? ''
    defaultLocale.value = data.data.default_locale
    validLocales.value = data.data.valid_locales
  } catch (e) {
    error.value = apiError(e, t('profile.loadError'))
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

async function loadSessions() {
  sessLoading.value = true
  sessError.value = ''
  try {
    const { data } = await api.get('/profile/sessions')
    sessions.value = data.data
  } catch (e) {
    sessError.value = apiError(e, t('profile.sessLoadError'))
  } finally {
    sessLoading.value = false
  }
}

async function closeOtherSessions() {
  const ok = await confirmDialog({
    title: t('profile.sessRevoke'),
    message: t('profile.sessConfirm'),
    confirmText: t('profile.sessRevoke'),
    danger: true,
  })
  if (!ok) return
  sessClosing.value = true
  sessError.value = ''
  sessClosedMsg.value = ''
  try {
    const { data } = await api.delete('/profile/sessions')
    sessClosedMsg.value = t('profile.sessClosed', { n: data.data.closed })
    await loadSessions()
  } catch (e) {
    sessError.value = apiError(e, t('profile.sessError'))
  } finally {
    sessClosing.value = false
  }
}

function fmtDate(s) {
  if (!s) return '—'
  const d = new Date(s.replace(' ', 'T'))
  return isNaN(d) ? s : d.toLocaleString()
}

onMounted(() => {
  load()
  loadSessions()
})
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
        class="w-72 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
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

      <form class="space-y-4" @submit.prevent="changePassword">
        <div class="space-y-1.5">
          <label class="block text-sm font-medium text-slate-700" for="pw-current">{{ $t('profile.currentPassword') }}</label>
          <input
            id="pw-current"
            v-model="pwCurrent"
            type="password"
            autocomplete="current-password"
            required
            class="w-72 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
          />
        </div>
        <div class="space-y-1.5">
          <label class="block text-sm font-medium text-slate-700" for="pw-new">{{ $t('profile.newPassword') }}</label>
          <input
            id="pw-new"
            v-model="pwNew"
            type="password"
            autocomplete="new-password"
            required
            class="w-72 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
          />
          <p class="text-xs text-slate-400">{{ $t('profile.pwHint') }}</p>
        </div>
        <div class="space-y-1.5">
          <label class="block text-sm font-medium text-slate-700" for="pw-confirm">{{ $t('profile.confirmPassword') }}</label>
          <input
            id="pw-confirm"
            v-model="pwConfirm"
            type="password"
            autocomplete="new-password"
            required
            class="w-72 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
          />
        </div>
        <div class="flex items-center gap-3 pt-1">
          <button
            type="submit"
            :disabled="pwSaving"
            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-60"
          >
            {{ pwSaving ? $t('common.saving') : $t('profile.changePassword') }}
          </button>
          <span v-if="pwSaved" class="text-sm text-success-600">{{ $t('profile.pwChanged') }}</span>
        </div>
      </form>
    </section>

    <!-- Sesiones activas -->
    <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 space-y-3">
      <div>
        <h2 class="font-semibold text-slate-900">{{ $t('profile.sessions') }}</h2>
        <p class="mt-0.5 text-sm text-slate-500">{{ $t('profile.sessionsDesc') }}</p>
      </div>

      <div v-if="sessError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">
        {{ sessError }}
      </div>

      <p v-if="sessLoading" class="text-sm text-slate-400">{{ $t('common.loading') }}</p>

      <ul v-else class="divide-y divide-slate-100 rounded-lg ring-1 ring-slate-200">
        <li v-for="(s, i) in sessions" :key="i" class="flex items-start justify-between gap-4 px-3 py-2.5 text-sm">
          <div class="min-w-0">
            <p class="truncate text-slate-700">{{ s.user_agent || $t('profile.sessUnknownAgent') }}</p>
            <p class="text-xs text-slate-400">
              {{ s.ip || '—' }} · {{ $t('profile.sessLastActivity') }}: {{ fmtDate(s.last_activity) }}
            </p>
          </div>
          <span
            v-if="s.current"
            class="shrink-0 rounded-full bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700 ring-1 ring-primary-200"
          >{{ $t('profile.sessCurrent') }}</span>
        </li>
      </ul>

      <div class="flex items-center gap-3 pt-1">
        <button
          type="button"
          :disabled="sessClosing || sessions.length <= 1"
          class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-60"
          @click="closeOtherSessions"
        >
          {{ sessClosing ? $t('common.saving') : $t('profile.sessRevoke') }}
        </button>
        <span v-if="sessClosedMsg" class="text-sm text-success-600">{{ sessClosedMsg }}</span>
      </div>
    </section>
  </div>
</template>
