<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, RouterLink } from 'vue-router'
import api from '../services/api'
import { apiError } from '../stores/auth'
import { confirmDialog } from '../composables/confirm'
import { makeLabeler } from '../composables/labels'
import { useDerivedFormat, DERIVED_TABLE_COLS, isDerivedCol } from '../composables/derived'
import ReviewBadge from '../components/ReviewBadge.vue'
import Skeleton from '../components/Skeleton.vue'
import Modal from '../components/Modal.vue'
import RowFilterEditor from '../components/RowFilterEditor.vue'

const { tableLabel, tableValue } = useDerivedFormat()

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
const fieldTruncate = ref(null)
const hasGeo = ref(false)
const canValidate = ref(false)

// --- Selección + revisión en lote ---
const selected = ref(new Set())
const batchComment = ref('')
const batchBusy = ref(false)
const batchFlash = ref('')

const allOnPageSelected = computed(
  () => items.value.length > 0 && items.value.every((s) => selected.value.has(s.submission_uid)),
)
function toggleRow(uid) {
  const s = new Set(selected.value)
  s.has(uid) ? s.delete(uid) : s.add(uid)
  selected.value = s
}
function toggleAllOnPage() {
  const s = new Set(selected.value)
  if (allOnPageSelected.value) items.value.forEach((it) => s.delete(it.submission_uid))
  else items.value.forEach((it) => s.add(it.submission_uid))
  selected.value = s
}
function clearSelection() {
  selected.value = new Set()
}

async function batchReview(status) {
  const uids = [...selected.value]
  if (!uids.length) return
  const ok = await confirmDialog({
    title: t('submissions.batchConfirmTitle'),
    message: t('submissions.batchConfirm', { n: uids.length, action: t('review.' + status) }),
    confirmText: t('review.' + status),
    danger: status === 'rejected',
  })
  if (!ok) return
  batchBusy.value = true
  batchFlash.value = ''
  try {
    const { data } = await api.post(`/forms/${formId.value}/review`, {
      uids,
      status,
      comment: batchComment.value || undefined,
    })
    batchFlash.value = t('submissions.batchDone', { n: data.data.applied })
    batchComment.value = ''
    clearSelection()
    await load()
  } catch (e) {
    error.value = apiError(e, t('submissions.batchError'))
  } finally {
    batchBusy.value = false
  }
}

// Enlace de descarga CSV con los filtros activos (la cookie de sesión viaja sola).
const exportUrl = computed(() => {
  const p = new URLSearchParams()
  if (search.value) p.set('search', search.value)
  if (reviewFilter.value) p.set('review', reviewFilter.value)
  if (advFilter.value) p.set('filter', JSON.stringify(advFilter.value))
  const qs = p.toString()
  return `/api/v1/forms/${formId.value}/export${qs ? '?' + qs : ''}`
})

// --- Filtro avanzado (mismo formato y motor que el scoping por filas; solo restringe) ---
const advFilter = ref(null)
const advOpen = ref(false)
const advEditor = ref(null)
const filterKey = (id) => `km.filter.${id}`

// Nº total de condiciones (suma de grupos; soporta el formato antiguo).
function countConditions(rf) {
  if (!rf) return 0
  if (Array.isArray(rf.conditions)) return rf.conditions.length
  return (rf.groups || []).reduce((n, g) => n + (g.conditions?.length || 0), 0)
}
const advCount = computed(() => countConditions(advFilter.value))

function loadAdvFilter() {
  try {
    advFilter.value = JSON.parse(localStorage.getItem(filterKey(formId.value)) || 'null')
  } catch {
    advFilter.value = null
  }
}
function saveAdvFilter() {
  try {
    if (advFilter.value) localStorage.setItem(filterKey(formId.value), JSON.stringify(advFilter.value))
    else localStorage.removeItem(filterKey(formId.value))
  } catch {
    /* sin almacenamiento: el filtro solo durará la sesión */
  }
}
function applyAdv() {
  advFilter.value = advEditor.value?.getValue() ?? null
  saveAdvFilter()
  advOpen.value = false
  page.value = 1
  load()
}
function clearAdv() {
  advFilter.value = null
  saveAdvFilter()
  advOpen.value = false
  page.value = 1
  load()
}

