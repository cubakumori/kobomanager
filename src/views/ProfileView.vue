<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import QRCode from 'qrcode'
import api from '../services/api'
import { useAuthStore, apiError } from '../stores/auth'
import { setLocale } from '../i18n'
import { confirmDialog } from '../composables/confirm'
import { useDarkMode } from '../composables/darkMode'
import { useDemoMode, usePushConfig, configReady } from '../composables/appConfig'

const { t } = useI18n()
const auth = useAuthStore()
// En demo, cambiar la contraseña y cerrar sesiones están bloqueados (el
// usuario demo es compartido entre visitantes); el idioma sí se puede cambiar.
const { demoMode } = useDemoMode()

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

// Tema (claro/oscuro/auto) — preferencia de ESTE dispositivo (localStorage),
// '' = seguir el «Tema por defecto» del sitio.
const { pref: darkPref, showToggle, setPref: setThemePref } = useDarkMode()
const themePref = ref(darkPref.value ?? '')
function changeTheme() {
  setThemePref(themePref.value || null)
}

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

// ── Notificaciones push (opt-in POR DISPOSITIVO; requiere claves VAPID en el
// servidor — pushPublicKey vacía = la sección ni se muestra). Qué se avisa y con
// qué frecuencia lo decide la página Notificaciones (misma preferencia que el email).
const { pushPublicKey } = usePushConfig()
const pushSupported =
  typeof navigator !== 'undefined' && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
const pushDevices = ref([])
const pushBusy = ref(false)
const pushError = ref('')
const pushDenied = ref(false)
const thisEndpointHash = ref('') // sha256 del endpoint de ESTE navegador (si está suscrito)
const pushOnHere = computed(() => !!thisEndpointHash.value && pushDevices.value.some((d) => d.endpoint_hash === thisEndpointHash.value))

async function sha256hex(text) {
  const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(text))
  return Array.from(new Uint8Array(buf), (b) => b.toString(16).padStart(2, '0')).join('')
}

