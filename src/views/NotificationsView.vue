<script setup>
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../services/api'
import { apiError } from '../stores/auth'

const { t } = useI18n()

const forms = ref([]) // [{ form_id, name, account_label, daily_summary }]
const loading = ref(true)
const error = ref('')
const saving = ref(false)
const saved = ref(false)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/notifications')
    forms.value = data.data
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
    const enabled = forms.value.filter((f) => f.daily_summary).map((f) => f.form_id)
    await api.put('/notifications', { enabled })
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

    <!-- Resumen diario por email -->
    <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
      <div class="border-b border-slate-100 px-5 py-3">
        <h2 class="font-semibold text-slate-900">{{ $t('notifications.dailySummary') }}</h2>
        <p class="mt-0.5 text-sm text-slate-500">{{ $t('notifications.dailySummaryDesc') }}</p>
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
