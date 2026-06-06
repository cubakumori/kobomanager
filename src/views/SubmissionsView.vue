<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, RouterLink } from 'vue-router'
import api from '../services/api'
import { apiError } from '../stores/auth'
import { makeLabeler } from '../composables/labels'
import ReviewBadge from '../components/ReviewBadge.vue'

const { t } = useI18n()
const route = useRoute()
const formId = computed(() => Number(route.params.id))

const formName = ref('')
const items = ref([])
const total = ref(0)
const page = ref(1)
const perPage = ref(25)
const perPageOptions = [10, 25, 50, 100]
const search = ref('')
const reviewFilter = ref('') // '' = todos
const sort = ref('date_desc')
const loading = ref(true)
const error = ref('')
const schema = ref(null)
const labelMode = ref('raw')
const hasGeo = ref(false)

const labeler = computed(() => makeLabeler(schema.value, labelMode.value))

// --- Columnas configurables (visibilidad + orden), persistidas por formulario ---
// "Enviado" (submitted_at) es una columna fija que siempre se muestra primero.
const orderedCols = ref([]) // todas las columnas de datos en orden de presentación
const visibleCols = ref([]) // subconjunto visible
const colMenuOpen = ref(false)
const dragIndex = ref(null)
let prefsForm = null // formId para el que se inicializaron las preferencias

const storeKey = (id) => `km.cols.${id}`

function defaultVisible(allCols) {
  // Igual que el comportamiento previo: primeras 4 preguntas con label (o primeras 4).
  if (labeler.value.on) {
    const labeled = allCols.filter((k) => labeler.value.hasLabel(k))
    if (labeled.length) return labeled.slice(0, 4)
  }
  return allCols.slice(0, 4)
}

function ensurePrefs() {
  const first = items.value[0]?.data
  if (!first) return
  if (prefsForm === formId.value && orderedCols.value.length) return
  const allCols = Object.keys(first).filter((k) => !k.startsWith('_'))

  let saved = null
  try {
    saved = JSON.parse(localStorage.getItem(storeKey(formId.value)) || 'null')
  } catch {
    saved = null
  }

  if (saved && Array.isArray(saved.order)) {
    // Conservar el orden guardado; añadir al final campos nuevos no vistos antes.
    const known = saved.order.filter((k) => allCols.includes(k))
    const extra = allCols.filter((k) => !known.includes(k))
    orderedCols.value = [...known, ...extra]
    const vis = Array.isArray(saved.visible) ? saved.visible.filter((k) => allCols.includes(k)) : []
    visibleCols.value = vis
  } else {
    orderedCols.value = allCols
    visibleCols.value = defaultVisible(allCols)
  }
  prefsForm = formId.value
}

function savePrefs() {
  try {
    localStorage.setItem(
      storeKey(formId.value),
      JSON.stringify({ order: orderedCols.value, visible: visibleCols.value }),
    )
  } catch {
    /* almacenamiento no disponible: las preferencias solo durarán la sesión */
  }
}

const shownColumns = computed(() => orderedCols.value.filter((k) => visibleCols.value.includes(k)))

function toggleCol(key) {
  const i = visibleCols.value.indexOf(key)
  if (i === -1) visibleCols.value.push(key)
  else visibleCols.value.splice(i, 1)
  savePrefs()
}

function onDrop(targetIdx) {
  const from = dragIndex.value
  dragIndex.value = null
  if (from === null || from === targetIdx) return
  const arr = [...orderedCols.value]
  const [moved] = arr.splice(from, 1)
  arr.splice(targetIdx, 0, moved)
  orderedCols.value = arr
  savePrefs()
}

