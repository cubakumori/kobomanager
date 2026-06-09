<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import api from '../services/api'
import { apiError } from '../stores/auth'
import LeafletMap from '../components/LeafletMap.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const formId = computed(() => Number(route.params.id))

const formName = ref('')
const points = ref([])
const total = ref(0)
const loading = ref(true)
const error = ref('')

// Cada punto → feature de tipo 'point' con el uid para poder navegar al detalle.
const features = computed(() =>
  points.value.map((p) => ({
    kind: 'point',
    points: [[p.lat, p.lng]],
    label: p.submitted_at,
    uid: p.submission_uid,
  })),
)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get(`/forms/${formId.value}/map`)
    formName.value = data.data.form.name
    points.value = data.data.points
    total.value = data.data.total
  } catch (e) {
    error.value = apiError(e, t('map.loadError'))
  } finally {
    loading.value = false
  }
}

function openSubmission(uid) {
  router.push({ name: 'submission-detail', params: { id: formId.value, subId: uid } })
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header>
      <RouterLink :to="{ name: 'submissions', params: { id: formId } }" class="text-sm text-primary-600 hover:underline">
        {{ $t('map.back') }}
      </RouterLink>
      <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">{{ formName || $t('map.title') }}</h1>
      <p class="mt-1 text-sm text-slate-500">{{ $t('map.count', { shown: points.length, total }) }}</p>
    </header>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900">{{ error }}</div>
    <div v-else-if="loading" class="text-sm text-slate-500">{{ $t('common.loading') }}</div>

    <template v-else>
      <LeafletMap v-if="points.length" :features="features" height="70vh" @select="openSubmission" />
      <p v-else class="rounded-xl bg-white px-5 py-8 text-center text-sm text-slate-400 ring-1 ring-slate-200">
        {{ $t('map.empty') }}
      </p>
    </template>
  </div>
</template>
