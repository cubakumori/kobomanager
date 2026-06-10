<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../services/api'
import { apiError } from '../stores/auth'
import Skeleton from '../components/Skeleton.vue'
import Modal from '../components/Modal.vue'
import { useTableFreeze } from '../composables/appConfig'

const { t, te } = useI18n()
const { freezeFirst } = useTableFreeze()

// --- Registro de actividad propio (GET /audit/me) ---
const items = ref([])
const total = ref(0)
const page = ref(1)
const perPage = ref(25)
const actions = ref([])
const loading = ref(true)
const loaded = ref(false) // primera carga completada (el skeleton solo aparece antes)
const error = ref('')

// Filtros aplicados (sin filtro por usuario: siempre eres tú). La búsqueda vive
// FUERA del modal (siempre a mano); el resto se edita en el modal de filtros.
const fAction = ref('')
const fForm = ref('')
const fFrom = ref('')
const fTo = ref('')
const search = ref('')

// Borrador del modal: solo se aplica al pulsar «Aplicar».
const filtersOpen = ref(false)
const draft = ref({ action: '', form: '', from: '', to: '' })

const filterCount = computed(
  () => [fAction.value, fForm.value, fFrom.value, fTo.value].filter(Boolean).length,
)
const canClear = computed(() => filterCount.value > 0 || search.value !== '')

// Opciones del desplegable de formularios: los formularios propios del usuario.
const forms = ref([])

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))

// Etiqueta legible de una acción (con fallback al código crudo).
const actionLabel = (a) => (te('audit.action_' + a) ? t('audit.action_' + a) : a)

async function loadRefs() {
  try {
    const { data } = await api.get('/forms')
    forms.value = data.data
  } catch {
    /* no crítico: el filtro por formulario queda vacío */
  }
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/audit/me', {
      params: {
        page: page.value,
        per_page: perPage.value,
        action: fAction.value || undefined,
        form_id: fForm.value || undefined,
        date_from: fFrom.value || undefined,
        date_to: fTo.value || undefined,
        search: search.value || undefined,
      },
    })
    items.value = data.data.items
    loaded.value = true
    total.value = data.data.total
    actions.value = data.data.actions
  } catch (e) {
    error.value = apiError(e, t('myActivity.loadError'))
  } finally {
    loading.value = false
  }
}

function openFilters() {
  draft.value = { action: fAction.value, form: fForm.value, from: fFrom.value, to: fTo.value }
  filtersOpen.value = true
}
function applyFilters() {
  fAction.value = draft.value.action
  fForm.value = draft.value.form
  fFrom.value = draft.value.from
  fTo.value = draft.value.to
  filtersOpen.value = false
  page.value = 1
  load()
}
function clearFilters() {
  fAction.value = ''
  fForm.value = ''
  fFrom.value = ''
  fTo.value = ''
  search.value = '' // su watch recarga
  filtersOpen.value = false
  page.value = 1
  load()
}

let searchTimer
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    page.value = 1
    load()
  }, 300)
})

function go(p) {
  if (p < 1 || p > totalPages.value) return
  page.value = p
  load()
}

// Resumen compacto del detalle JSON (clave: valor, …).
function detailText(d) {
  if (d === null || d === undefined) return ''
  if (typeof d !== 'object') return String(d)
  return Object.entries(d)
    .map(([k, v]) => `${k}: ${typeof v === 'object' ? JSON.stringify(v) : v}`)
    .join(' · ')
}

