<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../services/api'
import { apiError } from '../stores/auth'
import Skeleton from '../components/Skeleton.vue'

const { t, te } = useI18n()

// --- Registro de actividad propio (GET /audit/me) ---
const items = ref([])
const total = ref(0)
const page = ref(1)
const perPage = ref(25)
const actions = ref([])
const loading = ref(true)
const error = ref('')

// Filtros (sin filtro por usuario: siempre eres tú)
const fAction = ref('')
const fForm = ref('')
const fFrom = ref('')
const fTo = ref('')
const search = ref('')

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
    total.value = data.data.total
    actions.value = data.data.actions
  } catch (e) {
    error.value = apiError(e, t('myActivity.loadError'))
  } finally {
    loading.value = false
  }
}

function clearFilters() {
  fAction.value = ''
  fForm.value = ''
  fFrom.value = ''
  fTo.value = ''
  search.value = ''
}

// Filtros (salvo búsqueda) recargan desde la primera página.
watch([fAction, fForm, fFrom, fTo], () => {
  page.value = 1
  load()
})
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

    <!-- Filtros: rejilla de 2 columnas en móvil (evita una fila por filtro); flex en escritorio. -->
    <div class="grid grid-cols-2 gap-3 sm:flex sm:flex-wrap sm:items-end">
      <label class="flex flex-col gap-1 text-xs text-slate-500">
        {{ $t('audit.filterAction') }}
        <select v-model="fAction" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30 sm:w-auto">
          <option value="">{{ $t('audit.allActions') }}</option>
          <option v-for="a in actions" :key="a" :value="a">{{ actionLabel(a) }}</option>
        </select>
      </label>
      <label class="flex flex-col gap-1 text-xs text-slate-500">
        {{ $t('audit.filterForm') }}
        <select v-model="fForm" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30 sm:w-auto">
          <option value="">{{ $t('audit.allForms') }}</option>
          <option v-for="f in forms" :key="f.id" :value="f.id">{{ f.name }}</option>
        </select>
      </label>
      <label class="flex flex-col gap-1 text-xs text-slate-500">
        {{ $t('audit.filterFrom') }}
        <input v-model="fFrom" type="date" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30 sm:w-auto" />
      </label>
      <label class="flex flex-col gap-1 text-xs text-slate-500">
        {{ $t('audit.filterTo') }}
        <input v-model="fTo" type="date" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30 sm:w-auto" />
      </label>
      <label class="flex flex-col gap-1 text-xs text-slate-500">
        {{ $t('audit.search') }}
        <input v-model="search" type="search" :placeholder="$t('audit.searchHint')" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30 sm:w-auto" />
      </label>
      <button class="w-full self-end rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 sm:w-auto" @click="clearFilters">
        {{ $t('audit.clear') }}
      </button>
    </div>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900">{{ error }}</div>

    <p class="text-sm text-slate-500">{{ $t('audit.total', { n: total }) }}</p>

    <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
      <Skeleton v-if="loading && !items.length" variant="table" :rows="8" />
      <table v-else class="w-full text-left text-sm transition-opacity" :class="loading ? 'opacity-60' : ''">
        <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
          <tr>
            <th class="px-4 py-3">{{ $t('audit.colDate') }}</th>
            <th class="px-4 py-3">{{ $t('audit.colAction') }}</th>
            <th class="px-4 py-3">{{ $t('audit.colForm') }}</th>
            <th class="px-4 py-3">{{ $t('audit.colDetail') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="r in items" :key="r.id" class="align-top hover:bg-slate-50">
            <td class="whitespace-nowrap px-4 py-3 text-slate-500">{{ r.created_at }}</td>
            <td class="px-4 py-3">
              <span class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">{{ actionLabel(r.action) }}</span>
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
  </div>
</template>
