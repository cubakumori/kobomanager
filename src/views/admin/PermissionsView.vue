<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import api from '../../services/api'
import { apiError } from '../../stores/auth'
import Modal from '../../components/Modal.vue'
import RowFilterEditor from '../../components/RowFilterEditor.vue'

const { t } = useI18n()
const route = useRoute()

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
const rowEditor = ref(null) // ref al componente RowFilterEditor

// Nº total de condiciones del filtro (suma de los grupos; soporta formato antiguo).
function conditionCount(p) {
  const rf = p.row_filter
  if (!rf) return 0
  if (Array.isArray(rf.conditions)) return rf.conditions.length // formato antiguo
  return (rf.groups || []).reduce((n, g) => n + (g.conditions?.length || 0), 0)
}

function openScope(p) {
  scopeForm.value = p
  scopeOpen.value = true
}

function applyScope() {
  scopeForm.value.row_filter = rowEditor.value?.getValue() ?? null
  saved.value = false
  scopeOpen.value = false
}
function clearScope() {
  scopeForm.value.row_filter = null
  saved.value = false
  scopeOpen.value = false
}

// ---------- Permisos por columna (ocultar campos) ----------
const colsOpen = ref(false)
const colsForm = ref(null)
const colsFields = ref([]) // todos los campos del formulario (clave, etiqueta, tipo)
const colsLoading = ref(false)
const colsError = ref('')
const colsHidden = ref([]) // copia de trabajo: claves ocultas

function hiddenCount(p) {
  return p.field_filter?.hidden?.length || 0
}

async function openCols(p) {
  colsForm.value = p
  colsOpen.value = true
  colsError.value = ''
  colsFields.value = []
  colsHidden.value = [...(p.field_filter?.hidden || [])]
  colsLoading.value = true
  try {
    const { data } = await api.get(`/admin/forms/${p.form_id}/scope-fields`)
    colsFields.value = data.data.fields
  } catch (e) {
    colsError.value = apiError(e, t('permissions.colsLoadError'))
  } finally {
    colsLoading.value = false
  }
}

function toggleHidden(key) {
  const i = colsHidden.value.indexOf(key)
  if (i === -1) colsHidden.value.push(key)
  else colsHidden.value.splice(i, 1)
}

function applyCols() {
  const hidden = [...new Set(colsHidden.value)]
  colsForm.value.field_filter = hidden.length ? { hidden } : null
  saved.value = false
  colsOpen.value = false
}
function clearCols() {
  colsForm.value.field_filter = null
  saved.value = false
  colsOpen.value = false
}

onMounted(async () => {
  await loadUsers()
  // Deep-link desde admin/users → «Permisos»: ?user=ID preselecciona y carga sus permisos.
  const qUser = route.query.user
  if (qUser && users.value.some((u) => String(u.id) === String(qUser))) {
    selectedUserId.value = String(qUser)
    loadPerms()
  }
})
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
      class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200"
    >
      <div v-if="loadingPerms" class="p-4 text-sm text-slate-500">{{ $t('common.loading') }}</div>
      <template v-else>
        <table class="w-full whitespace-nowrap text-left text-sm">
          <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
            <tr>
              <th class="px-4 py-3">{{ $t('permissions.colForm') }}</th>
              <th class="px-4 py-3 text-center">{{ $t('permissions.colView') }}</th>
              <th class="px-4 py-3 text-center">{{ $t('permissions.colEdit') }}</th>
              <th class="px-4 py-3 text-center">{{ $t('permissions.colValidate') }}</th>
              <th class="px-4 py-3 text-center">{{ $t('permissions.colScope') }}</th>
              <th class="px-4 py-3 text-center">{{ $t('permissions.colColumns') }}</th>
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
              <td class="px-4 py-3 text-center">
                <button
                  v-if="p.can_view"
                  type="button"
                  class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium ring-1 transition"
                  :class="hiddenCount(p)
                    ? 'bg-accent-50 text-accent-700 ring-accent-200 hover:bg-accent-100'
                    : 'bg-slate-50 text-slate-500 ring-slate-200 hover:bg-slate-100'"
                  @click="openCols(p)"
                >
                  {{ hiddenCount(p)
                    ? $t('permissions.colsHidden', { n: hiddenCount(p) })
                    : $t('permissions.colsAll') }}
                </button>
                <span v-else class="text-slate-300">—</span>
              </td>
            </tr>
            <tr v-if="!visiblePerms.length">
              <td colspan="6" class="px-4 py-6 text-center text-slate-400">{{ $t('permissions.empty') }}</td>
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
          <span v-if="saved" class="text-sm text-success-600">{{ $t('common.saved') }}</span>
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
        <RowFilterEditor
          ref="rowEditor"
          :form-id="scopeForm.form_id"
          :model-value="scopeForm.row_filter"
        />

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

    <!-- Modal: permisos por columna (ocultar campos) -->
    <Modal
      v-if="colsOpen"
      size="xl"
      :title="$t('permissions.colsTitle', { form: colsForm?.name })"
      @close="colsOpen = false"
    >
      <div class="space-y-4">
        <p class="text-sm text-slate-500">{{ $t('permissions.colsIntro') }}</p>

        <div v-if="colsError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">
          {{ colsError }}
        </div>
        <div v-if="colsLoading" class="text-sm text-slate-500">{{ $t('common.loading') }}</div>

        <template v-else>
          <div v-if="!colsFields.length" class="rounded-lg bg-slate-50 px-3 py-3 text-sm text-slate-500">
            {{ $t('permissions.colsNoFields') }}
          </div>
          <template v-else>
            <p class="text-xs text-slate-500">
              {{ $t('permissions.colsSelected', { n: colsHidden.length }) }}
            </p>
            <div class="max-h-96 space-y-1 overflow-y-auto rounded-lg border border-slate-200 p-2">
              <label
                v-for="f in colsFields"
                :key="f.key"
                class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-slate-50"
                :class="colsHidden.includes(f.key) ? 'bg-accent-50' : ''"
              >
                <input
                  type="checkbox"
                  :checked="colsHidden.includes(f.key)"
                  @change="toggleHidden(f.key)"
                />
                <span class="min-w-0 flex-1 truncate">{{ f.label }}</span>
                <span class="shrink-0 text-xs text-slate-400">{{ f.type }}</span>
              </label>
            </div>
          </template>
        </template>

        <div class="flex items-center justify-between gap-3 border-t border-slate-100 pt-4">
          <button
            type="button"
            class="rounded-lg px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50"
            @click="clearCols"
          >
            {{ $t('permissions.colsClear') }}
          </button>
          <div class="flex gap-2">
            <button
              type="button"
              class="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100"
              @click="colsOpen = false"
            >
              {{ $t('common.cancel') }}
            </button>
            <button
              type="button"
              class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700"
              @click="applyCols"
            >
              {{ $t('permissions.colsApply') }}
            </button>
          </div>
        </div>
      </div>
    </Modal>
  </div>
</template>
