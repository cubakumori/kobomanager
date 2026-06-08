<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import api from '../services/api'
import { apiError } from '../stores/auth'
import { makeLabeler } from '../composables/labels'
import { useDerivedFormat } from '../composables/derived'
import ReviewBadge from '../components/ReviewBadge.vue'
import LeafletMap from '../components/LeafletMap.vue'
import AttachmentsGallery from '../components/AttachmentsGallery.vue'

const { t } = useI18n()
const { summaryRows } = useDerivedFormat()
const route = useRoute()
const router = useRouter()
const sub = ref(null)
const loading = ref(true)
const error = ref('')
const schema = ref(null)
const labelMode = ref('raw')
const fieldTruncate = ref(null)
const attachments = ref([])
const geo = ref([])
const derived = ref(null)

const labeler = computed(() => makeLabeler(schema.value, labelMode.value, fieldTruncate.value))

// Navegación al envío anterior/siguiente (mismo orden que la lista).
const toPrev = computed(() =>
  sub.value?.prev ? { name: 'submission-detail', params: { id: route.params.id, subId: sub.value.prev } } : null,
)
const toNext = computed(() =>
  sub.value?.next ? { name: 'submission-detail', params: { id: route.params.id, subId: sub.value.next } } : null,
)

// Adjunto indexado por la clave del campo que lo originó (question_xpath).
const attByField = computed(() => {
  const m = {}
  for (const a of attachments.value) if (a.field && !(a.field in m)) m[a.field] = a
  return m
})

// URL del proxy autenticado del backend (mismo origen → la cookie viaja sola).
function attUrl(att) {
  return `/api/v1/submissions/${route.params.subId}/attachments/${att.uid}`
}

// --- edición ---
const editing = ref(false)
const editForm = ref({})
const saving = ref(false)
const editError = ref('')

// --- revisión ---
const comment = ref('')
const reviewing = ref(false)
const reviewError = ref('')

const fields = computed(() => {
  const d = sub.value?.data ?? {}
  const data = [], meta = []
  for (const [k, v] of Object.entries(d)) {
    ;(k.startsWith('_') ? meta : data).push([k, v])
  }
  return { data, meta }
})

function fmt(v) {
  return v !== null && typeof v === 'object' ? JSON.stringify(v) : String(v ?? '')
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get(`/submissions/${route.params.subId}`)
    sub.value = data.data
    schema.value = data.data.schema ?? null
    labelMode.value = data.data.label_mode ?? 'raw'
    fieldTruncate.value = data.data.field_truncate ?? null
    attachments.value = data.data.attachments ?? []
    geo.value = data.data.geo ?? []
    derived.value = data.data.derived ?? null
  } catch (e) {
    error.value = apiError(e, t('detail.loadError'))
  } finally {
    loading.value = false
  }
}

function startEdit() {
  editError.value = ''
  editForm.value = Object.fromEntries(fields.value.data.map(([k, v]) => [k, fmt(v)]))
  editing.value = true
}

async function saveEdit() {
  const original = Object.fromEntries(fields.value.data.map(([k, v]) => [k, fmt(v)]))
  const changed = {}
  for (const [k, v] of Object.entries(editForm.value)) {
    if (v !== original[k]) changed[k] = v
  }
  if (Object.keys(changed).length === 0) {
    editing.value = false
    return
  }
  saving.value = true
  editError.value = ''
  try {
    const { data } = await api.put(`/submissions/${route.params.subId}`, { data: changed })
    editing.value = false
    // Editar en Kobo crea una versión nueva con un _uuid distinto; el backend ya
    // migró la caché y las revisiones a ese uid. Navegamos al nuevo uid (el watch
    // dispara load()); si no cambió, recargamos en el sitio.
    const newUid = data?.data?.submission_uid
    if (newUid && newUid !== route.params.subId) {
      router.replace({ name: 'submission-detail', params: { id: route.params.id, subId: newUid } })
    } else {
      await load()
    }
  } catch (e) {
    editError.value = apiError(e, t('detail.saveError'))
  } finally {
    saving.value = false
  }
}

async function submitReview(status) {
  reviewing.value = true
  reviewError.value = ''
  try {
    await api.post(`/submissions/${route.params.subId}/review`, { status, comment: comment.value })
    comment.value = ''
    await load()
  } catch (e) {
    reviewError.value = apiError(e, t('detail.reviewError'))
  } finally {
    reviewing.value = false
  }
}