const labeler = computed(() => makeLabeler(schema.value, labelMode.value, fieldTruncate.value))

// --- Columnas configurables (visibilidad + orden), persistidas por formulario ---
// "Enviado" (submitted_at) es una columna fija que siempre se muestra primero.
const orderedCols = ref([]) // todas las columnas de datos en orden de presentación
const visibleCols = ref([]) // subconjunto visible
const colMenuOpen = ref(false)
const actionsOpen = ref(false) // menú «Acciones» (solo móvil/tablet)
const dragIndex = ref(null)
let prefsForm = null // formId para el que se inicializaron las preferencias

const storeKey = (id) => `km.cols.${id}`

// Etiqueta/valor de una columna, sea de datos o calculada (id con prefijo «@»).
const colLabel = (c) => (isDerivedCol(c) ? tableLabel(c) : labeler.value.label(c))
const colFullLabel = (c) => (isDerivedCol(c) ? tableLabel(c) : labeler.value.fullLabel(c))
const cellValue = (c, s) => (isDerivedCol(c) ? tableValue(c, s.derived) : labeler.value.value(c, s.data[c]))

function defaultVisible(dataCols) {
  // Igual que el comportamiento previo: primeras 4 preguntas de datos con label (o
  // primeras 4). Las columnas calculadas arrancan ocultas.
  if (labeler.value.on) {
    const labeled = dataCols.filter((k) => labeler.value.hasLabel(k))
    if (labeled.length) return labeled.slice(0, 4)
  }
  return dataCols.slice(0, 4)
}

function ensurePrefs() {
  const first = items.value[0]?.data
  if (!first) return
  if (prefsForm === formId.value && orderedCols.value.length) return
  const dataCols = Object.keys(first).filter((k) => !k.startsWith('_'))
  // Las columnas calculadas se ofrecen primero, antes de los campos del formulario.
  const allCols = [...DERIVED_TABLE_COLS, ...dataCols]

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
    visibleCols.value = defaultVisible(dataCols)
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
  const dataCols = Object.keys(items.value[0]?.data ?? {}).filter((k) => !k.startsWith('_'))
  orderedCols.value = [...DERIVED_TABLE_COLS, ...dataCols]
  visibleCols.value = defaultVisible(dataCols)
}

// Clases compartidas de los botones de acción y de los ítems del menú móvil.
const actionBtn =
  'whitespace-nowrap rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50'
const menuItem =
  'block w-full rounded-md px-3 py-2 text-left text-sm font-medium text-slate-700 hover:bg-slate-50'

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
        filter: advFilter.value ? JSON.stringify(advFilter.value) : undefined,
      },
    })
    formName.value = data.data.form.name
    items.value = data.data.items
    total.value = data.data.total
    schema.value = data.data.schema ?? null
    labelMode.value = data.data.label_mode ?? 'raw'
    fieldTruncate.value = data.data.field_truncate ?? null
    hasGeo.value = !!data.data.has_geo
    canValidate.value = !!data.data.can_validate
    clearSelection()
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
watch(formId, () => { prefsForm = null; orderedCols.value = []; visibleCols.value = []; loadAdvFilter() })

function go(p) {
  if (p < 1 || p > totalPages.value) return
  page.value = p
  load()
}

onMounted(() => { loadAdvFilter(); load() })
</script>

