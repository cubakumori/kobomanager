<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../../services/api'
import { apiError } from '../../stores/auth'
import Skeleton from '../../components/Skeleton.vue'

const { t, te } = useI18n()

// --- Estado del sistema (/health ampliado para admin) ---
const health = ref(null)
async function loadHealth() {
  try {
    const { data } = await api.get('/health')
    health.value = data.data
  } catch {
    health.value = null
  }
}
const cronEntries = computed(() => Object.entries(health.value?.cron ?? {}))

// --- Registro de auditoría ---
const items = ref([])
const total = ref(0)
const page = ref(1)
const perPage = ref(25)
const actions = ref([])
const loading = ref(true)
const error = ref('')

// Filtros
const fAction = ref('')
const fUser = ref('')
const fForm = ref('')
const fFrom = ref('')
const fTo = ref('')
const search = ref('')

// Opciones de los desplegables
const users = ref([])
const forms = ref([])

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))

// Etiqueta legible de una acción (con fallback al código crudo).
const actionLabel = (a) => (te('audit.action_' + a) ? t('audit.action_' + a) : a)
// Etiqueta legible del nombre de un cron (con fallback al identificador crudo).
const cronLabel = (n) => (te('audit.cron_' + n) ? t('audit.cron_' + n) : n)

async function loadRefs() {
  try {
    const [u, f] = await Promise.all([api.get('/admin/users'), api.get('/admin/forms')])
    users.value = u.data.data
    forms.value = f.data.data
  } catch {
    /* no crítico: los filtros por usuario/formulario quedan vacíos */
  }
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/admin/audit', {
      params: {
        page: page.value,
        per_page: perPage.value,
        action: fAction.value || undefined,
        user_id: fUser.value || undefined,
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
    error.value = apiError(e, t('audit.loadError'))
  } finally {
    loading.value = false
  }
}

function clearFilters() {
  fAction.value = ''
  fUser.value = ''
  fForm.value = ''
  fFrom.value = ''
  fTo.value = ''
  search.value = ''
}

// Filtros (salvo búsqueda) recargan desde la primera página.
watch([fAction, fUser, fForm, fFrom, fTo], () => {
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
  loadHealth()
  loadRefs()
  load()
})
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $t('audit.title') }}</h1>
      <p class="mt-1 text-sm text-slate-500">{{ $t('audit.subtitle') }}</p>
    </header>

    <!-- Estado del sistema -->
    <section v-if="health" class="grid gap-4 sm:grid-cols-2">
      <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wider text-slate-500">{{ $t('audit.cronTitle') }}</h2>
        <ul v-if="cronEntries.length" class="space-y-2 text-sm">
          <li v-for="[name, info] in cronEntries" :key="name" class="flex items-center justify-between gap-3">
            <span class="font-medium text-slate-700" :title="name">{{ cronLabel(name) }}</span>
            <span class="text-right">
              <span
                class="mr-2 inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1"
                :class="info.ok ? 'bg-success-50 text-success-700 ring-success-200' : 'bg-red-50 text-red-700 ring-red-200'"
              >{{ info.ok ? $t('audit.ok') : $t('audit.error') }}</span>
              <span class="text-slate-400">{{ info.at }}</span>
            </span>
          </li>
        </ul>
        <p v-else class="text-sm text-slate-400">{{ $t('audit.cronNever') }}</p>
      </div>

      <div v-if="health.sync" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wider text-slate-500">{{ $t('audit.syncTitle') }}</h2>
        <dl class="space-y-1 text-sm text-slate-700">
          <div class="flex justify-between"><dt class="text-slate-500">{{ $t('audit.forms') }}</dt><dd>{{ health.sync.forms_active }} / {{ health.sync.forms_total }}</dd></div>
          <div class="flex justify-between">
            <dt class="text-slate-500">{{ $t('audit.withErrors') }}</dt>
            <dd :class="health.sync.forms_error ? 'font-semibold text-red-600' : ''">{{ health.sync.forms_error }}</dd>
          </div>
          <div class="flex justify-between"><dt class="text-slate-500">{{ $t('audit.submissions') }}</dt><dd>{{ health.sync.submissions }}</dd></div>
          <div class="flex justify-between"><dt class="text-slate-500">{{ $t('audit.lastSync') }}</dt><dd>{{ health.sync.last_synced_at || '—' }}</dd></div>
          <div class="flex justify-between">
            <dt class="text-slate-500">{{ $t('audit.mail') }}</dt>
            <dd>{{ health.sync.mail_configured ? $t('audit.mailOn') : $t('audit.mailOff') }}</dd>
          </div>
        </dl>
      </div>
    </section>

    <!-- Filtros -->
    <div class="flex flex-wrap items-end gap-3">
      <label class="flex flex-col gap-1 text-xs text-slate-500">
        {{ $t('audit.filterAction') }}
        <select v-model="fAction" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30">
          <option value="">{{ $t('audit.allActions') }}</option>
          <option v-for="a in actions" :key="a" :value="a">{{ actionLabel(a) }}</option>
        </select>
      </label>
      <label class="flex flex-col gap-1 text-xs text-slate-500">
        {{ $t('audit.filterUser') }}
        <select v-model="fUser" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30">
          <option value="">{{ $t('audit.allUsers') }}</option>
          <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
        </select>
      </label>
      <label class="flex flex-col gap-1 text-xs text-slate-500">
        {{ $t('audit.filterForm') }}
        <select v-model="fForm" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30">
          <option value="">{{ $t('audit.allForms') }}</option>
          <option v-for="f in forms" :key="f.id" :value="f.id">{{ f.name }}</option>
        </select>
      </label>
      <label class="flex flex-col gap-1 text-xs text-slate-500">
        {{ $t('audit.filterFrom') }}
        <input v-model="fFrom" type="date" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30" />
      </label>
      <label class="flex flex-col gap-1 text-xs text-slate-500">
        {{ $t('audit.filterTo') }}
        <input v-model="fTo" type="date" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30" />
      </label>
      <label class="flex flex-col gap-1 text-xs text-slate-500">
        {{ $t('audit.search') }}
        <input v-model="search" type="search" :placeholder="$t('audit.searchHint')" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30" />
      </label>
      <button class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50" @click="clearFilters">
        {{ $t('audit.clear') }}
      </button>
    </div>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">{{ error }}</div>

    <p class="text-sm text-slate-500">{{ $t('audit.total', { n: total }) }}</p>

    <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
      <Skeleton v-if="loading" variant="table" :rows="10" />
      <table v-else class="w-full text-left text-sm">
        <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
          <tr>
            <th class="px-4 py-3">{{ $t('audit.colDate') }}</th>
            <th class="px-4 py-3">{{ $t('audit.colAction') }}</th>
            <th class="px-4 py-3">{{ $t('audit.colUser') }}</th>
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
            <td class="px-4 py-3">
              <span v-if="r.user_name" class="text-slate-700" :title="r.user_email">{{ r.user_name }}</span>
              <span v-else class="text-slate-300">—</span>
            </td>
            <td class="px-4 py-3 text-slate-600">{{ r.form_name || '—' }}</td>
            <td class="px-4 py-3 text-xs text-slate-500">
              <span v-if="r.submission_uid" class="mr-1 rounded bg-slate-100 px-1 py-0.5 font-mono">{{ r.submission_uid.slice(0, 8) }}</span>
              <span class="break-all" :title="detailText(r.detail)">{{ detailText(r.detail) }}</span>
            </td>
          </tr>
          <tr v-if="!items.length">
            <td colspan="5" class="px-4 py-6 text-center text-slate-400">{{ $t('audit.empty') }}</td>
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
