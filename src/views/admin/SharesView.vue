<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../../services/api'
import { apiError } from '../../stores/auth'
import { confirmDialog } from '../../composables/confirm'
import Modal from '../../components/Modal.vue'

const { t } = useI18n()

const links = ref([])
const forms = ref([])
const passwordPolicy = ref('optional')
const attachmentsPolicy = ref('off')
const loading = ref(true)
const error = ref('')

// --- Creación ---
const showCreate = ref(false)
const creating = ref(false)
const createError = ref('')
const blankForm = () => ({
  form_id: '',
  label: '',
  expose_list: true,
  expose_detail: true,
  expose_map: false,
  expose_attachments: false,
  password: '',
  expires_at: '',
  row_filter: null,
})
const form = ref(blankForm())

const activeForms = computed(() => forms.value.filter((f) => f.active))

async function loadForms() {
  try {
    const { data } = await api.get('/admin/forms')
    forms.value = data.data
  } catch (e) {
    error.value = apiError(e, t('shares.loadError'))
  }
}

async function loadLinks() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/admin/shares')
    links.value = data.data.items
    passwordPolicy.value = data.data.password_policy
    attachmentsPolicy.value = data.data.attachments_policy
  } catch (e) {
    error.value = apiError(e, t('shares.loadError'))
  } finally {
    loading.value = false
  }
}

function openCreate() {
  form.value = blankForm()
  createError.value = ''
  showCreate.value = true
}

const canCreate = computed(
  () =>
    form.value.form_id &&
    (form.value.expose_list || form.value.expose_detail || form.value.expose_map) &&
    !(passwordPolicy.value === 'required' && !form.value.password),
)

// Adjuntos: solo si la política global los permite Y se está fijando contraseña.
const canExposeAttachments = computed(
  () => attachmentsPolicy.value === 'require_password' && passwordPolicy.value !== 'off' && !!form.value.password,
)

async function onCreate() {
  if (!canCreate.value) return
  creating.value = true
  createError.value = ''
  try {
    await api.post('/admin/shares', {
      form_id: Number(form.value.form_id),
      label: form.value.label,
      expose_list: form.value.expose_list,
      expose_detail: form.value.expose_detail,
      expose_map: form.value.expose_map,
      expose_attachments: canExposeAttachments.value && form.value.expose_attachments,
      password: passwordPolicy.value === 'off' ? '' : form.value.password,
      expires_at: form.value.expires_at,
      row_filter: form.value.row_filter,
    })
    showCreate.value = false
    await loadLinks()
  } catch (e) {
    createError.value = apiError(e, t('shares.createError'))
  } finally {
    creating.value = false
  }
}

// --- URL + copiar ---
function shareUrl(link) {
  return `${window.location.origin}/s/${link.token}`
}
const copiedId = ref(null)
async function copy(link) {
  try {
    await navigator.clipboard.writeText(shareUrl(link))
    copiedId.value = link.id
    setTimeout(() => (copiedId.value === link.id ? (copiedId.value = null) : null), 1500)
  } catch {
    /* clipboard no disponible: el usuario puede copiar a mano */
  }
}

function linkState(link) {
  if (link.revoked_at) return 'revoked'
  if (link.expired) return 'expired'
  return 'active'
}
function exposesText(link) {
  const parts = []
  if (link.expose_list) parts.push(t('shares.exposeList'))
  if (link.expose_detail) parts.push(t('shares.exposeDetail'))
  if (link.expose_map) parts.push(t('shares.exposeMap'))
  if (link.expose_attachments) parts.push(t('shares.exposeAttachments'))
  return parts.join(' · ')
}

async function revoke(link) {
  const ok = await confirmDialog({
    title: t('shares.confirmRevokeTitle'),
    message: t('shares.confirmRevoke'),
    confirmText: t('shares.revoke'),
    danger: true,
  })
  if (!ok) return
  try {
    await api.delete(`/admin/shares/${link.id}`)
    await loadLinks()
  } catch (e) {
    error.value = apiError(e, t('shares.deleteError'))
  }
}
async function remove(link) {
  const ok = await confirmDialog({
    title: t('shares.confirmDeleteTitle'),
    message: t('shares.confirmDelete'),
    confirmText: t('shares.delete'),
    danger: true,
  })
  if (!ok) return
  try {
    await api.delete(`/admin/shares/${link.id}`, { params: { purge: 1 } })
    await loadLinks()
  } catch (e) {
    error.value = apiError(e, t('shares.deleteError'))
  }
}

// ---------- Filtro por filas (scoping) — reutiliza el endpoint scope-fields ----------
const scopeOpen = ref(false)
const scopeFields = ref([])
const scopeLoading = ref(false)
const scopeError = ref('')
const scopeConditions = ref([])
const suggestions = ref({})