function resetCols() {
  try {
    localStorage.removeItem(storeKey(formId.value))
  } catch { /* noop */ }
  const allCols = Object.keys(items.value[0]?.data ?? {}).filter((k) => !k.startsWith('_'))
  orderedCols.value = allCols
  visibleCols.value = defaultVisible(allCols)
}

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get(`/forms/${formId.value}/submissions`, {
      params: {
        page: page.value,
        per_page: perPage.value,
        search: search.value || undefined,
        review: reviewFilter.value || undefined,
        sort: sort.value,
      },
    })
    formName.value = data.data.form.name
    items.value = data.data.items
    total.value = data.data.total
    schema.value = data.data.schema ?? null
    labelMode.value = data.data.label_mode ?? 'raw'
    hasGeo.value = !!data.data.has_geo
    ensurePrefs()
  } catch (e) {
    error.value = apiError(e, t('submissions.loadError'))
  } finally {
    loading.value = false
  }
}

let searchTimer
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    page.value = 1
    load()
  }, 300)
})

// Filtro de revisión, orden y tamaño de página: recargar desde la primera página.
watch([reviewFilter, sort, perPage], () => {
  page.value = 1
  load()
})

// Al cambiar de formulario, re-inicializar las preferencias de columnas.
watch(formId, () => { prefsForm = null; orderedCols.value = []; visibleCols.value = [] })