// applicationServerKey: base64url → Uint8Array (lo exige pushManager.subscribe).
function vapidKeyBytes(b64u) {
  const b64 = (b64u + '='.repeat((4 - (b64u.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/')
  return Uint8Array.from(atob(b64), (c) => c.charCodeAt(0))
}

// Etiqueta legible del dispositivo (best-effort desde el user-agent).
function deviceLabel() {
  const ua = navigator.userAgent
  const browser = (ua.match(/(Edg|OPR|Firefox|Chrome|Safari)\/[\d.]+/) || ['—'])[0].split('/')[0]
  const os = /Android/.test(ua) ? 'Android' : /iPhone|iPad/.test(ua) ? 'iOS' : /Mac/.test(ua) ? 'macOS' : /Windows/.test(ua) ? 'Windows' : /Linux/.test(ua) ? 'Linux' : ''
  return [browser === 'Edg' ? 'Edge' : browser === 'OPR' ? 'Opera' : browser, os].filter(Boolean).join(' · ')
}

async function loadPush() {
  if (!pushPublicKey.value || !pushSupported) return
  pushError.value = ''
  try {
    const { data } = await api.get('/push/subscriptions')
    pushDevices.value = data.data.subscriptions
    // getRegistration (no .ready): en dev el SW no se registra y .ready no resuelve nunca.
    const reg = await navigator.serviceWorker.getRegistration()
    const sub = reg ? await reg.pushManager.getSubscription() : null
    thisEndpointHash.value = sub ? await sha256hex(sub.endpoint) : ''
    pushDenied.value = Notification.permission === 'denied'
  } catch (e) {
    pushError.value = apiError(e, t('profile.pushLoadError'))
  }
}

async function enablePush() {
  pushBusy.value = true
  pushError.value = ''
  try {
    const perm = await Notification.requestPermission()
    pushDenied.value = perm === 'denied'
    if (perm !== 'granted') return
    const reg = await navigator.serviceWorker.getRegistration()
    if (!reg) {
      pushError.value = t('profile.pushUnsupported')
      return
    }
    const sub =
      (await reg.pushManager.getSubscription()) ||
      (await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: vapidKeyBytes(pushPublicKey.value),
      }))
    const json = sub.toJSON()
    await api.post('/push/subscriptions', {
      endpoint: sub.endpoint,
      keys: { p256dh: json.keys.p256dh, auth: json.keys.auth },
      label: deviceLabel(),
    })
    await loadPush()
  } catch (e) {
    pushError.value = apiError(e, t('profile.pushError'))
  } finally {
    pushBusy.value = false
  }
}

async function disablePush() {
  pushBusy.value = true
  pushError.value = ''
  try {
    const reg = await navigator.serviceWorker.getRegistration()
    const sub = reg ? await reg.pushManager.getSubscription() : null
    if (sub) {
      await api.delete('/push/subscriptions', { data: { endpoint: sub.endpoint } })
      await sub.unsubscribe()
    }
    await loadPush()
  } catch (e) {
    pushError.value = apiError(e, t('profile.pushError'))
  } finally {
    pushBusy.value = false
  }
}

async function removePushDevice(id) {
  pushError.value = ''
  try {
    await api.delete('/push/subscriptions', { data: { id } })
    await loadPush()
  } catch (e) {
    pushError.value = apiError(e, t('profile.pushError'))
  }
}

// ── Segundo factor (TOTP) ──
// Estado del GET /profile/totp + máquina de enrolamiento: init (QR + secreto
// manual) → confirm (código) → códigos de recuperación mostrados UNA sola vez.
const route = useRoute()
const totp = ref(null)              // { enabled, pending, recovery_left, required_by_policy }
const totpError = ref('')
const totpBusy = ref(false)
const totpSecret = ref('')          // enrolamiento en curso (secreto manual)
const totpQr = ref('')              // dataURL del QR
const totpConfirmCode = ref('')
const recoveryCodes = ref([])       // en claro solo tras confirmar; luego jamás
const totpDisableCode = ref('')
// Llegada forzada por la política (?totp=required): aviso destacado en la tarjeta.
const totpForced = computed(() => route.query.totp === 'required' || !!(totp.value?.required_by_policy && !totp.value?.enabled))

async function loadTotp() {
  try {
    const { data } = await api.get('/profile/totp')
    totp.value = data.data
  } catch (e) {
    totpError.value = apiError(e, t('profile.totpError'))
  }
}

async function totpStart() {
  totpBusy.value = true
  totpError.value = ''
  recoveryCodes.value = []
  try {
    const { data } = await api.post('/profile/totp/init')
    totpSecret.value = data.data.secret
    totpQr.value = await QRCode.toDataURL(data.data.otpauth_uri, { width: 220, margin: 1 })
    totpConfirmCode.value = ''
  } catch (e) {
    totpError.value = apiError(e, t('profile.totpError'))
  } finally {
    totpBusy.value = false
  }
}

async function totpConfirm() {
  totpBusy.value = true
  totpError.value = ''
  try {
    const { data } = await api.post('/profile/totp/confirm', { code: totpConfirmCode.value })
    recoveryCodes.value = data.data.recovery_codes
    totpSecret.value = ''
    totpQr.value = ''
    totpConfirmCode.value = ''
    if (auth.user) auth.user.totp_enabled = true
    await loadTotp()
  } catch (e) {
    totpError.value = apiError(e, t('profile.totpBadCode'))
  } finally {
    totpBusy.value = false
  }
}

async function totpCancel() {
  totpError.value = ''
  try {
    await api.delete('/profile/totp') // pendiente: se cancela sin código
    totpSecret.value = ''
    totpQr.value = ''
    await loadTotp()
  } catch (e) {
    totpError.value = apiError(e, t('profile.totpError'))
  }
}

async function totpDisable() {
  const ok = await confirmDialog({
    title: t('profile.totpDisable'),
    message: t('profile.totpDisableConfirm'),
    confirmText: t('profile.totpDisable'),
    danger: true,
  })
  if (!ok) return
  totpBusy.value = true
  totpError.value = ''
  try {
    await api.delete('/profile/totp', { data: { code: totpDisableCode.value } })
    totpDisableCode.value = ''
    recoveryCodes.value = []
    if (auth.user) auth.user.totp_enabled = false
    await loadTotp()
  } catch (e) {
    totpError.value = apiError(e, t('profile.totpBadCode'))
  } finally {
    totpBusy.value = false
  }
}

onMounted(() => {
  load()
  loadSessions()
  loadTotp()
  // El push depende de la clave pública de /config: esperar a que esté cargada.
  configReady.then(loadPush)
})
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $t('profile.title') }}</h1>
      <p class="mt-1 text-sm text-slate-500">{{ auth.user?.name }} · {{ auth.user?.email }}</p>
    </header>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900">
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

    <!-- Tema (claro/oscuro) — preferencia por dispositivo, no viaja con la cuenta -->
    <section v-if="showToggle" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 space-y-3">
      <div>
        <h2 class="font-semibold text-slate-900">{{ $t('profile.theme') }}</h2>
        <p class="mt-0.5 text-sm text-slate-500">{{ $t('profile.themeDesc') }}</p>
      </div>
      <select
        v-model="themePref"
        class="w-72 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
        @change="changeTheme"
      >
        <option value="">{{ $t('profile.themeSiteDefault') }}</option>
        <option value="light">{{ $t('common.theme_light') }}</option>
        <option value="dark">{{ $t('common.theme_dark') }}</option>
        <option value="auto">{{ $t('common.theme_auto') }}</option>
      </select>
    </section>

    <!-- Notificaciones push (solo si el servidor tiene claves VAPID) -->
    <section v-if="pushPublicKey" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 space-y-3">
      <div>
        <h2 class="font-semibold text-slate-900">{{ $t('profile.push') }}</h2>
        <p class="mt-0.5 text-sm text-slate-500">{{ $t('profile.pushDesc') }}</p>
      </div>

      <div v-if="pushError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900">
        {{ pushError }}
      </div>

      <p v-if="!pushSupported" class="text-sm text-slate-400">{{ $t('profile.pushUnsupported') }}</p>
      <p v-else-if="pushDenied" class="text-sm text-slate-400">{{ $t('profile.pushDenied') }}</p>

      <div v-if="pushSupported" class="flex items-center gap-3">
        <button
          v-if="!pushOnHere"
          type="button"
          :disabled="pushBusy || pushDenied"
          class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-60"
          @click="enablePush"
        >
          {{ pushBusy ? $t('common.saving') : $t('profile.pushEnable') }}
        </button>
        <button
          v-else
          type="button"
          :disabled="pushBusy"
          class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-60"
          @click="disablePush"
        >
          {{ pushBusy ? $t('common.saving') : $t('profile.pushDisable') }}
        </button>
        <span v-if="pushOnHere" class="text-sm text-success-600 dark:text-success-400">{{ $t('profile.pushOnHere') }}</span>
      </div>

      <ul v-if="pushDevices.length" class="divide-y divide-slate-100 rounded-lg ring-1 ring-slate-200">
        <li v-for="d in pushDevices" :key="d.id" class="flex items-start justify-between gap-4 px-3 py-2.5 text-sm">
          <div class="min-w-0">
            <p class="truncate text-slate-700">
              {{ d.label || $t('profile.pushUnknownDevice') }}
              <span
                v-if="d.endpoint_hash === thisEndpointHash"
                class="ml-1 rounded-full bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700 ring-1 ring-primary-200 dark:bg-primary-900/30 dark:text-primary-300 dark:ring-primary-800"
              >{{ $t('profile.pushThisDevice') }}</span>
            </p>
            <p class="text-xs text-slate-400">
              {{ $t('profile.pushSince') }}: {{ fmtDate(d.created_at) }}
              <template v-if="d.last_used_at"> · {{ $t('profile.pushLastUsed') }}: {{ fmtDate(d.last_used_at) }}</template>
            </p>
          </div>
          <button
            type="button"
            class="shrink-0 text-xs font-medium text-red-600 hover:underline dark:text-red-400"
            @click="removePushDevice(d.id)"
          >
            {{ $t('profile.pushRemove') }}
          </button>
        </li>
      </ul>
      <p class="text-xs text-slate-400">{{ $t('profile.pushHint') }}</p>
    </section>

    <!-- Contraseña -->
    <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 space-y-3">
      <div>
        <h2 class="font-semibold text-slate-900">{{ $t('profile.password') }}</h2>
        <p class="mt-0.5 text-sm text-slate-500">{{ $t('profile.passwordDesc') }}</p>
      </div>

      <div v-if="pwError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900">
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
            :disabled="demoMode || pwSaving"
            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-60"
            :title="demoMode ? $t('common.demoDisabled') : undefined"
          >
            {{ pwSaving ? $t('common.saving') : $t('profile.changePassword') }}
          </button>
          <span v-if="pwSaved" class="text-sm text-success-600 dark:text-success-400">{{ $t('profile.pwChanged') }}</span>
        </div>
      </form>
    </section>

    <!-- Segundo factor (TOTP) -->
    <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 space-y-3">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('profile.totp') }}</h2>
          <p class="mt-0.5 text-sm text-slate-500">{{ $t('profile.totpDesc') }}</p>
        </div>
        <span
          v-if="totp"
          class="rounded-full px-2.5 py-0.5 text-xs font-medium"
          :class="totp.enabled
            ? 'bg-success-50 text-success-700 ring-1 ring-success-200 dark:bg-success-900/30 dark:text-success-300 dark:ring-success-800'
            : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'"
        >
          {{ totp.enabled ? $t('profile.totpOn') : $t('profile.totpOff') }}
        </span>
      </div>

      <!-- La política de la instancia exige 2FA a esta cuenta -->
      <div v-if="totpForced && totp && !totp.enabled" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900">
        {{ $t('profile.totpRequiredByPolicy') }}
      </div>

      <div v-if="totpError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900">
        {{ totpError }}
      </div>

      <!-- Códigos de recuperación: SE MUESTRAN UNA SOLA VEZ tras activar -->
      <div v-if="recoveryCodes.length" class="rounded-lg bg-amber-50 p-4 ring-1 ring-amber-200 dark:bg-amber-950/40 dark:ring-amber-900">
        <p class="text-sm font-semibold text-amber-800 dark:text-amber-300">{{ $t('profile.totpRecoveryTitle') }}</p>
        <p class="mt-1 text-xs text-amber-700 dark:text-amber-400">{{ $t('profile.totpRecoveryHint') }}</p>
        <div class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 font-mono text-sm text-slate-800 dark:text-slate-200 sm:grid-cols-4">
          <span v-for="c in recoveryCodes" :key="c">{{ c }}</span>
        </div>
      </div>

      <template v-if="totp">
        <!-- Sin 2FA y sin enrolamiento en curso: botón de arranque -->
        <div v-if="!totp.enabled && !totpQr" class="flex items-center gap-3">
          <button
            type="button"
            :disabled="demoMode || totpBusy"
            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-60"
            :title="demoMode ? $t('common.demoDisabled') : undefined"
            @click="totpStart"
          >
            {{ totpBusy ? $t('common.saving') : $t('profile.totpEnable') }}
          </button>
        </div>

        <!-- Enrolamiento en curso: QR + secreto manual + confirmación -->
        <div v-else-if="!totp.enabled && totpQr" class="space-y-3">
          <p class="text-sm text-slate-600">{{ $t('profile.totpScanHint') }}</p>
          <div class="flex flex-wrap items-start gap-5">
            <img :src="totpQr" alt="QR" class="h-44 w-44 rounded-lg ring-1 ring-slate-200" />
            <div class="space-y-2 text-sm">
              <p class="text-slate-500">{{ $t('profile.totpManual') }}</p>
              <code class="block w-fit rounded bg-slate-100 px-2 py-1 font-mono text-xs tracking-wider text-slate-800 dark:bg-slate-800 dark:text-slate-200">{{ totpSecret }}</code>
            </div>
          </div>
          <form class="flex flex-wrap items-center gap-3" @submit.prevent="totpConfirm">
            <input
              v-model="totpConfirmCode"
              type="text"
              inputmode="numeric"
              autocomplete="one-time-code"
              required
              :placeholder="$t('profile.totpCodePlaceholder')"
              class="w-40 rounded-lg border border-slate-300 px-3 py-2 text-center text-sm tracking-widest outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
            />
            <button
              type="submit"
              :disabled="totpBusy || !totpConfirmCode"
              class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-60"
            >
              {{ totpBusy ? $t('common.saving') : $t('profile.totpConfirm') }}
            </button>
            <button type="button" class="text-sm font-medium text-slate-500 hover:underline" @click="totpCancel">
              {{ $t('common.cancel') }}
            </button>
          </form>
        </div>

        <!-- 2FA activo: recovery restantes + desactivar (si la política lo permite) -->
        <div v-else class="space-y-3">
          <p class="text-sm text-slate-500">{{ $t('profile.totpRecoveryLeft', { n: totp.recovery_left }) }}</p>
          <p v-if="totp.required_by_policy" class="text-sm text-slate-400">{{ $t('profile.totpCannotDisable') }}</p>
          <form v-else class="flex flex-wrap items-center gap-3" @submit.prevent="totpDisable">
            <input
              v-model="totpDisableCode"
              type="text"
              inputmode="numeric"
              autocomplete="one-time-code"
              :placeholder="$t('profile.totpCodePlaceholder')"
              class="w-40 rounded-lg border border-slate-300 px-3 py-2 text-center text-sm tracking-widest outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
            />
            <button
              type="submit"
              :disabled="demoMode || totpBusy || !totpDisableCode"
              class="rounded-lg border border-red-300 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 disabled:opacity-60 dark:border-red-800 dark:text-red-400"
              :title="demoMode ? $t('common.demoDisabled') : undefined"
            >
              {{ totpBusy ? $t('common.saving') : $t('profile.totpDisable') }}
            </button>
          </form>
        </div>
      </template>
    </section>

    <!-- Sesiones activas -->
    <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 space-y-3">
      <div>
        <h2 class="font-semibold text-slate-900">{{ $t('profile.sessions') }}</h2>
        <p class="mt-0.5 text-sm text-slate-500">{{ $t('profile.sessionsDesc') }}</p>
      </div>

      <div v-if="sessError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900">
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
            class="shrink-0 rounded-full bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700 ring-1 ring-primary-200 dark:bg-primary-900/30 dark:text-primary-300 dark:ring-primary-800"
          >{{ $t('profile.sessCurrent') }}</span>
        </li>
      </ul>

      <div class="flex items-center gap-3 pt-1">
        <button
          type="button"
          :disabled="demoMode || sessClosing || sessions.length <= 1"
          class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 dark:bg-red-700 dark:hover:bg-red-600 disabled:opacity-60"
          :title="demoMode ? $t('common.demoDisabled') : undefined"
          @click="closeOtherSessions"
        >
          {{ sessClosing ? $t('common.saving') : $t('profile.sessRevoke') }}
        </button>
        <span v-if="sessClosedMsg" class="text-sm text-success-600 dark:text-success-400">{{ sessClosedMsg }}</span>
      </div>
    </section>
  </div>
</template>