const scopeFieldByKey = computed(() => {
  const m = new Map()
  for (const f of scopeFields.value) m.set(f.key, f)
  return m
})
const selectableFields = computed(() => scopeFields.value.filter((f) => !f.multi))
const conditionCount = computed(() => form.value.row_filter?.conditions?.length || 0)

async function openScope() {
  if (!form.value.form_id) return
  scopeOpen.value = true
  scopeError.value = ''
  scopeFields.value = []
  suggestions.value = {}
  scopeConditions.value = (form.value.row_filter?.conditions || []).map((c) => ({
    field: c.field,
    values: [...(c.values || [])],
  }))
  scopeLoading.value = true
  try {
    const { data } = await api.get(`/admin/forms/${form.value.form_id}/scope-fields`)
    scopeFields.value = data.data.fields
  } catch (e) {
    scopeError.value = apiError(e, t('permissions.scopeLoadError'))
  } finally {
    scopeLoading.value = false
  }
}
function addCondition() {
  scopeConditions.value.push({ field: '', values: [] })
}
function removeCondition(i) {
  scopeConditions.value.splice(i, 1)
}
function fieldOptions(field) {
  const f = scopeFieldByKey.value.get(field)
  return f && f.options.length ? f.options : null
}
function toggleValue(cond, value) {
  const i = cond.values.indexOf(value)
  if (i === -1) cond.values.push(value)
  else cond.values.splice(i, 1)
}
function valuesText(cond) {
  return cond.values.join('\n')
}
function setValuesText(cond, text) {
  cond.values = text.split('\n').map((v) => v.trim()).filter((v) => v !== '')
}
async function loadSuggestions(field) {
  if (!field || suggestions.value[field]) return
  try {
    const { data } = await api.get(`/admin/forms/${form.value.form_id}/scope-fields`, {
      params: { values: field },
    })
    suggestions.value = { ...suggestions.value, [field]: data.data.values }
  } catch {
    suggestions.value = { ...suggestions.value, [field]: [] }
  }
}
function addSuggestion(cond, value) {
  if (!cond.values.includes(value)) cond.values.push(value)
}
function applyScope() {
  const conditions = scopeConditions.value
    .filter((c) => c.field && c.values.length)
    .map((c) => ({ field: c.field, values: [...new Set(c.values)] }))
  form.value.row_filter = conditions.length ? { conditions } : null
  scopeOpen.value = false
}
function clearScope() {
  form.value.row_filter = null
  scopeOpen.value = false
}
// Al cambiar de formulario, el filtro de filas deja de tener sentido.
function onFormChange() {
  form.value.row_filter = null
}