function go(p) {
  if (p < 1 || p > totalPages.value) return
  page.value = p
  load()
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header>
      <RouterLink :to="{ name: 'forms' }" class="text-sm text-blue-600 hover:underline">
        {{ $t('submissions.back') }}
      </RouterLink>
      <div class="mt-1 flex items-center justify-between gap-4">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ formName || $t('submissions.title') }}</h1>
        <div class="flex shrink-0 items-center gap-2">
          <!-- Selector de columnas -->
          <div class="relative">
            <button
              class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
              @click="colMenuOpen = !colMenuOpen"
            >
              {{ $t('submissions.columns') }}
            </button>
            <template v-if="colMenuOpen">
              <!-- capa para cerrar al pulsar fuera -->
              <div class="fixed inset-0 z-10" @click="colMenuOpen = false"></div>
              <div class="absolute right-0 z-20 mt-1 w-72 rounded-lg border border-slate-200 bg-white p-2 shadow-lg">
                <div class="flex items-center justify-between px-2 py-1">
                  <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('submissions.columnsTitle') }}</span>
                  <button class="text-xs font-medium text-blue-600 hover:underline" @click="resetCols">
                    {{ $t('submissions.columnsReset') }}
                  </button>
                </div>
                <p class="px-2 pb-1 text-xs text-slate-400">{{ $t('submissions.columnsHint') }}</p>
                <ul class="max-h-72 overflow-y-auto">
                  <li
                    v-for="(c, i) in orderedCols"
                    :key="c"
                    draggable="true"
                    class="flex cursor-move items-center gap-2 rounded-md px-2 py-1.5 hover:bg-slate-50"
                    :class="{ 'opacity-40': dragIndex === i }"
                    @dragstart="dragIndex = i"
                    @dragover.prevent
                    @drop="onDrop(i)"
                    @dragend="dragIndex = null"
                  >
                    <span class="select-none text-slate-300">⠿</span>
                    <input
                      type="checkbox"
                      class="h-4 w-4"
                      :checked="visibleCols.includes(c)"
                      @change.stop="toggleCol(c)"
                    />
                    <span class="truncate text-sm text-slate-700" :title="labeler.label(c)">{{ labeler.label(c) }}</span>
                  </li>
                  <li v-if="!orderedCols.length" class="px-2 py-2 text-sm text-slate-400">—</li>
                </ul>
              </div>
            </template>
          </div>

          <RouterLink
            v-if="hasGeo"
            :to="{ name: 'form-map', params: { id: formId } }"
            class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
          >
            {{ $t('submissions.map') }}
          </RouterLink>
          <span
            v-else
            class="cursor-not-allowed rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-medium text-slate-300"
            :title="$t('submissions.mapDisabled')"
          >
            {{ $t('submissions.map') }}
          </span>

          <RouterLink
            :to="{ name: 'stats', params: { id: formId } }"
            class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
          >
            {{ $t('submissions.stats') }}
          </RouterLink>
        </div>
      </div>
      <p class="mt-1 text-sm text-slate-500">{{ $t('submissions.total', { n: total }) }}</p>
    </header>

    <div class="flex flex-wrap items-center gap-3">
      <input
        v-model="search"
        type="search"
        :placeholder="$t('submissions.search')"
        class="w-full max-w-xs rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/30"
      />
      <label class="flex items-center gap-1.5 text-sm text-slate-600">
        {{ $t('submissions.filterReview') }}
        <select
          v-model="reviewFilter"
          class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/30"
        >
          <option value="">{{ $t('submissions.reviewAll') }}</option>
          <option value="pending">{{ $t('review.pending') }}</option>
          <option value="approved">{{ $t('review.approved') }}</option>
          <option value="rejected">{{ $t('review.rejected') }}</option>
        </select>
      </label>
      <label class="flex items-center gap-1.5 text-sm text-slate-600">
        {{ $t('submissions.sort') }}
        <select
          v-model="sort"
          class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/30"
        >
          <option value="date_desc">{{ $t('submissions.sortNewest') }}</option>
          <option value="date_asc">{{ $t('submissions.sortOldest') }}</option>
        </select>
      </label>
      <label class="flex items-center gap-1.5 text-sm text-slate-600">
        {{ $t('submissions.perPage') }}
        <select
          v-model.number="perPage"
          class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/30"
        >
          <option v-for="n in perPageOptions" :key="n" :value="n">{{ n }}</option>
        </select>
      </label>
    </div>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">
      {{ error }}
    </div>

    <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
      <div v-if="loading" class="p-4 text-sm text-slate-500">{{ $t('common.loading') }}</div>
      <table v-else class="w-full text-left text-sm">
        <thead class="bg-emerald-50 text-xs uppercase tracking-wider text-emerald-700">
          <tr>
            <th class="px-4 py-3">{{ $t('submissions.colSubmitted') }}</th>
            <th v-for="c in shownColumns" :key="c" class="px-4 py-3">{{ labeler.label(c) }}</th>
            <th class="px-4 py-3">{{ $t('submissions.colReview') }}</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="s in items" :key="s.submission_uid" class="hover:bg-slate-50">
            <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ s.submitted_at }}</td>
            <td v-for="c in shownColumns" :key="c" class="px-4 py-3 text-slate-700">{{ labeler.value(c, s.data[c]) }}</td>
            <td class="px-4 py-3"><ReviewBadge :status="s.review_status" /></td>
            <td class="px-4 py-3 text-right">
              <RouterLink
                :to="{ name: 'submission-detail', params: { id: formId, subId: s.submission_uid } }"
                class="text-sm font-medium text-blue-600 hover:underline"
              >
                {{ $t('forms.view') }}
              </RouterLink>
            </td>
          </tr>
          <tr v-if="!items.length">
            <td :colspan="shownColumns.length + 3" class="px-4 py-6 text-center text-slate-400">
              {{ $t('submissions.empty') }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Paginación -->
    <div v-if="totalPages > 1" class="flex items-center justify-between text-sm">
      <button
        class="rounded-lg border border-slate-300 px-3 py-1.5 disabled:opacity-50"
        :disabled="page <= 1"
        @click="go(page - 1)"
      >
        {{ $t('submissions.prev') }}
      </button>
      <span class="text-slate-500">{{ $t('submissions.page', { page, pages: totalPages }) }}</span>
      <button
        class="rounded-lg border border-slate-300 px-3 py-1.5 disabled:opacity-50"
        :disabled="page >= totalPages"
        @click="go(page + 1)"
      >
        {{ $t('submissions.next') }}
      </button>
    </div>
  </div>
</template>
