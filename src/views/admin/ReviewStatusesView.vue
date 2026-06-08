<script setup>
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../../services/api'
import { apiError } from '../../stores/auth'
import { confirmDialog } from '../../composables/confirm'
import { useReviewStatusesStore } from '../../stores/reviewStatuses'
import { badgeClass, REVIEW_COLORS } from '../../composables/reviewColors'

const { t } = useI18n()
const store = useReviewStatusesStore()

const rows = ref([])      // [{ id, key, label, color, is_open, is_builtin, active, sort_order }] (editable)
const colors = ref(REVIEW_COLORS)
const loading = ref(true)
const error = ref('')
const flash = ref('')
const savingId = ref(null)

// Alta de un estado nuevo.
const newLabel = ref('')
const newColor = ref('slate')
const newOpen = ref(true)
const creating = ref(false)

// Etiqueta por defecto (i18n) de un built-in, para usar como placeholder.
function defaultLabel(key) {
  return t(`review.${key}`)
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/admin/review-statuses')
    rows.value = data.data.statuses.map((s) => ({ ...s, label: s.label ?? '' }))
    colors.value = data.data.colors
  } catch (e) {
    error.value = apiError(e, t('reviewStatuses.loadError'))
  } finally {
    loading.value = false
  }
}

async function save(row) {
  savingId.value = row.id
  flash.value = ''
  error.value = ''
  try {
    await api.put(`/admin/review-statuses/${row.id}`, {
      label: row.label,
      color: row.color,
      is_open: row.is_open,
      active: row.active,
      sort_order: Number(row.sort_order),
    })
    flash.value = t('reviewStatuses.saved')
    await refresh()
  } catch (e) {
    error.value = apiError(e, t('reviewStatuses.saveError'))
  } finally {
    savingId.value = null
  }
}

async function create() {
  if (!newLabel.value.trim()) return
  creating.value = true
  error.value = ''
  try {
    await api.post('/admin/review-statuses', {
      label: newLabel.value.trim(),
      color: newColor.value,
      is_open: newOpen.value,
    })
    newLabel.value = ''
    newColor.value = 'slate'
    newOpen.value = true
    flash.value = t('reviewStatuses.saved')
    await refresh()
  } catch (e) {
    error.value = apiError(e, t('reviewStatuses.saveError'))
  } finally {
    creating.value = false
  }
}

async function remove(row) {
  const ok = await confirmDialog({
    title: t('reviewStatuses.deleteTitle'),
    message: t('reviewStatuses.deleteConfirm', { name: row.label || row.key }),
    confirmText: t('reviewStatuses.delete'),
    danger: true,
  })
  if (!ok) return
  error.value = ''
  try {
    await api.delete(`/admin/review-statuses/${row.id}`)
    flash.value = t('reviewStatuses.deleted')
    await refresh()
  } catch (e) {
    error.value = apiError(e, t('reviewStatuses.saveError'))
  }
}

// Tras cualquier cambio: recargar la lista local y el catálogo global (badges, etc.).
async function refresh() {
  await Promise.all([load(), store.load(true)])
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-semibold text-slate-900">{{ $t('reviewStatuses.title') }}</h1>
      <p class="mt-1 text-sm text-slate-500">{{ $t('reviewStatuses.subtitle') }}</p>
    </div>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">{{ error }}</div>
    <p v-if="flash" class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-700 ring-1 ring-green-200">{{ flash }}</p>

    <p v-if="loading" class="text-sm text-slate-400">{{ $t('common.loading') }}</p>

    <div v-else class="space-y-3">
      <div
        v-for="row in rows"
        :key="row.id"
        class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200"
      >
        <div class="flex flex-wrap items-center gap-4">
          <span class="inline-block rounded-full px-2 py-0.5 text-xs font-medium" :class="badgeClass(row.color)">
            {{ row.label || defaultLabel(row.key) }}
          </span>
          <span v-if="row.is_builtin" class="text-xs text-slate-400">{{ $t('reviewStatuses.builtin') }}</span>
          <code class="text-xs text-slate-400">{{ row.key }}</code>
        </div>

        <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <label class="block text-sm">
            <span class="mb-1 block text-slate-600">{{ $t('reviewStatuses.label') }}</span>
            <input
              v-model="row.label"
              :placeholder="row.is_builtin ? defaultLabel(row.key) : ''"
              class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
            />
          </label>

          <label class="block text-sm">
            <span class="mb-1 block text-slate-600">{{ $t('reviewStatuses.order') }}</span>
            <input
              v-model.number="row.sort_order"
              type="number"
              class="w-24 rounded-lg border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
            />
          </label>

          <div class="block text-sm">
            <span class="mb-1 block text-slate-600">{{ $t('reviewStatuses.color') }}</span>
            <div class="flex flex-wrap gap-1.5">
              <button
                v-for="c in colors"
                :key="c"
                type="button"
                :title="c"
                class="h-6 w-6 rounded-full ring-2"
                :class="[badgeClass(c), row.color === c ? 'ring-slate-800' : 'ring-transparent']"
                @click="row.color = c"
              ></button>
            </div>
          </div>

          <div class="flex flex-col gap-2 text-sm">
            <label class="flex items-center gap-2">
              <input v-model="row.is_open" type="checkbox" :disabled="row.key === 'pending'" />
              <span class="text-slate-600">{{ $t('reviewStatuses.isOpen') }}</span>
            </label>
            <label class="flex items-center gap-2">
              <input v-model="row.active" type="checkbox" :disabled="row.key === 'pending'" />
              <span class="text-slate-600">{{ $t('reviewStatuses.active') }}</span>
            </label>
          </div>
        </div>

        <div class="mt-3 flex items-center gap-3">
          <button
            :disabled="savingId === row.id"
            class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-60"
            @click="save(row)"
          >
            {{ $t('reviewStatuses.save') }}
          </button>
          <button
            v-if="!row.is_builtin"
            class="text-sm font-medium text-red-600 hover:text-red-700"
            @click="remove(row)"
          >
            {{ $t('reviewStatuses.delete') }}
          </button>
        </div>
      </div>

      <!-- Alta de estado nuevo -->
      <div class="rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200">
        <h2 class="font-semibold text-slate-900">{{ $t('reviewStatuses.addTitle') }}</h2>
        <div class="mt-3 flex flex-wrap items-end gap-4">
          <label class="block text-sm">
            <span class="mb-1 block text-slate-600">{{ $t('reviewStatuses.label') }}</span>
            <input
              v-model="newLabel"
              class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
              @keyup.enter="create"
            />
          </label>
          <div class="block text-sm">
            <span class="mb-1 block text-slate-600">{{ $t('reviewStatuses.color') }}</span>
            <div class="flex flex-wrap gap-1.5">
              <button
                v-for="c in colors"
                :key="c"
                type="button"
                :title="c"
                class="h-6 w-6 rounded-full ring-2"
                :class="[badgeClass(c), newColor === c ? 'ring-slate-800' : 'ring-transparent']"
                @click="newColor = c"
              ></button>
            </div>
          </div>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="newOpen" type="checkbox" />
            <span class="text-slate-600">{{ $t('reviewStatuses.isOpen') }}</span>
          </label>
          <button
            :disabled="creating || !newLabel.trim()"
            class="rounded-lg bg-accent-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-accent-700 disabled:opacity-60"
            @click="create"
          >
            {{ $t('reviewStatuses.add') }}
          </button>
        </div>
        <p class="mt-2 text-xs text-slate-400">{{ $t('reviewStatuses.isOpenHint') }}</p>
      </div>
    </div>
  </div>
</template>