onMounted(() => {
  loadRefs()
  load()
})
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $t('myActivity.title') }}</h1>
      <p class="mt-1 text-sm text-slate-500">{{ $t('myActivity.subtitle') }}</p>
    </header>

    <!-- Una sola fila: búsqueda siempre a mano; el resto de filtros, en un modal. -->
    <div class="flex items-center gap-2">
      <input
        v-model="search"
        type="search"
        :placeholder="$t('audit.searchHint')"
        class="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30 sm:max-w-xs"
      />
      <button
        type="button"
        class="shrink-0 whitespace-nowrap rounded-lg border px-3 py-1.5 text-sm font-medium"
        :class="filterCount
          ? 'border-primary-300 bg-primary-50 text-primary-700 hover:bg-primary-100 dark:border-primary-700 dark:bg-primary-900/30 dark:text-primary-300 dark:hover:bg-primary-900/50'
          : 'border-slate-300 text-slate-700 hover:bg-slate-50'"
        @click="openFilters"
      >
        {{ filterCount ? $t('submissions.filtersActive', { n: filterCount }) : $t('submissions.filters') }}
      </button>
      <button
        type="button"
        class="shrink-0 rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
        :disabled="!canClear"
        @click="clearFilters"
      >
        {{ $t('common.clear') }}
      </button>
    </div>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900">{{ error }}</div>

    <p class="text-sm text-slate-500">{{ $t('audit.total', { n: total }) }}</p>

    <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
      <Skeleton v-if="loading && !loaded" variant="table" :rows="8" />
      <table v-else class="w-full text-left text-sm transition-opacity" :class="loading ? 'opacity-60' : ''">
        <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
          <tr>
            <th class="px-4 py-3" :class="freezeFirst() ? 'sticky left-0 z-10 bg-slate-50' : ''">{{ $t('audit.colDate') }}</th>
            <th class="px-4 py-3">{{ $t('audit.colAction') }}</th>
            <th class="px-4 py-3">{{ $t('audit.colForm') }}</th>
            <th class="px-4 py-3">{{ $t('audit.colDetail') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="r in items" :key="r.id" class="group align-top hover:bg-slate-50">
            <td
              class="whitespace-nowrap px-4 py-3 text-slate-500"
              :class="freezeFirst() ? 'sticky left-0 z-10 bg-white group-hover:bg-slate-50' : ''"
            >{{ r.created_at }}</td>
            <td class="px-4 py-3">
              <span class="inline-flex whitespace-nowrap rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">{{ actionLabel(r.action) }}</span>
            </td>
            <td class="px-4 py-3 text-slate-600">{{ r.form_name || '—' }}</td>
            <td class="px-4 py-3 text-xs text-slate-500">
              <span v-if="r.submission_uid" class="mr-1 rounded bg-slate-100 px-1 py-0.5 font-mono">{{ r.submission_uid.slice(0, 8) }}</span>
              <span class="break-all" :title="detailText(r.detail)">{{ detailText(r.detail) }}</span>
            </td>
          </tr>
          <tr v-if="!items.length">
            <td colspan="4" class="px-4 py-6 text-center text-slate-400">{{ $t('myActivity.empty') }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Paginación -->
    <div v-if="totalPages > 1" class="flex items-center justify-between text-sm">
      <button class="rounded-lg border border-slate-300 px-3 py-1.5 disabled:opacity-50" :disabled="page <= 1" @click="go(page - 1)">
        {{ $t('audit.prev') }}
      </button>
      <span class="text-slate-500">{{ $t('audit.page', { page, pages: totalPages }) }}</span>
      <button class="rounded-lg border border-slate-300 px-3 py-1.5 disabled:opacity-50" :disabled="page >= totalPages" @click="go(page + 1)">
        {{ $t('audit.next') }}
      </button>
    </div>

    <!-- Modal: filtros (acción / formulario / fechas) -->
    <Modal v-if="filtersOpen" :title="$t('submissions.filters')" @close="filtersOpen = false">
      <div class="space-y-4">
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-slate-700">{{ $t('audit.filterAction') }}</span>
          <select v-model="draft.action" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30">
            <option value="">{{ $t('audit.allActions') }}</option>
            <option v-for="a in actions" :key="a" :value="a">{{ actionLabel(a) }}</option>
          </select>
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-slate-700">{{ $t('audit.filterForm') }}</span>
          <select v-model="draft.form" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30">
            <option value="">{{ $t('audit.allForms') }}</option>
            <option v-for="f in forms" :key="f.id" :value="f.id">{{ f.name }}</option>
          </select>
        </label>
        <div class="grid grid-cols-2 gap-3">
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-slate-700">{{ $t('audit.filterFrom') }}</span>
            <input v-model="draft.from" type="date" class="w-full rounded-lg border border-slate-300 px-2 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30" />
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-slate-700">{{ $t('audit.filterTo') }}</span>
            <input v-model="draft.to" type="date" class="w-full rounded-lg border border-slate-300 px-2 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30" />
          </label>
        </div>

        <div class="flex items-center justify-between gap-3 border-t border-slate-100 pt-4">
          <button type="button" class="rounded-lg px-3 py-2 text-sm font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/40 disabled:cursor-not-allowed disabled:opacity-40" :disabled="!canClear" @click="clearFilters">
            {{ $t('common.clear') }}
          </button>
          <div class="flex gap-2">
            <button type="button" class="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100" @click="filtersOpen = false">
              {{ $t('common.cancel') }}
            </button>
            <button type="button" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700" @click="applyFilters">
              {{ $t('submissions.filtersApply') }}
            </button>
          </div>
        </div>
      </div>
    </Modal>
  </div>
</template>
