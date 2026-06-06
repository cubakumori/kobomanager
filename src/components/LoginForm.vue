<script setup>
import { ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '../services/api'
import { useAuthStore, apiError } from '../stores/auth'

const emit = defineEmits(['success'])
const { t } = useI18n()
const auth = useAuthStore()

const email = ref('')
const password = ref('')
const error = ref('')
const loading = ref(false)
const resetEnabled = ref(false)

// Mostrar el enlace «¿Olvidaste tu contraseña?» solo si el admin lo habilitó.
onMounted(async () => {
  try {
    const { data } = await api.get('/config')
    resetEnabled.value = !!data.data.password_reset_enabled
  } catch {
    resetEnabled.value = false
  }
})

async function onSubmit() {
  error.value = ''
  loading.value = true
  try {
    const user = await auth.login(email.value, password.value)
    emit('success', user)
  } catch (e) {
    error.value = apiError(e, t('login.failed'))
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <form class="space-y-5" @submit.prevent="onSubmit">
    <div
      v-if="error"
      class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200"
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
