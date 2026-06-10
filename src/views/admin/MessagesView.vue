<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../../services/api'
import { apiError } from '../../stores/auth'
import { confirmDialog } from '../../composables/confirm'
import Modal from '../../components/Modal.vue'
import Skeleton from '../../components/Skeleton.vue'
import { useTableFreeze } from '../../composables/appConfig'

const { t } = useI18n()
const { freezeFirst } = useTableFreeze()

const items = ref([])
const total = ref(0)
const newCount = ref(0)
const loading = ref(true)
const loaded = ref(false) // primera carga completada (el skeleton solo aparece antes)
const error = ref('')

const statusFilter = ref('new') // la bandeja abre mostrando lo nuevo
const topicFilter = ref('')
const page = ref(1)
const perPage = 25
const pages = computed(() => Math.max(1, Math.ceil(total.value / perPage)))

const TOPICS = ['general', 'hire', 'proposal', 'using']

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/admin/messages', {
      params: { page: page.value, per_page: perPage, status: statusFilter.value, topic: topicFilter.value },
    })
    items.value = data.data.items
    loaded.value = true
    total.value = data.data.total
    newCount.value = data.data.new_count
  } catch (e) {
    error.value = apiError(e, t('messages.loadError'))
  } finally {
    loading.value = false
  }
}
function applyFilters() {
  page.value = 1
  load()
}
function goTo(p) {
  page.value = Math.min(Math.max(1, p), pages.value)
  load()
}

// --- Lectura en modal (al abrir, new → read automáticamente) ---
const current = ref(null)
async function open(msg) {
  current.value = msg
  if (msg.status === 'new') {
    try {
      await api.put(`/admin/messages/${msg.id}`, { status: 'read' })
      msg.status = 'read'
      newCount.value = Math.max(0, newCount.value - 1)
    } catch {
      /* no bloquea la lectura */
    }
  }
}

// Las etiquetas de motivo se reutilizan del formulario público (support.topic*).
function topicLabel(topic) {
  return t('support.topic' + topic.charAt(0).toUpperCase() + topic.slice(1))
}

function mailtoHref(msg) {
  const subject = encodeURIComponent(`Re: [KoboManager] ${topicLabel(msg.topic)}`)
  return `mailto:${msg.email}?subject=${subject}`
}

async function setStatus(msg, status) {
  try {
    await api.put(`/admin/messages/${msg.id}`, { status })
    msg.status = status
    // Si el filtro activo deja fuera el nuevo estado, refresca la lista.
    if (statusFilter.value && statusFilter.value !== status) {
      current.value = null
      await load()
    }
  } catch (e) {
    error.value = apiError(e, t('messages.updateError'))
  }
}

async function remove(msg) {
  const ok = await confirmDialog({
    title: t('messages.confirmDeleteTitle'),
    message: t('messages.confirmDelete'),
    confirmText: t('common.delete'),
    danger: true,
  })
  if (!ok) return
  try {
    await api.delete(`/admin/messages/${msg.id}`)
    current.value = null
    await load()
  } catch (e) {
    error.value = apiError(e, t('messages.deleteError'))
  }
}

