<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '../services/api'
import { apiError } from '../stores/auth'
import logo from '../assets/km_logo.png'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const token = typeof route.query.token === 'string' ? route.query.token : ''
const checking = ref(true)
const validToken = ref(false)
const password = ref('')
const confirm = ref('')
const error = ref('')
const saving = ref(false)
const done = ref(false)

// Comprobar el token al cargar para mostrar el formulario o el aviso de enlace inválido.
onMounted(async () => {
  if (!token) {
    validToken.value = false
    checking.value = false
    return
  }
  try {
    const { data } = await api.get('/auth/reset-password', { params: { token } })
    validToken.value = !!data.data.valid
  } catch {
    validToken.value = false
  } finally {
    checking.value = false
  }
})

async function onSubmit() {
  error.value = ''
  if (password.value.length < 8) {
    error.value = t('reset.tooShort')
    return
  }
  if (password.value !== confirm.value) {
    error.value = t('reset.mismatch')
    return
  }
  saving.value = true
  try {
    await api.post('/auth/reset-password', { token, password: password.value })
    done.value = true
    // Tras un momento, llevar al login para entrar con la nueva contraseña.
    setTimeout(() => router.push({ name: 'login' }), 2500)
  } catch (e) {
    // Si el token caducó/usó entre la comprobación y el envío, reflejarlo.
    if (e?.response?.data?.error?.code === 'RESET_TOKEN_INVALID') {
      validToken.value = false
    }
    error.value = apiError(e, t('reset.error'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="grid min-h-screen place-items-center bg-slate-100 px-4 py-10">
    <div class="w-full max-w-sm">
      <RouterLink to="/" class="mx-auto mb-5 block w-fit">
        <img :src="logo" alt="KoboManager" class="h-20 w-20" />
      </RouterLink>

      <div class="space-y-5 rounded-2xl bg-white p-8 shadow-sm ring-1 ring-slate-200">
        <div class="text-center">
          <h1 class="text-xl font-semibold tracking-tight text-slate-900">{{ $t('reset.title') }}</h1>
        </div>

        <p v-if="checking" class="text-center text-sm text-slate-500">{{ $t('common.loading') }}</p>

        <!-- Enlace inválido o caducado -->
        <template v-else-if="!validToken && !done">
          <div class="rounded-lg bg-red-50 px-3 py-3 text-sm text-red-700 ring-1 ring-red-200">
            {{ $t('reset.invalid') }}
          </div>
          <p class="text-center text-sm">
            <RouterLink to="/forgot-password" class="text-primary-600 hover:underline">{{ $t('reset.requestNew') }}</RouterLink>
          </p>
        </template>

        <!-- Éxito -->
        <div
          v-else-if="done"
          class="rounded-lg bg-green-50 px-3 py-3 text-sm text-green-800 ring-1 ring-green-200"
        >
          {{ $t('reset.done') }}
        </div>

        <!-- Formulario -->
        <form v-else class="space-y-5" @submit.prevent="onSubmit">
          <p class="text-sm text-slate-500">{{ $t('reset.subtitle') }}</p>

          <div
            v-if="error"
            class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200"
          >
            {{ error }}
          </div>

          <div class="space-y-1">
            <label class="block text-sm font-medium text-slate-700" for="rp-pass">{{ $t('reset.newPassword') }}</label>
            <input
              id="rp-pass"
              v-model="password"
              type="password"
              autocomplete="new-password"
              required
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
            />
            <p class="text-xs text-slate-400">{{ $t('reset.hint') }}</p>
          </div>

          <div class="space-y-1">
            <label class="block text-sm font-medium text-slate-700" for="rp-confirm">{{ $t('reset.confirm') }}</label>
            <input
              id="rp-confirm"
              v-model="confirm"
              type="password"
              autocomplete="new-password"
              required
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
            />
          </div>

          <button
            type="submit"
            :disabled="saving"
            class="w-full rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-primary-700 disabled:opacity-60"
          >
            {{ saving ? $t('common.saving') : $t('reset.submit') }}
          </button>
        </form>
      </div>
    </div>
  </div>
</template>
