<script setup>
/**
 * Panel de comentarios de revisión de un formulario: reúne los comentarios que ya
 * viven en submission_reviews (hechos en la app o importados de Kobo) y los agrupa
 * por equipo → encuestador, para leer de un vistazo qué se ha comentado sin abrir
 * envío por envío. Solo lectura; lo sirve GET /forms/{id}/comments (lib/Comments).
 */
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, RouterLink } from 'vue-router'
import api from '../services/api'
import { apiError } from '../stores/auth'
import Skeleton from '../components/Skeleton.vue'
import ReviewBadge from '../components/ReviewBadge.vue'

const { t } = useI18n()
const route = useRoute()
const formId = computed(() => Number(route.params.id))

const q = ref(null)
const loading = ref(true)
const error = ref('')

// Filtros (se envían al backend; el texto con un pequeño debounce).
const status = ref('')
const search = ref('')
const REVIEW_STATUSES = ['pending', 'on_hold', 'approved', 'rejected']

// Guard de secuencia: con filtros que se solapan (cambiar estado y teclear a la
// vez), solo la última petición lanzada puede escribir el resultado; las respuestas
// obsoletas se descartan.
let reqSeq = 0
async function load() {
  const seq = ++reqSeq
  loading.value = true
  error.value = ''
  try {
    const params = {}
    if (status.value) params.status = status.value
    if (search.value.trim()) params.search = search.value.trim()
    const { data } = await api.get(`/forms/${formId.value}/comments`, { params })
    if (seq !== reqSeq) return
    q.value = data.data
  } catch (e) {
    if (seq !== reqSeq) return
    error.value = apiError(e, t('comments.loadError'))
  } finally {
    if (seq === reqSeq) loading.value = false
  }
}

// Recarga al cambiar de estado al instante; el texto, con debounce.
watch(status, load)
let searchTimer = null
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(load, 350)
})

// Solo hay nivel de equipo si el formulario lo tiene configurado (y es visible).
const hasTeams = computed(() => !!q.value?.team_field)
const teams = computed(() => q.value?.teams ?? [])

// Origen legible del comentario (autor de la app, o «Kobo» para los importados).
const authorOf = (c) => c.author || (c.source === 'kobo' ? t('comments.sourceKobo') : t('comments.sourceUnknown'))

// «N comentario(s)» con pluralización (vue-i18n: t(clave, plural, { named })).
const nComments = (n) => t('comments.countN', n, { named: { n } })

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header>
      <div class="flex items-center justify-between gap-3">
        <RouterLink :to="{ name: 'submissions', params: { id: formId } }" class="text-sm text-primary-600 hover:underline">
          {{ $t('stats.back') }}
        </RouterLink>
        <div class="flex items-center gap-3">
          <RouterLink :to="{ name: 'quality', params: { id: formId } }" class="text-sm font-medium text-primary-600 hover:underline">
            {{ $t('stats.qualityTitle') }}
          </RouterLink>
          <RouterLink :to="{ name: 'stats', params: { id: formId } }" class="text-sm font-medium text-primary-600 hover:underline">
            {{ $t('stats.qualityStatsLink') }}
          </RouterLink>
        </div>
      </div>
      <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
        {{ $t('comments.title') }}{{ q ? ' · ' + q.form.name : '' }}
      </h1>
      <p class="mt-1 text-sm text-slate-500">{{ $t('comments.subtitle') }}</p>
    </header>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900">
      {{ error }}
    </div>

    <!-- Filtros -->
    <div class="flex flex-wrap items-center gap-3">
      <input
        v-model="search"
        type="search"
        :placeholder="$t('comments.searchPlaceholder')"
        class="w-full max-w-xs rounded-lg border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-800"
      />
      <select v-model="status" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-800">
        <option value="">{{ $t('comments.filterAllStatuses') }}</option>
        <option v-for="s in REVIEW_STATUSES" :key="s" :value="s">{{ $t('review.' + s) }}</option>
      </select>
    </div>

    <Skeleton v-if="loading && !q" variant="cards" :count="3" />

    <template v-else-if="q">
      <p class="text-sm text-slate-500">{{ nComments(q.total) }}</p>

      <p v-if="!teams.length" class="rounded-xl bg-white p-6 text-center text-sm text-slate-500 shadow-sm ring-1 ring-slate-200">
        {{ $t('comments.empty') }}
      </p>

      <div v-else class="space-y-3">
        <details
          v-for="(team, i) in teams"
          :key="i"
          class="overflow-hidden rounded-lg ring-1 ring-slate-200 dark:ring-slate-700"
          :open="!hasTeams || teams.length <= 3"
        >
          <summary
            class="flex cursor-pointer list-none flex-wrap items-center gap-x-3 gap-y-1 bg-slate-50 px-4 py-2 hover:bg-slate-100 dark:bg-slate-800/40"
            :class="{ 'pointer-events-none': !hasTeams }"
          >
            <span class="font-medium text-slate-800">{{ hasTeams ? team.name : $t('comments.allEnumerators') }}</span>
            <span class="ml-auto text-xs text-slate-500">{{ nComments(team.count) }}</span>
          </summary>

          <div class="divide-y divide-slate-100 border-t border-slate-100 dark:divide-slate-800 dark:border-slate-700">
            <div v-for="(e, j) in team.enumerators" :key="j" class="px-4 py-3">
              <p class="mb-2 flex items-center gap-2 text-sm">
                <span class="font-medium text-slate-700">{{ e.name }}</span>
                <span class="text-xs text-slate-400">· {{ nComments(e.count) }}</span>
              </p>
              <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                  <thead class="uppercase tracking-wider text-slate-400">
                    <tr>
                      <th class="py-1 pr-4 whitespace-nowrap">{{ $t('comments.colDate') }}</th>
                      <th class="py-1 pr-4 whitespace-nowrap">{{ $t('comments.colStatus') }}</th>
                      <th class="py-1 pr-4 whitespace-nowrap">{{ $t('comments.colAuthor') }}</th>
                      <th class="py-1 pr-4">{{ $t('comments.colComment') }}</th>
                      <th class="py-1"></th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr v-for="c in e.comments" :key="c.id">
                      <td class="py-1.5 pr-4 whitespace-nowrap text-slate-500">{{ c.created_at }}</td>
                      <td class="py-1.5 pr-4"><ReviewBadge :status="c.review_status" /></td>
                      <td class="py-1.5 pr-4 whitespace-nowrap text-slate-600">{{ authorOf(c) }}</td>
                      <td class="py-1.5 pr-4 text-slate-700 dark:text-slate-300">{{ c.comment }}</td>
                      <td class="py-1.5 whitespace-nowrap">
                        <RouterLink
                          :to="{ name: 'submission-detail', params: { id: formId, subId: c.uid }, query: { from: 'comments' } }"
                          class="font-medium text-primary-600 hover:underline"
                        >
                          {{ $t('stats.qualityView') }}
                        </RouterLink>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </details>
      </div>
    </template>
  </div>
</template>