function fmtDate(s) {
  return s ? s.replace(/:\d{2}$/, '') : ''
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $t('messages.title') }}</h1>
      <p class="mt-1 text-sm text-slate-500">
        {{ $t('messages.subtitle') }}
        <span v-if="newCount" class="ml-1 inline-flex rounded-full bg-primary-100 px-2 py-0.5 text-xs font-semibold text-primary-700 dark:bg-primary-900/40 dark:text-primary-300">
          {{ $t('messages.newCount', { n: newCount }) }}
        </span>
      </p>
    </header>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900">
      {{ error }}
    </div>

    <!-- Filtros -->
    <div class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center">
      <select
        v-model="statusFilter"
        class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm sm:w-auto"
        @change="applyFilters"
      >
        <option value="">{{ $t('messages.allStatuses') }}</option>
        <option value="new">{{ $t('messages.statusNew') }}</option>
        <option value="read">{{ $t('messages.statusRead') }}</option>
        <option value="archived">{{ $t('messages.statusArchived') }}</option>
      </select>
      <select
        v-model="topicFilter"
        class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm sm:w-auto"
        @change="applyFilters"
      >
        <option value="">{{ $t('messages.allTopics') }}</option>
        <option v-for="tp in TOPICS" :key="tp" :value="tp">{{ topicLabel(tp) }}</option>
      </select>
    </div>

    <div v-if="loading && !loaded" class="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
      <Skeleton variant="table" :rows="6" />
    </div>

    <div v-else class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200 transition-opacity" :class="loading ? 'opacity-60' : ''">
      <table class="w-full whitespace-nowrap text-left text-sm">
        <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
          <tr>
            <th class="px-4 py-3" :class="freezeFirst() ? 'sticky left-0 z-10 bg-slate-50' : ''">{{ $t('messages.colDate') }}</th>
            <th class="px-4 py-3">{{ $t('messages.colFrom') }}</th>
            <th class="px-4 py-3">{{ $t('messages.colTopic') }}</th>
            <th class="px-4 py-3">{{ $t('messages.colStatus') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr
            v-for="msg in items"
            :key="msg.id"
            class="group cursor-pointer hover:bg-slate-50"
            :class="msg.status === 'new' ? 'bg-primary-50/40 dark:bg-primary-900/15' : ''"
            @click="open(msg)"
          >
            <td class="px-4 py-3 text-slate-600" :class="freezeFirst() ? 'sticky left-0 z-10 bg-white group-hover:bg-slate-50' : ''">{{ fmtDate(msg.created_at) }}</td>
            <td class="px-4 py-3">
              <p class="font-medium text-slate-900" :class="msg.status === 'new' ? 'font-semibold' : ''">{{ msg.name }}</p>
              <p class="text-xs text-slate-400">{{ msg.email }}<span v-if="msg.org"> · {{ msg.org }}</span></p>
            </td>
            <td class="px-4 py-3">
              <span class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 ring-1 ring-slate-200">
                {{ topicLabel(msg.topic) }}
              </span>
            </td>
            <td class="px-4 py-3">
              <span
                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1"
                :class="{
                  'bg-primary-50 text-primary-700 ring-primary-200 dark:bg-primary-900/40 dark:text-primary-300 dark:ring-primary-800': msg.status === 'new',
                  'bg-success-50 text-success-700 ring-success-200 dark:bg-success-900/40 dark:text-success-300 dark:ring-success-800': msg.status === 'read',
                  'bg-slate-100 text-slate-500 ring-slate-200': msg.status === 'archived',
                }"
              >
                {{ $t('messages.status' + msg.status.charAt(0).toUpperCase() + msg.status.slice(1)) }}
              </span>
            </td>
          </tr>
          <tr v-if="!items.length">
            <td colspan="4" class="px-4 py-8 text-center text-slate-400">{{ $t('messages.empty') }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Paginación -->
    <div v-if="pages > 1" class="flex items-center justify-end gap-2 text-sm">
      <button
        class="rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
        :disabled="page <= 1"
        @click="goTo(page - 1)"
      >‹</button>
      <span class="text-xs text-slate-500">{{ page }} / {{ pages }}</span>
      <button
        class="rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
        :disabled="page >= pages"
        @click="goTo(page + 1)"
      >›</button>
    </div>

    <!-- Modal: lectura del mensaje -->
    <Modal v-if="current" size="lg" :title="$t('messages.readTitle')" @close="current = null">
      <div class="space-y-4">
        <div class="space-y-1 rounded-lg bg-slate-50 px-3 py-2 text-sm">
          <p><span class="text-slate-500">{{ $t('messages.colFrom') }}:</span> <strong>{{ current.name }}</strong> &lt;{{ current.email }}&gt;</p>
          <p v-if="current.org"><span class="text-slate-500">{{ $t('messages.org') }}:</span> {{ current.org }}</p>
          <p><span class="text-slate-500">{{ $t('messages.colTopic') }}:</span> {{ topicLabel(current.topic) }}</p>
          <p><span class="text-slate-500">{{ $t('messages.colDate') }}:</span> {{ current.created_at }}</p>
          <p v-if="!current.emailed" class="text-xs text-amber-600 dark:text-amber-400">{{ $t('messages.notEmailed') }}</p>
        </div>

        <p class="whitespace-pre-wrap break-words text-sm text-slate-800">{{ current.message }}</p>

        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-4">
          <button class="rounded-lg px-3 py-2 text-sm font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/40" @click="remove(current)">
            {{ $t('common.delete') }}
          </button>
          <div class="flex flex-wrap gap-2">
            <button
              v-if="current.status !== 'archived'"
              class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
              @click="setStatus(current, 'archived')"
            >{{ $t('messages.archive') }}</button>
            <button
              v-else
              class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
              @click="setStatus(current, 'read')"
            >{{ $t('messages.unarchive') }}</button>
            <a
              :href="mailtoHref(current)"
              class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700"
            >{{ $t('messages.reply') }}</a>
          </div>
        </div>
      </div>
    </Modal>
  </div>
</template>