<template>
  <div class="space-y-6">
    <header class="relative">
      <RouterLink :to="{ name: 'forms' }" class="text-sm text-primary-600 hover:underline">
        {{ $t('submissions.back') }}
      </RouterLink>
      <!-- En pantallas pequeñas el título ocupa su propia línea (no se encoge) y las acciones
           van debajo; en escritorio (lg) vuelven a la misma fila. -->
      <div class="mt-1 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between lg:gap-4">
        <h1 class="min-w-0 break-words text-2xl font-semibold tracking-tight text-slate-900">{{ formName || $t('submissions.title') }}</h1>

        <!-- Acciones en escritorio: inline -->
        <div class="hidden shrink-0 items-center gap-2 lg:flex">
          <button :class="actionBtn" @click="colMenuOpen = !colMenuOpen">{{ $t('submissions.columns') }}</button>
          <RouterLink v-if="hasGeo" :to="{ name: 'form-map', params: { id: formId } }" :class="actionBtn">{{ $t('submissions.map') }}</RouterLink>
          <span v-else class="cursor-not-allowed whitespace-nowrap rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-medium text-slate-300" :title="$t('submissions.mapDisabled')">{{ $t('submissions.map') }}</span>
          <RouterLink :to="{ name: 'stats', params: { id: formId } }" :class="actionBtn">{{ $t('submissions.stats') }}</RouterLink>
          <a :href="exportUrl" :class="actionBtn" :title="$t('submissions.exportHint')">{{ $t('submissions.export') }}</a>
        </div>

        <!-- Acciones en móvil/tablet: un único menú «Acciones» (los botones nunca parten en varias filas) -->
        <div class="relative lg:hidden">
          <button
            :class="actionBtn"
            class="inline-flex items-center gap-1"
            @click="actionsOpen = !actionsOpen"
          >
            {{ $t('submissions.actions') }}
            <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
          </button>
          <template v-if="actionsOpen">
            <div class="fixed inset-0 z-30" @click="actionsOpen = false"></div>
            <div class="absolute left-0 z-40 mt-1 w-56 rounded-lg border border-slate-200 bg-white p-1 shadow-lg">
              <button :class="menuItem" @click="actionsOpen = false; colMenuOpen = true">{{ $t('submissions.columns') }}</button>
              <RouterLink v-if="hasGeo" :to="{ name: 'form-map', params: { id: formId } }" :class="menuItem" @click="actionsOpen = false">{{ $t('submissions.map') }}</RouterLink>
              <span v-else :class="[menuItem, 'cursor-not-allowed text-slate-300 hover:bg-transparent']">{{ $t('submissions.map') }}</span>
              <RouterLink :to="{ name: 'stats', params: { id: formId } }" :class="menuItem" @click="actionsOpen = false">{{ $t('submissions.stats') }}</RouterLink>
              <a :href="exportUrl" :class="menuItem" :title="$t('submissions.exportHint')" @click="actionsOpen = false">{{ $t('submissions.export') }}</a>
            </div>
          </template>
        </div>
      </div>
      <p class="mt-1 text-sm text-slate-500">{{ $t('submissions.total', { n: total }) }}</p>

      <!-- Panel selector de columnas (compartido escritorio/móvil): hoja centrada en móvil,
           anclado a la derecha de la cabecera en escritorio. -->
      <template v-if="colMenuOpen">
        <div class="fixed inset-0 z-40 bg-black/30 lg:bg-transparent" @click="colMenuOpen = false"></div>
        <div class="fixed inset-x-4 top-24 z-50 mx-auto max-w-sm rounded-lg border border-slate-200 bg-white p-2 shadow-lg lg:absolute lg:inset-x-auto lg:right-0 lg:top-full lg:mx-0 lg:mt-1 lg:w-72 lg:max-w-none">
          <div class="flex items-center justify-between px-2 py-1">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $t('submissions.columnsTitle') }}</span>
            <button class="text-xs font-medium text-primary-600 hover:underline" @click="resetCols">
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
              <span class="truncate text-sm text-slate-700" :title="colFullLabel(c)">{{ colLabel(c) }}</span>
              <span
                v-if="isDerivedCol(c)"
                class="ml-auto shrink-0 rounded bg-accent-100 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-accent-700"
              >{{ $t('submissions.columnsCalculated') }}</span>
            </li>
            <li v-if="!orderedCols.length" class="px-2 py-2 text-sm text-slate-400">—</li>
          </ul>
        </div>
      </template>
    </header>

    <!-- Filtros: rejilla de 2 columnas en móvil (búsqueda a fila completa); flex en escritorio. -->
    <div class="grid grid-cols-2 gap-3 sm:flex sm:flex-wrap sm:items-center">
      <input
        v-model="search"
        type="search"
        :placeholder="$t('submissions.search')"
        class="col-span-2 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30 sm:col-auto sm:w-auto sm:max-w-xs"
      />
      <label class="flex min-w-0 items-center gap-1.5 text-sm text-slate-600">
        {{ $t('submissions.filterReview') }}
        <select
          v-model="reviewFilter"
          class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30 sm:w-auto"
        >
          <option value="">{{ $t('submissions.reviewAll') }}</option>
          <option value="pending">{{ $t('review.pending') }}</option>
          <option value="approved">{{ $t('review.approved') }}</option>
          <option value="on_hold">{{ $t('review.on_hold') }}</option>
          <option value="rejected">{{ $t('review.rejected') }}</option>
        </select>
      </label>
      <label class="flex min-w-0 items-center gap-1.5 text-sm text-slate-600">
        {{ $t('submissions.sort') }}
        <select
          v-model="sort"
          class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30 sm:w-auto"
        >
          <option value="date_desc">{{ $t('submissions.sortNewest') }}</option>
          <option value="date_asc">{{ $t('submissions.sortOldest') }}</option>
          <optgroup :label="$t('submissions.columnsCalculated')">
            <option value="duration_desc">{{ $t('submissions.sortDurationDesc') }}</option>
            <option value="duration_asc">{{ $t('submissions.sortDurationAsc') }}</option>
            <option value="attachments_desc">{{ $t('submissions.sortAttachments') }}</option>
            <option value="geo_desc">{{ $t('submissions.sortGeo') }}</option>
          </optgroup>
        </select>
      </label>
      <label class="flex min-w-0 items-center gap-1.5 text-sm text-slate-600">
        {{ $t('submissions.perPage') }}
        <select
          v-model.number="perPage"
          class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30 sm:w-auto"
        >
          <option v-for="n in perPageOptions" :key="n" :value="n">{{ n }}</option>
        </select>
      </label>
      <div class="col-span-2 flex items-center gap-1 sm:col-auto">
        <button
          type="button"
          class="w-full whitespace-nowrap rounded-lg border px-3 py-1.5 text-sm font-medium sm:w-auto"
          :class="advCount
            ? 'border-primary-300 bg-primary-50 text-primary-700 hover:bg-primary-100'
            : 'border-slate-300 text-slate-700 hover:bg-slate-50'"
          @click="advOpen = true"
        >
          {{ advCount ? $t('submissions.filtersActive', { n: advCount }) : $t('submissions.filters') }}
        </button>
        <button
          v-if="advCount"
          type="button"
          class="shrink-0 rounded-lg px-2 py-1.5 text-sm font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-900"
          :title="$t('submissions.filtersClear')"
          @click="clearAdv"
        >×</button>
      </div>
    </div>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">
      {{ error }}
    </div>
    <p v-if="batchFlash" class="rounded-lg bg-success-50 px-3 py-2 text-sm text-success-700 ring-1 ring-success-200">
      {{ batchFlash }}
    </p>

    <!-- Barra de revisión en lote (solo si el usuario puede validar y hay selección) -->
    <div
      v-if="canValidate && selected.size"
      class="flex flex-wrap items-center gap-3 rounded-xl bg-accent-50 px-4 py-3 ring-1 ring-accent-200"
    >
      <span class="text-sm font-medium text-accent-800">{{ $t('submissions.selected', { n: selected.size }) }}</span>
      <input
        v-model="batchComment"
        :placeholder="$t('submissions.batchComment')"
        class="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
      />
      <button
        :disabled="batchBusy"
        class="rounded-lg bg-success-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-success-700 disabled:opacity-60"
        @click="batchReview('approved')"
      >
        {{ $t('submissions.batchApprove') }}
      </button>
      <button
        :disabled="batchBusy"
        class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-sky-700 disabled:opacity-60"
        @click="batchReview('on_hold')"
      >
        {{ $t('submissions.batchStandby') }}
      </button>
      <button
        :disabled="batchBusy"
        class="rounded-lg bg-red-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-60"
        @click="batchReview('rejected')"
      >
        {{ $t('submissions.batchReject') }}
      </button>
      <button class="text-sm font-medium text-slate-500 hover:text-slate-700" @click="clearSelection">
        {{ $t('submissions.clearSelection') }}
      </button>
    </div>

    <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
      <Skeleton v-if="loading" variant="table" :rows="10" />
      <table v-else class="w-full text-left text-sm">
        <thead class="bg-accent-50 text-xs uppercase tracking-wider text-accent-700 dark:bg-slate-50 dark:text-accent-300">
          <tr>
            <th v-if="canValidate" class="sticky left-0 z-20 w-12 bg-accent-50 px-4 py-3 dark:bg-slate-50">
              <input
                type="checkbox"
                class="h-4 w-4 align-middle"
                :checked="allOnPageSelected"
                :title="$t('submissions.selectAll')"
                @change="toggleAllOnPage"
              />
            </th>
            <th
              class="sticky z-20 whitespace-nowrap bg-accent-50 px-4 py-3 dark:bg-slate-50"
              :class="canValidate ? 'left-12' : 'left-0'"
            >{{ $t('submissions.colSubmitted') }}</th>
            <th v-for="c in shownColumns" :key="c" class="whitespace-nowrap px-4 py-3" :title="colFullLabel(c)">{{ colLabel(c) }}</th>
            <th class="px-4 py-3">{{ $t('submissions.colReview') }}</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="s in items" :key="s.submission_uid" class="group hover:bg-slate-50">
            <td v-if="canValidate" class="sticky left-0 z-10 bg-white px-4 py-3 group-hover:bg-slate-50">
              <input
                type="checkbox"
                class="h-4 w-4 align-middle"
                :checked="selected.has(s.submission_uid)"
                @change="toggleRow(s.submission_uid)"
              />
            </td>
            <td
              class="sticky z-10 whitespace-nowrap bg-white px-4 py-3 text-slate-600 group-hover:bg-slate-50"
              :class="canValidate ? 'left-12' : 'left-0'"
            >{{ s.submitted_at }}</td>
            <td
              v-for="c in shownColumns"
              :key="c"
              class="max-w-xs truncate whitespace-nowrap px-4 py-3 text-slate-700"
              :title="cellValue(c, s)"
            >{{ cellValue(c, s) }}</td>
            <td class="px-4 py-3"><ReviewBadge :status="s.review_status" /></td>
            <td class="px-4 py-3 text-right">
              <RouterLink
                :to="{ name: 'submission-detail', params: { id: formId, subId: s.submission_uid } }"
                class="text-sm font-medium text-primary-600 hover:underline"
              >
                {{ $t('submissions.details') }}
              </RouterLink>
            </td>
          </tr>
          <tr v-if="!items.length">
            <td :colspan="shownColumns.length + 3 + (canValidate ? 1 : 0)" class="px-4 py-6 text-center text-slate-400">
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

    <!-- Modal: filtro avanzado (reutiliza el editor del scoping por filas) -->
    <Modal v-if="advOpen" size="xl" :title="$t('submissions.filtersTitle')" @close="advOpen = false">
      <div class="space-y-4">
        <p class="text-sm text-slate-500">{{ $t('submissions.filtersIntro') }}</p>
        <RowFilterEditor
          ref="advEditor"
          :form-id="formId"
          :model-value="advFilter"
          :fields-url="`/forms/${formId}/scope-fields`"
        />
        <div class="flex items-center justify-between gap-3 border-t border-slate-100 pt-4">
          <button type="button" class="rounded-lg px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50" @click="clearAdv">
            {{ $t('submissions.filtersClear') }}
          </button>
          <div class="flex gap-2">
            <button type="button" class="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100" @click="advOpen = false">
              {{ $t('common.cancel') }}
            </button>
            <button type="button" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700" @click="applyAdv">
              {{ $t('submissions.filtersApply') }}
            </button>
          </div>
        </div>
      </div>
    </Modal>
  </div>
</template>
