<script setup>
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../services/api'
import { apiError, useAuthStore } from '../stores/auth'

const { t } = useI18n()
const auth = useAuthStore()

const FREQUENCIES = ['off', 'daily', 'hourly', 'every_sync']

const forms = ref([]) // [{ form_id, name, account_label, frequency }]
const defaultFrequency = ref('off') // ajuste global: frecuencia por defecto en formularios sin preferencia
const quiet = ref(null) // horario de silencio global { start, end } | null
const loading = ref(true)
const error = ref('')
const saving = ref(false)
const saved = ref(false)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/notifications')
    forms.value = data.data.forms
    defaultFrequency.value = data.data.default_frequency
    quiet.value = data.data.quiet
  } catch (e) {
    error.value = apiError(e, t('notifications.loadError'))
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  saved.value = false
  error.value = ''
  try {
    const frequencies = Object.fromEntries(forms.value.map((f) => [f.form_id, f.frequency]))
    await api.put('/notifications', { frequencies })
    saved.value = true
  } catch (e) {
    error.value = apiError(e, t('notifications.saveError'))
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $t('notifications.title') }}</h1>
      <p class="mt-1 text-sm text-slate-500">{{ $t('notifications.subtitle') }}</p>
    </header>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900">
      {{ error }}
    </div>

    <!-- Avisos de envíos nuevos por email -->
    <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
      <div class="border-b border-slate-100 px-5 py-3">
        <h2 class="font-semibold text-slate-900">{{ $t('notifications.emailAlerts') }}</h2>
        <p class="mt-0.5 text-sm text-slate-500">{{ $t('notifications.emailAlertsDesc') }}</p>
        <p class="mt-1 text-xs text-slate-400">{{ $t('notifications.sentTo', { email: auth.user?.email }) }}</p>
      </div>

      <div v-if="loading" class="px-5 py-4 text-sm text-slate-500">{{ $t('common.loading') }}</div>

      <template v-else>
        <p v-if="defaultFrequency !== 'off'" class="border-b border-slate-100 bg-slate-50 px-5 py-2 text-xs text-slate-500">
          {{ $t('notifications.defaultOnHint', { frequency: $t('notifications.freq_' + defaultFrequency) }) }}
        </p>
        <p v-if="quiet" class="border-b border-slate-100 bg-slate-50 px-5 py-2 text-xs text-slate-500">
          {{ $t('notifications.quietHint', { start: quiet.start, end: quiet.end }) }}
        </p>
        <ul class="divide-y divide-slate-100">
          <li v-for="f in forms" :key="f.form_id" class="flex items-center justify-between gap-3 px-5 py-3">
            <div class="min-w-0">
              <p class="truncate text-sm font-medium text-slate-900">{{ f.name }}</p>
              <p class="truncate text-xs text-slate-400">{{ f.account_label }}</p>
            </div>
            <select
              v-model="f.frequency"
              class="shrink-0 rounded-lg border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
              @change="saved = false"
            >
              <option v-for="freq in FREQUENCIES" :key="freq" :value="freq">{{ $t('notifications.freq_' + freq) }}</option>
            </select>
          </li>
          <li v-if="!forms.length" class="px-5 py-6 text-center text-sm text-slate-400">
            {{ $t('notifications.noForms') }}
          </li>
        </ul>

        <div v-if="forms.length" class="flex items-center gap-3 border-t border-slate-100 px-5 py-4">
          <button
            :disabled="saving"
            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-60"
            @click="save"
          >
            {{ saving ? $t('common.saving') : $t('notifications.save') }}
          </button>
          <span v-if="saved" class="text-sm text-success-600 dark:text-success-400">{{ $t('common.saved') }}</span>
        </div>
      </template>
    </section>
  </div>
</template>
