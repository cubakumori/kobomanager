<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../../services/api'
import { apiError } from '../../stores/auth'
import Modal from '../../components/Modal.vue'

const { t } = useI18n()

const users = ref([])
const selectedUserId = ref('')
const perms = ref([])

const loadingPerms = ref(false)
const error = ref('')
const saving = ref(false)
const saved = ref(false)

// Filtro por cuenta (solo afecta a lo mostrado; al guardar se envían todos).
const selectedAccount = ref('')
const accounts = computed(() => {
  const map = new Map()
  for (const p of perms.value) map.set(p.account_id, p.account_label)
  return [...map].map(([id, label]) => ({ id, label }))
})
const visiblePerms = computed(() =>
  selectedAccount.value === ''
    ? perms.value
    : perms.value.filter((p) => p.account_id === Number(selectedAccount.value)),
)

async function loadUsers() {
  try {
    const { data } = await api.get('/admin/users')
    users.value = data.data
  } catch (e) {
    error.value = apiError(e, t('permissions.loadUsersError'))
  }
}

async function loadPerms() {
  saved.value = false
  if (!selectedUserId.value) {
    perms.value = []
    return
  }
  loadingPerms.value = true
  error.value = ''
  try {
    const { data } = await api.get('/admin/permissions', { params: { user_id: selectedUserId.value } })
    perms.value = data.data
  } catch (e) {
    error.value = apiError(e, t('permissions.loadError'))
  } finally {
    loadingPerms.value = false
  }
}

async function onSave() {
  saving.value = true
  saved.value = false
  error.value = ''
  try {
    await api.put('/admin/permissions', {
      user_id: Number(selectedUserId.value),
      permissions: perms.value,
    })
    saved.value = true
  } catch (e) {
    error.value = apiError(e, t('permissions.saveError'))
  } finally {
    saving.value = false
  }
}

function onToggle(p) {
  if (p.can_edit || p.can_validate) p.can_view = true
  saved.value = false
}

// ---------- Filtro por filas (scoping) ----------
const scopeOpen = ref(false)
const scopeForm = ref(null) // la fila de permiso en edición
const scopeFields = ref([]) // campos filtrables del formulario
const scopeLoading = ref(false)
const scopeError = ref('')
const scopeConditions = ref([]) // copia de trabajo: [{ field, values: [] }]
const suggestions = ref({}) // { [field]: string[] } valores sugeridos desde la caché

function conditionCount(p) {
  return p.row_filter?.conditions?.length || 0
}

const scopeFieldByKey = computed(() => {
  const m = new Map()
  for (const f of scopeFields.value) m.set(f.key, f)
  return m
})

// Campos ofrecidos en el selector: del formulario (sin select_multiple) + metadatos.
const selectableFields = computed(() =>
  scopeFields.value.filter((f) => !f.multi),
)

