<script setup>
import { ref, nextTick, onMounted } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '../services/api'
import { useAuthStore, apiError } from '../stores/auth'

const emit = defineEmits(['success'])
const { t } = useI18n()
const auth = useAuthStore()
const router = useRouter()

const email = ref('')
const password = ref('')
const error = ref('')
const loading = ref(false)
const resetEnabled = ref(false)

// Segundo paso (2FA): con el reto emitido por el backend se pide el código TOTP
// (o uno de recuperación) en el MISMO formulario; solo entonces hay sesión.
const challenge = ref('')
const totpCode = ref('')
const totpInput = ref(null)

// Mostrar el enlace «¿Olvidaste tu contraseña?» solo si el admin lo habilitó.
onMounted(async () => {
  try {
    const { data } = await api.get('/config')
    resetEnabled.value = !!data.data.password_reset_enabled
  } catch {
    resetEnabled.value = false
  }
})

function finish(user) {
  // Si la política de la instancia le exige 2FA y no lo tiene, va directo a su
  // perfil a activarlo (la API ya está cortada para todo lo demás).
  if (user?.totp_enroll_required) {
    router.push({ name: 'profile', query: { totp: 'required' } })
    return
  }
  emit('success', user)
}

async function onSubmit() {
  error.value = ''
  loading.value = true
  try {
    const res = await auth.login(email.value, password.value)
    if (res?.totp_required) {
      challenge.value = res.challenge
      totpCode.value = ''
      await nextTick()
      totpInput.value?.focus()
      return
    }
    finish(res)
  } catch (e) {
    error.value = apiError(e, t('login.failed'))
  } finally {
    loading.value = false
  }
}

async function onSubmitTotp() {
  error.value = ''
  loading.value = true
  try {
    finish(await auth.loginTotp(challenge.value, totpCode.value))
  } catch (e) {
    // Reto caducado (>5 min): vuelta al paso de credenciales.
    if (e?.response?.data?.error?.code === 'TOTP_CHALLENGE_EXPIRED') {
      challenge.value = ''
      password.value = ''
    }
    error.value = apiError(e, t('login.totpFailed'))
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <!-- Paso 2 (solo con 2FA activo): código TOTP o de recuperación -->
  <form v-if="challenge" class="space-y-5" @submit.prevent="onSubmitTotp">
    <div
      v-if="error"
      class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900"
    >
      {{ error }}
    </div>

    <div class="space-y-1">
      <label class="block text-sm font-medium text-slate-700" for="lf-totp">{{ $t('login.totpCode') }}</label>
      <input
        id="lf-totp"
        ref="totpInput"
        v-model="totpCode"
        type="text"
        inputmode="numeric"
        autocomplete="one-time-code"
        required
        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-center text-lg tracking-widest outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
      />
      <p class="text-xs text-slate-400">{{ $t('login.totpHint') }}</p>
    </div>

    <button
      type="submit"
      :disabled="loading || !totpCode"
      class="w-full rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-primary-700 disabled:opacity-60"
    >
      {{ loading ? $t('login.submitting') : $t('login.totpSubmit') }}
    </button>

    <p class="text-center text-sm">
      <button type="button" class="text-primary-600 hover:underline" @click="challenge = ''; error = ''">
        {{ $t('login.totpBack') }}
      </button>
    </p>
  </form>

  <form v-else class="space-y-5" @submit.prevent="onSubmit">
    <div
      v-if="error"
      class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900"
    >
      {{ error }}
    </div>

    <div class="space-y-1">
      <label class="block text-sm font-medium text-slate-700" for="lf-email">{{ $t('common.email') }}</label>
      <input
        id="lf-email"
        v-model="email"
        type="email"
        autocomplete="username"
        required
        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
      />
    </div>

    <div class="space-y-1">
      <label class="block text-sm font-medium text-slate-700" for="lf-password">{{ $t('login.password') }}</label>
      <input
        id="lf-password"
        v-model="password"
        type="password"
        autocomplete="current-password"
        required
        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
      />
    </div>

    <button
      type="submit"
      :disabled="loading"
      class="w-full rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-primary-700 disabled:opacity-60"
    >
      {{ loading ? $t('login.submitting') : $t('login.submit') }}
    </button>

    <p v-if="resetEnabled" class="text-center text-sm">
      <RouterLink to="/forgot-password" class="text-primary-600 hover:underline">
        {{ $t('login.forgot') }}
      </RouterLink>
    </p>
  </form>
</template>
