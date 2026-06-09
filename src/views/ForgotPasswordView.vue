<script setup>
import { ref } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '../services/api'
import { apiError } from '../stores/auth'
import logo from '../assets/km_logo.png'

const { t } = useI18n()
const router = useRouter()

const email = ref('')
const error = ref('')
const loading = ref(false)
const done = ref(false)

async function onSubmit() {
  error.value = ''
  loading.value = true
  try {
    await api.post('/auth/forgot-password', { email: email.value })
    // Respuesta siempre genérica: confirmamos sin revelar si el email existe.
    done.value = true
  } catch (e) {
    error.value = apiError(e, t('forgot.error'))
  } finally {
    loading.value = false
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
          <h1 class="text-xl font-semibold tracking-tight text-slate-900">{{ $t('forgot.title') }}</h1>
          <p class="mt-1 text-sm text-slate-500">{{ $t('forgot.subtitle') }}</p>
        </div>

        <div
          v-if="done"
          class="rounded-lg bg-success-50 px-3 py-3 text-sm text-success-800 ring-1 ring-success-200 dark:bg-success-900/30 dark:text-success-300 dark:ring-success-800"
        >
          {{ $t('forgot.sent') }}
        </div>

        <form v-else class="space-y-5" @submit.prevent="onSubmit">
          <div
            v-if="error"
            class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900"
          >
            {{ error }}
          </div>

          <div class="space-y-1">
            <label class="block text-sm font-medium text-slate-700" for="fp-email">{{ $t('common.email') }}</label>
            <input
              id="fp-email"
              v-model="email"
              type="email"
              autocomplete="username"
              required
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
            />
          </div>

          <button
            type="submit"
            :disabled="loading"
            class="w-full rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-primary-700 disabled:opacity-60"
          >
            {{ loading ? $t('forgot.sending') : $t('forgot.submit') }}
          </button>
        </form>

        <p class="text-center text-sm">
          <RouterLink to="/login" class="text-primary-600 hover:underline">{{ $t('forgot.backToLogin') }}</RouterLink>
        </p>
      </div>
    </div>
  </div>
</template>
