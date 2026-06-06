<script setup>
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import api from '../services/api'
import { apiError } from '../stores/auth'

const { t } = useI18n()
const forms = ref([])
const loading = ref(true)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/forms')
    forms.value = data.data
  } catch (e) {
    error.value = apiError(e, t('myForms.loadError'))
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $t('myForms.title') }}</h1>
      <p class="mt-1 text-sm text-slate-500">{{ $t('myForms.subtitle') }}</p>
    </header>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">
      {{ error }}
    </div>
    <div v-else-if="loading" class="text-sm text-slate-500">{{ $t('common.loading') }}</div>

    <div v-else-if="!forms.length" class="rounded-xl bg-white p-6 text-sm text-slate-400 shadow-sm ring-1 ring-slate-200">
      {{ $t('myForms.empty') }}
    </div>

    <div v-else class="grid gap-4 sm:grid-cols-2">
      <RouterLink
        v-for="f in forms"
        :key="f.id"
        :to="{ name: 'submissions', params: { id: f.id } }"
        class="rounded-xl bg-accent-50 p-5 shadow-sm ring-1 ring-accent-200 transition hover:ring-accent-400"
      >
        <p class="text-xs uppercase tracking-wider text-accent-600">{{ f.account_label }}</p>
        <h2 class="mt-1 font-semibold text-accent-900">{{ f.name }}</h2>
        <p class="mt-2 text-sm text-accent-900/70">{{ $t('myForms.count', { n: f.submission_count }) }}</p>
      </RouterLink>
    </div>
  </div>
</template>