onMounted(() => {
  loadForms()
  loadLinks()
})
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $t('shares.title') }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ $t('shares.subtitle') }}</p>
      </div>
      <button
        class="shrink-0 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700"
        @click="openCreate"
      >
        {{ $t('shares.new') }}
      </button>
    </header>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">
      {{ error }}
    </div>

    <div v-if="loading" class="text-sm text-slate-500">{{ $t('common.loading') }}</div>

    <div v-else class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
      <table class="w-full text-left text-sm">
        <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
          <tr>
            <th class="px-4 py-3">{{ $t('shares.colLink') }}</th>
            <th class="px-4 py-3">{{ $t('shares.colExposes') }}</th>
            <th class="px-4 py-3">{{ $t('shares.colState') }}</th>
            <th class="px-4 py-3">{{ $t('shares.colVisits') }}</th>
            <th class="px-4 py-3 text-right">{{ $t('common.actions') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="link in links" :key="link.id">
            <td class="px-4 py-3">
              <p class="font-medium text-slate-900">{{ link.label || link.form.name }}</p>
              <p class="text-xs text-slate-400">{{ link.form.name }}</p>
              <div class="mt-1 flex flex-wrap items-center gap-1.5">
                <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">/s/{{ link.token.slice(0, 10) }}…</code>
                <span v-if="link.has_password" class="text-xs text-amber-600">🔒 {{ $t('shares.withPassword') }}</span>
                <span v-if="link.row_filter" class="text-xs text-accent-700">
                  {{ $t('shares.rowFilterActive', { n: link.row_filter.conditions.length }) }}
                </span>
              </div>
            </td>
            <td class="px-4 py-3 text-slate-600">{{ exposesText(link) }}</td>
            <td class="px-4 py-3">
              <span
                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1"
                :class="{
                  'bg-green-50 text-green-700 ring-green-200': linkState(link) === 'active',
                  'bg-slate-100 text-slate-500 ring-slate-200': linkState(link) === 'revoked',
                  'bg-amber-50 text-amber-700 ring-amber-200': linkState(link) === 'expired',
                }"
              >
                {{ $t('shares.state' + linkState(link).charAt(0).toUpperCase() + linkState(link).slice(1)) }}
              </span>
              <p v-if="link.expires_at && linkState(link) === 'active'" class="mt-1 text-xs text-slate-400">
                ⌛ {{ link.expires_at }}
              </p>
            </td>
            <td class="px-4 py-3 text-slate-600">
              <p>{{ link.access_count }}</p>
              <p class="text-xs text-slate-400">
                {{ link.last_accessed_at ? $t('shares.lastAccess', { date: link.last_accessed_at }) : $t('shares.neverAccessed') }}
              </p>
            </td>
            <td class="px-4 py-3">
              <div class="flex items-center justify-end gap-1">
                <button
                  class="rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50"
                  @click="copy(link)"
                >
                  {{ copiedId === link.id ? $t('shares.copied') : $t('shares.copy') }}
                </button>
                <a
                  :href="shareUrl(link)"
                  target="_blank"
                  rel="noopener"
                  class="rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50"
                >{{ $t('shares.open') }}</a>
                <button
                  v-if="linkState(link) !== 'revoked'"
                  class="rounded-lg px-2.5 py-1 text-xs font-medium text-amber-700 hover:bg-amber-50"
                  @click="revoke(link)"
                >{{ $t('shares.revoke') }}</button>
                <button
                  class="rounded-lg px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-50"
                  @click="remove(link)"
                >{{ $t('shares.delete') }}</button>
              </div>
            </td>
          </tr>
          <tr v-if="!links.length">
            <td colspan="5" class="px-4 py-8 text-center text-slate-400">{{ $t('shares.empty') }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Modal: crear enlace -->
    <Modal v-if="showCreate" size="lg" :title="$t('shares.new')" @close="showCreate = false">
      <div class="space-y-4">
        <div v-if="createError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">
          {{ createError }}
        </div>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-slate-700">{{ $t('shares.form') }}</span>
          <select
            v-model="form.form_id"
            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
            @change="onFormChange"
          >
            <option value="">{{ $t('shares.selectForm') }}</option>
            <option v-for="f in activeForms" :key="f.id" :value="f.id">{{ f.name }} · {{ f.account_label }}</option>
          </select>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-slate-700">{{ $t('shares.label') }}</span>
          <input
            v-model="form.label"
            :placeholder="$t('shares.labelPlaceholder')"
            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
          />
        </label>

        <fieldset>
          <legend class="mb-1 text-sm font-medium text-slate-700">{{ $t('shares.exposes') }}</legend>
          <div class="flex flex-wrap gap-4">
            <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.expose_list" /> {{ $t('shares.exposeList') }}</label>
            <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.expose_detail" /> {{ $t('shares.exposeDetail') }}</label>
            <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.expose_map" /> {{ $t('shares.exposeMap') }}</label>
            <label
              v-if="attachmentsPolicy === 'require_password'"
              class="inline-flex items-center gap-2 text-sm"
              :class="canExposeAttachments ? '' : 'text-slate-400'"
            >
              <input type="checkbox" v-model="form.expose_attachments" :disabled="!canExposeAttachments" />
              {{ $t('shares.exposeAttachments') }}
            </label>
          </div>
          <p v-if="!(form.expose_list || form.expose_detail || form.expose_map)" class="mt-1 text-xs text-red-600">
            {{ $t('shares.exposeAtLeastOne') }}
          </p>
          <p v-if="attachmentsPolicy === 'require_password' && form.expose_attachments && !canExposeAttachments" class="mt-1 text-xs text-amber-600">
            {{ $t('shares.exposeAttachmentsNeedsPassword') }}
          </p>
        </fieldset>

        <!-- Filtro de filas -->
        <div class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2">
          <span class="text-sm text-slate-600">
            {{ $t('shares.rowFilter') }}:
            <strong>{{ conditionCount ? $t('shares.rowFilterActive', { n: conditionCount }) : $t('shares.rowFilterAll') }}</strong>
          </span>
          <button
            type="button"
            :disabled="!form.form_id"
            class="rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
            @click="openScope"
          >{{ $t('shares.rowFilterEdit') }}</button>
        </div>

        <!-- Contraseña (según política) -->
        <label v-if="passwordPolicy !== 'off'" class="block">
          <span class="mb-1 block text-sm font-medium text-slate-700">{{ $t('shares.password') }}</span>
          <input
            v-model="form.password"
            type="text"
            autocomplete="off"
            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
          />
          <span class="mt-1 block text-xs text-slate-400">
            {{ passwordPolicy === 'required' ? $t('shares.passwordRequired') : $t('shares.passwordOptional') }}
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-sm font-medium text-slate-700">{{ $t('shares.expiresAt') }}</span>
          <input
            v-model="form.expires_at"
            type="date"
            class="rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
          />
          <span class="mt-1 block text-xs text-slate-400">{{ $t('shares.expiresHint') }}</span>
        </label>

        <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
          <button class="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100" @click="showCreate = false">
            {{ $t('common.cancel') }}
          </button>
          <button
            :disabled="!canCreate || creating"
            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-60"
            @click="onCreate"
          >
            {{ creating ? $t('shares.creating') : $t('shares.create') }}
          </button>
        </div>
      </div>
    </Modal>

    <!-- Modal: filtro por filas (reutiliza claves de permissions.scope*) -->
    <Modal v-if="scopeOpen" size="xl" :title="$t('permissions.scopeTitle', { form: '' })" @close="scopeOpen = false">
      <div class="space-y-4">
        <p class="text-sm text-slate-500">{{ $t('permissions.scopeIntro') }}</p>
        <div v-if="scopeError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">{{ scopeError }}</div>
        <div v-if="scopeLoading" class="text-sm text-slate-500">{{ $t('common.loading') }}</div>

        <template v-else>
          <div v-if="!scopeConditions.length" class="rounded-lg bg-slate-50 px-3 py-3 text-sm text-slate-500">
            {{ $t('permissions.scopeNoConditions') }}
          </div>

          <div v-for="(cond, i) in scopeConditions" :key="i" class="space-y-2 rounded-lg border border-slate-200 p-3">
            <div class="flex items-center gap-2">
              <select
                v-model="cond.field"
                class="min-w-0 flex-1 truncate rounded-lg border border-slate-300 px-3 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
                @change="cond.values = []; loadSuggestions(cond.field)"
              >
                <option value="">{{ $t('permissions.scopeSelectField') }}</option>
                <optgroup :label="$t('permissions.scopeMetaGroup')">
                  <option value="_submitted_by">{{ $t('permissions.scopeFieldSubmittedBy') }}</option>
                </optgroup>
                <optgroup :label="$t('permissions.scopeFieldsGroup')">
                  <option v-for="f in selectableFields" :key="f.key" :value="f.key">{{ f.label }}</option>
                </optgroup>
              </select>
              <button type="button" class="rounded-lg px-2 py-1 text-xs text-red-600 hover:bg-red-50" @click="removeCondition(i)">
                {{ $t('permissions.scopeRemove') }}
              </button>
            </div>

            <div v-if="cond.field">
              <p class="mb-1 text-xs font-medium text-slate-600">{{ $t('permissions.scopeValues') }}</p>
              <div v-if="fieldOptions(cond.field)" class="flex flex-wrap gap-2">
                <label
                  v-for="opt in fieldOptions(cond.field)"
                  :key="opt.value"
                  class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-1 text-sm"
                  :class="cond.values.includes(opt.value) ? 'bg-accent-50 border-accent-300' : ''"
                >
                  <input type="checkbox" :checked="cond.values.includes(opt.value)" @change="toggleValue(cond, opt.value)" />
                  <span>{{ opt.label }} <span class="text-slate-400">({{ opt.value }})</span></span>
                </label>
              </div>
              <div v-else class="space-y-1.5">
                <textarea
                  :value="valuesText(cond)"
                  rows="3"
                  class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
                  :placeholder="$t('permissions.scopeValuesHint')"
                  @input="setValuesText(cond, $event.target.value)"
                ></textarea>
                <div class="flex flex-wrap items-center gap-1.5">
                  <button type="button" class="rounded-md bg-slate-100 px-2 py-0.5 text-xs text-slate-600 hover:bg-slate-200" @click="loadSuggestions(cond.field)">
                    {{ $t('permissions.scopeSuggest') }}
                  </button>
                  <button
                    v-for="s in suggestions[cond.field] || []"
                    :key="s"
                    type="button"
                    class="rounded-md bg-primary-50 px-2 py-0.5 text-xs text-primary-700 hover:bg-primary-100"
                    @click="addSuggestion(cond, s)"
                  >+ {{ s }}</button>
                  <span v-if="suggestions[cond.field] && !suggestions[cond.field].length" class="text-xs text-slate-400">
                    {{ $t('permissions.scopeNoSuggest') }}
                  </span>
                </div>
              </div>
            </div>
          </div>

          <button type="button" class="rounded-lg border border-dashed border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50" @click="addCondition">
            + {{ $t('permissions.scopeAddCondition') }}
          </button>
        </template>

        <div class="flex items-center justify-between gap-3 border-t border-slate-100 pt-4">
          <button type="button" class="rounded-lg px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50" @click="clearScope">
            {{ $t('permissions.scopeClear') }}
          </button>
          <div class="flex gap-2">
            <button type="button" class="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100" @click="scopeOpen = false">
              {{ $t('common.cancel') }}
            </button>
            <button type="button" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700" @click="applyScope">
              {{ $t('permissions.scopeApply') }}
            </button>
          </div>
        </div>
      </div>
    </Modal>
  </div>
</template>