// Al cambiar de envío (prev/siguiente) recargar y volver arriba; salir de edición.
watch(() => route.params.subId, () => {
  editing.value = false
  window.scrollTo({ top: 0 })
  load()
})

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header>
      <div class="flex items-center justify-between gap-3">
        <RouterLink
          :to="{ name: 'submissions', params: { id: route.params.id } }"
          class="text-sm text-primary-600 hover:underline"
        >
          {{ $t('detail.back') }}
        </RouterLink>
        <!-- Navegación entre envíos -->
        <div class="flex items-center gap-1">
          <component
            :is="toPrev ? 'RouterLink' : 'span'"
            :to="toPrev || undefined"
            class="rounded-lg border px-2.5 py-1 text-sm font-medium"
            :class="toPrev ? 'border-slate-300 text-slate-700 hover:bg-slate-50' : 'cursor-not-allowed border-slate-200 text-slate-300'"
          >{{ $t('detail.prev') }}</component>
          <component
            :is="toNext ? 'RouterLink' : 'span'"
            :to="toNext || undefined"
            class="rounded-lg border px-2.5 py-1 text-sm font-medium"
            :class="toNext ? 'border-slate-300 text-slate-700 hover:bg-slate-50' : 'cursor-not-allowed border-slate-200 text-slate-300'"
          >{{ $t('detail.next') }}</component>
        </div>
      </div>
      <div class="mt-1 flex items-center gap-3">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $t('detail.title') }}</h1>
        <ReviewBadge v-if="sub" :status="sub.review_status" />
      </div>
      <p v-if="sub" class="mt-1 text-sm text-slate-500">
        {{ $t('detail.submittedAt', { form: sub.form.name, date: sub.submitted_at }) }}
      </p>
    </header>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">
      {{ error }}
    </div>
    <div v-else-if="loading" class="text-sm text-slate-500">{{ $t('common.loading') }}</div>

    <template v-else-if="sub">
      <!-- Datos (con edición opcional) -->
      <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
          <h2 class="font-semibold text-slate-900">{{ $t('detail.data') }}</h2>
          <button
            v-if="sub.can_edit && !editing"
            class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
            @click="startEdit"
          >
            {{ $t('common.edit') }}
          </button>
        </div>

        <div v-if="editError" class="bg-red-50 px-5 py-2 text-sm text-red-700">{{ editError }}</div>

        <!-- Modo lectura -->
        <dl v-if="!editing" class="divide-y divide-slate-100">
          <div v-for="[k, v] in fields.data" :key="k" class="grid grid-cols-3 gap-4 px-5 py-3">
            <dt class="text-sm font-medium text-slate-500" :title="labeler.fullLabel(k)">{{ labeler.label(k) }}</dt>
            <dd class="col-span-2 text-sm text-slate-800">
              <a
                v-if="attByField[k]"
                :href="attUrl(attByField[k])"
                target="_blank"
                rel="noopener"
                class="inline-flex items-center gap-1 text-primary-600 hover:underline"
              >📎 {{ attByField[k].name }}</a>
              <template v-else>{{ labeler.value(k, v) }}</template>
            </dd>
          </div>
          <div v-if="!fields.data.length" class="px-5 py-3 text-sm text-slate-400">{{ $t('detail.noFields') }}</div>
        </dl>

        <!-- Modo edición -->
        <div v-else class="space-y-3 px-5 py-4">
          <label v-for="[k] in fields.data" :key="k" class="grid grid-cols-3 items-center gap-4">
            <span class="text-sm font-medium text-slate-500" :title="labeler.fullLabel(k)">{{ labeler.label(k) }}</span>
            <!-- Desplegable con etiquetas para preguntas de opción única -->
            <select
              v-if="labeler.on && labeler.optionsFor(k) && !labeler.isMulti(k)"
              v-model="editForm[k]"
              class="col-span-2 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
            >
              <option value="">—</option>
              <option
                v-if="editForm[k] && !(editForm[k] in labeler.optionsFor(k))"
                :value="editForm[k]"
              >{{ editForm[k] }}</option>
              <option v-for="(lbl, code) in labeler.optionsFor(k)" :key="code" :value="code">{{ lbl }}</option>
            </select>
            <input
              v-else
              v-model="editForm[k]"
              class="col-span-2 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
            />
          </label>
          <div class="flex items-center gap-3 pt-2">
            <button
              :disabled="saving"
              class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-60"
              @click="saveEdit"
            >
              {{ saving ? $t('common.saving') : $t('detail.saveChanges') }}
            </button>
            <button
              class="text-sm font-medium text-slate-500 hover:text-slate-700"
              @click="editing = false"
            >
              {{ $t('common.cancel') }}
            </button>
          </div>
          <p class="text-xs text-slate-400">{{ $t('detail.editHint') }}</p>
        </div>
      </section>

      <!-- Resumen / valores calculados -->
      <section v-if="derived" class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <h2 class="border-b border-slate-100 px-5 py-3 font-semibold text-slate-900">{{ $t('derived.summary') }}</h2>
        <dl class="grid grid-cols-1 sm:grid-cols-2">
          <div
            v-for="row in summaryRows(derived)"
            :key="row.label"
            class="grid grid-cols-3 gap-4 border-b border-slate-50 px-5 py-2.5"
          >
            <dt class="text-sm font-medium text-slate-500">{{ row.label }}</dt>
            <dd class="col-span-2 text-sm text-slate-800">{{ row.value }}</dd>
          </div>
        </dl>
      </section>

      <!-- Ubicación (geopoint/geoshape/geotrace) -->
      <section v-if="geo.length" class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <h2 class="border-b border-slate-100 px-5 py-3 font-semibold text-slate-900">{{ $t('detail.location') }}</h2>
        <div class="p-5">
          <LeafletMap :features="geo" height="320px" />
        </div>
      </section>

      <!-- Adjuntos (fotos, audio, archivos) -->
      <section v-if="attachments.length" class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <h2 class="border-b border-slate-100 px-5 py-3 font-semibold text-slate-900">
          {{ $t('detail.attachments', { n: attachments.length }) }}
        </h2>
        <div class="p-5">
          <AttachmentsGallery :attachments="attachments" :url-for="attUrl" />
        </div>
      </section>

      <!-- Panel de revisión -->
      <section v-if="sub.can_validate" class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <h2 class="border-b border-slate-100 px-5 py-3 font-semibold text-slate-900">{{ $t('detail.review') }}</h2>
        <div class="space-y-3 px-5 py-4">
          <div v-if="reviewError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
            {{ reviewError }}
          </div>
          <textarea
            v-model="comment"
            rows="2"
            :placeholder="$t('detail.comment')"
            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
          ></textarea>
          <div class="flex gap-3">
            <button
              :disabled="reviewing"
              class="rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700 disabled:opacity-60"
              @click="submitReview('approved')"
            >
              {{ $t('detail.approve') }}
            </button>
            <button
              :disabled="reviewing"
              class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-700 disabled:opacity-60"
              @click="submitReview('on_hold')"
            >
              {{ $t('detail.standby') }}
            </button>
            <button
              :disabled="reviewing"
              class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-60"
              @click="submitReview('rejected')"
            >
              {{ $t('detail.reject') }}
            </button>
          </div>
        </div>
      </section>

      <!-- Metadatos -->
      <details class="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <summary class="cursor-pointer px-5 py-3 font-semibold text-slate-900">
          {{ $t('detail.metadata', { n: fields.meta.length }) }}
        </summary>
        <dl class="divide-y divide-slate-100 border-t border-slate-100">
          <div v-for="[k, v] in fields.meta" :key="k" class="grid grid-cols-3 gap-4 px-5 py-2">
            <dt class="text-xs font-medium text-slate-400">{{ k }}</dt>
            <dd class="col-span-2 break-all text-xs text-slate-600">{{ fmt(v) }}</dd>
          </div>
        </dl>
      </details>

      <!-- Historial de revisiones -->
      <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <h2 class="border-b border-slate-100 px-5 py-3 font-semibold text-slate-900">{{ $t('detail.history') }}</h2>
        <ul v-if="sub.reviews.length" class="divide-y divide-slate-100">
          <li v-for="r in sub.reviews" :key="r.id" class="flex items-start gap-3 px-5 py-3">
            <ReviewBadge :status="r.status" />
            <div class="text-sm">
              <p class="text-slate-700">{{ r.comment || '—' }}</p>
              <p class="text-xs text-slate-400">{{ r.user_name }} · {{ r.created_at }}</p>
            </div>
          </li>
        </ul>
        <p v-else class="px-5 py-3 text-sm text-slate-400">{{ $t('detail.noReviews') }}</p>
      </section>

      <!-- Navegación entre envíos (repetida al final) -->
      <div class="flex items-center justify-between gap-3">
        <RouterLink
          :to="{ name: 'submissions', params: { id: route.params.id } }"
          class="text-sm text-primary-600 hover:underline"
        >
          {{ $t('detail.back') }}
        </RouterLink>
        <div class="flex items-center gap-1">
          <component
            :is="toPrev ? 'RouterLink' : 'span'"
            :to="toPrev || undefined"
            class="rounded-lg border px-2.5 py-1 text-sm font-medium"
            :class="toPrev ? 'border-slate-300 text-slate-700 hover:bg-slate-50' : 'cursor-not-allowed border-slate-200 text-slate-300'"
          >{{ $t('detail.prev') }}</component>
          <component
            :is="toNext ? 'RouterLink' : 'span'"
            :to="toNext || undefined"
            class="rounded-lg border px-2.5 py-1 text-sm font-medium"
            :class="toNext ? 'border-slate-300 text-slate-700 hover:bg-slate-50' : 'cursor-not-allowed border-slate-200 text-slate-300'"
          >{{ $t('detail.next') }}</component>
        </div>
      </div>
    </template>
  </div>
</template>