async function openScope(p) {
  scopeForm.value = p
  scopeOpen.value = true
  scopeError.value = ''
  scopeFields.value = []
  suggestions.value = {}
  // Copia profunda de las condiciones actuales para editarlas sin tocar el original.
  scopeConditions.value = (p.row_filter?.conditions || []).map((c) => ({
    field: c.field,
    values: [...(c.values || [])],
  }))
  scopeLoading.value = true
  try {
    const { data } = await api.get(`/admin/forms/${p.form_id}/scope-fields`)
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

// Opciones (select_one) del campo de una condición, o null si es texto libre.
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
  cond.values = text
    .split('\n')
    .map((v) => v.trim())
    .filter((v) => v !== '')
}

async function loadSuggestions(field) {
  if (!field || suggestions.value[field]) return
  try {
    const { data } = await api.get(`/admin/forms/${scopeForm.value.form_id}/scope-fields`, {
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
  scopeForm.value.row_filter = conditions.length ? { conditions } : null
  saved.value = false
  scopeOpen.value = false
}
function clearScope() {
  scopeForm.value.row_filter = null
  saved.value = false
  scopeOpen.value = false
}

onMounted(loadUsers)
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $t('permissions.title') }}</h1>
      <p class="mt-1 text-sm text-slate-500">{{ $t('permissions.subtitle') }}</p>
    </header>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">
      {{ error }}
    </div>

    <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
      <div class="flex items-center gap-2">
        <label class="text-sm text-slate-600">{{ $t('permissions.userFilter') }}</label>
        <select
          v-model="selectedUserId"
          class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
          @change="loadPerms"
        >
          <option value="">{{ $t('permissions.selectUser') }}</option>
          <option v-for="u in users" :key="u.id" :value="u.id">
            {{ u.name }} ({{ u.email }}) · {{ u.role }}
          </option>
        </select>
      </div>
      <div v-if="selectedUserId && accounts.length" class="flex items-center gap-2">
        <label class="text-sm text-slate-600">{{ $t('permissions.accountFilter') }}</label>
        <select
          v-model="selectedAccount"
          class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
        >
          <option value="">{{ $t('permissions.allAccounts') }}</option>
          <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.label }}</option>
        </select>
      </div>
    </div>

    <div
      v-if="selectedUserId"
      class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200"
    >
      <div v-if="loadingPerms" class="p-4 text-sm text-slate-500">{{ $t('common.loading') }}</div>
      <template v-else>
        <table class="w-full text-left text-sm">
          <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
            <tr>
              <th class="px-4 py-3">{{ $t('permissions.colForm') }}</th>
              <th class="px-4 py-3 text-center">{{ $t('permissions.colView') }}</th>
              <th class="px-4 py-3 text-center">{{ $t('permissions.colEdit') }}</th>
              <th class="px-4 py-3 text-center">{{ $t('permissions.colValidate') }}</th>
              <th class="px-4 py-3 text-center">{{ $t('permissions.colScope') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="p in visiblePerms" :key="p.form_id">
              <td class="px-4 py-3">
                <p class="font-medium text-slate-900">{{ p.name }}</p>
                <p class="text-xs text-slate-400">{{ p.account_label }}</p>
              </td>
              <td class="px-4 py-3 text-center">
                <input type="checkbox" v-model="p.can_view" @change="saved = false" />
              </td>
              <td class="px-4 py-3 text-center">
                <input type="checkbox" v-model="p.can_edit" @change="onToggle(p)" />
              </td>
              <td class="px-4 py-3 text-center">
                <input type="checkbox" v-model="p.can_validate" @change="onToggle(p)" />
              </td>
              <td class="px-4 py-3 text-center">
                <button
                  v-if="p.can_view"
                  type="button"
                  class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium ring-1 transition"
                  :class="conditionCount(p)
                    ? 'bg-accent-50 text-accent-700 ring-accent-200 hover:bg-accent-100'
                    : 'bg-slate-50 text-slate-500 ring-slate-200 hover:bg-slate-100'"
                  @click="openScope(p)"
                >
                  {{ conditionCount(p)
                    ? $t('permissions.scopeFiltered', { n: conditionCount(p) })
                    : $t('permissions.scopeAll') }}
                </button>
                <span v-else class="text-slate-300">—</span>
              </td>
            </tr>
            <tr v-if="!visiblePerms.length">
              <td colspan="5" class="px-4 py-6 text-center text-slate-400">{{ $t('permissions.empty') }}</td>
            </tr>
          </tbody>
        </table>

        <div v-if="perms.length" class="flex items-center gap-3 border-t border-slate-100 p-4">
          <button
            :disabled="saving"
            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-60"
            @click="onSave"
          >
            {{ saving ? $t('common.saving') : $t('permissions.save') }}
          </button>
          <span v-if="saved" class="text-sm text-green-600">{{ $t('common.saved') }}</span>
        </div>
      </template>
    </div>

    <!-- Modal: filtro por filas -->
    <Modal
      v-if="scopeOpen"
      size="xl"
      :title="$t('permissions.scopeTitle', { form: scopeForm?.name })"
      @close="scopeOpen = false"
    >
      <div class="space-y-4">
        <p class="text-sm text-slate-500">{{ $t('permissions.scopeIntro') }}</p>

        <div v-if="scopeError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">
          {{ scopeError }}
        </div>
        <div v-if="scopeLoading" class="text-sm text-slate-500">{{ $t('common.loading') }}</div>

        <template v-else>
          <div v-if="!scopeConditions.length" class="rounded-lg bg-slate-50 px-3 py-3 text-sm text-slate-500">
            {{ $t('permissions.scopeNoConditions') }}
          </div>

          <div
            v-for="(cond, i) in scopeConditions"
            :key="i"
            class="space-y-2 rounded-lg border border-slate-200 p-3"
          >
            <div class="flex items-center gap-2">
              <select
                v-model="cond.field"
                class="flex-1 rounded-lg border border-slate-300 px-3 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
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
              <button
                type="button"
                class="rounded-lg px-2 py-1 text-xs text-red-600 hover:bg-red-50"
                @click="removeCondition(i)"
              >
                {{ $t('permissions.scopeRemove') }}
              </button>
            </div>

            <div v-if="cond.field">
              <p class="mb-1 text-xs font-medium text-slate-600">{{ $t('permissions.scopeValues') }}</p>

              <!-- select_one: opciones con etiqueta -->
              <div v-if="fieldOptions(cond.field)" class="flex flex-wrap gap-2">
                <label
                  v-for="opt in fieldOptions(cond.field)"
                  :key="opt.value"
                  class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-1 text-sm"
                  :class="cond.values.includes(opt.value) ? 'bg-accent-50 border-accent-300' : ''"
                >
                  <input
                    type="checkbox"
                    :checked="cond.values.includes(opt.value)"
                    @change="toggleValue(cond, opt.value)"
                  />
                  <span>{{ opt.label }} <span class="text-slate-400">({{ opt.value }})</span></span>
                </label>
              </div>

              <!-- texto libre / metadatos -->
              <div v-else class="space-y-1.5">
                <textarea
                  :value="valuesText(cond)"
                  rows="3"
                  class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
                  :placeholder="$t('permissions.scopeValuesHint')"
                  @input="setValuesText(cond, $event.target.value)"
                ></textarea>
                <div class="flex flex-wrap items-center gap-1.5">
                  <button
                    type="button"
                    class="rounded-md bg-slate-100 px-2 py-0.5 text-xs text-slate-600 hover:bg-slate-200"
                    @click="loadSuggestions(cond.field)"
                  >
                    {{ $t('permissions.scopeSuggest') }}
                  </button>
                  <button
                    v-for="s in suggestions[cond.field] || []"
                    :key="s"
                    type="button"
                    class="rounded-md bg-primary-50 px-2 py-0.5 text-xs text-primary-700 hover:bg-primary-100"
                    @click="addSuggestion(cond, s)"
                  >
                    + {{ s }}
                  </button>
                  <span
                    v-if="suggestions[cond.field] && !suggestions[cond.field].length"
                    class="text-xs text-slate-400"
                  >
                    {{ $t('permissions.scopeNoSuggest') }}
                  </span>
                </div>
              </div>
            </div>
          </div>

          <button
            type="button"
            class="rounded-lg border border-dashed border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50"
            @click="addCondition"
          >
            + {{ $t('permissions.scopeAddCondition') }}
          </button>
        </template>

        <div class="flex items-center justify-between gap-3 border-t border-slate-100 pt-4">
          <button
            type="button"
            class="rounded-lg px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50"
            @click="clearScope"
          >
            {{ $t('permissions.scopeClear') }}
          </button>
          <div class="flex gap-2">
            <button
              type="button"
              class="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100"
              @click="scopeOpen = false"
            >
              {{ $t('common.cancel') }}
            </button>
            <button
              type="button"
              class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700"
              @click="applyScope"
            >
              {{ $t('permissions.scopeApply') }}
            </button>
          </div>
        </div>
      </div>
    </Modal>
  </div>
</template>
