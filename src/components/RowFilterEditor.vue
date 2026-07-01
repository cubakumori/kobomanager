<script setup>
/**
 * Editor del filtro por filas (scoping multi-condición AND/OR + operadores).
 *
 * Reutilizado por PermissionsView (por usuario+formulario) y SharesView (por enlace).
 * El padre envuelve este editor en un Modal y, al «Aplicar», lee el resultado con
 * `getValue()` (expuesto). La forma producida es la canónica de lib/RowScope:
 *   { match:'all|any', groups:[ { match:'all|any',
 *       conditions:[ {field, op, values:[...] } ] } ] }  | null
 */
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../services/api'
import { apiError } from '../stores/auth'

const props = defineProps({
  formId: { type: [Number, String], required: true },
  modelValue: { type: Object, default: null }, // row_filter actual (canónico o antiguo) | null
  // Endpoint de campos/valores. Por defecto el de admin (Permisos/Enlaces); el filtro
  // avanzado de la tabla pasa el de usuario (`/forms/{id}/scope-fields`), que excluye
  // los campos ocultos y acota los valores sugeridos al alcance del usuario.
  fieldsUrl: { type: String, default: '' },
  // Campo a EXCLUIR del selector (creación de enlaces en lote: el campo distintivo
  // lo fija el propio lote, así que no debe poder añadirse aquí).
  excludeField: { type: String, default: '' },
  // Fuerza el conector raíz a Y y oculta su selector (creación en lote: el filtro
  // base debe combinarse en AND con la condición distintiva).
  forceRootAll: { type: Boolean, default: false },
})

const fieldsEndpoint = () => props.fieldsUrl || `/admin/forms/${props.formId}/scope-fields`

const { t } = useI18n()

const RANGE_OPS = ['lt', 'lte', 'gt', 'gte']
const NOVAL_OPS = ['empty', 'not_empty']
const SET_OPS = ['has_any', 'has_all', 'has_none']

const fields = ref([])
const loading = ref(false)
const error = ref('')
const suggestions = ref({}) // { [field]: string[] }

// Copia de trabajo.
const rootMatch = ref('all')
const groups = ref([]) // [{ match, conditions:[{ field, op, values:[] }] }]

const fieldByKey = computed(() => {
  const m = new Map()
  for (const f of fields.value) m.set(f.key, f)
  return m
})

// Campos ofrecidos en el selector (todos menos el excluido, si lo hay).
const selectableFields = computed(() =>
  props.excludeField ? fields.value.filter((f) => f.key !== props.excludeField) : fields.value,
)

// ---------- seed / carga ----------

function normalizeIncoming(mv) {
  if (!mv || typeof mv !== 'object') return null
  // Formato antiguo {conditions:[...]} (solo-AND, op implícito 'in').
  if (Array.isArray(mv.conditions) && !mv.groups) {
    return {
      match: 'all',
      groups: [{
        match: 'all',
        conditions: mv.conditions.map((c) => ({ field: c.field, op: c.op || 'in', values: [...(c.values || [])] })),
      }],
    }
  }
  if (!Array.isArray(mv.groups)) return null
  return {
    match: mv.match === 'any' ? 'any' : 'all',
    groups: mv.groups.map((g) => ({
      match: g.match === 'any' ? 'any' : 'all',
      conditions: (g.conditions || []).map((c) => ({ field: c.field, op: c.op || 'in', values: [...(c.values || [])] })),
    })),
  }
}

function seed() {
  const r = normalizeIncoming(props.modelValue)
  if (!r) {
    rootMatch.value = 'all'
    groups.value = []
    return
  }
  rootMatch.value = props.forceRootAll ? 'all' : r.match
  groups.value = r.groups.map((g) => ({
    match: g.match,
    conditions: g.conditions.map((c) => ({ field: c.field, op: c.op, values: [...c.values] })),
  }))
}

onMounted(async () => {
  seed()
  loading.value = true
  try {
    const { data } = await api.get(fieldsEndpoint())
    fields.value = data.data.fields
  } catch (e) {
    error.value = apiError(e, t('rowfilter.loadError'))
  } finally {
    loading.value = false
  }
})

// ---------- estructura ----------

function blankCondition() {
  return { field: '', op: 'in', values: [] }
}
function addGroup() {
  groups.value.push({ match: 'all', conditions: [blankCondition()] })
}
function removeGroup(gi) {
  groups.value.splice(gi, 1)
}
function addCondition(gi) {
  groups.value[gi].conditions.push(blankCondition())
}
function removeCondition(gi, ci) {
  groups.value[gi].conditions.splice(ci, 1)
  if (!groups.value[gi].conditions.length) removeGroup(gi)
}

// ---------- operadores / widgets por tipo ----------

function isNumeric(meta) {
  return meta && ['integer', 'decimal'].includes(meta.type)
}
function isDate(meta) {
  return meta && (meta.type === 'date' || meta.type === 'datetime' || meta.type.startsWith('date'))
}

// Operadores disponibles para el campo de una condición.
function availableOps(field) {
  const meta = fieldByKey.value.get(field)
  if (!meta) return ['in', 'nin', 'empty', 'not_empty'] // metadato (p. ej. _submitted_by)
  if (meta.multi) return ['has_any', 'has_all', 'has_none', 'empty', 'not_empty']
  if (meta.options && meta.options.length) return ['in', 'nin', 'empty', 'not_empty']
  if (isNumeric(meta)) return ['in', 'nin', 'lt', 'lte', 'gt', 'gte', 'empty', 'not_empty']
  if (isDate(meta)) return ['lt', 'lte', 'gt', 'gte', 'in', 'nin', 'empty', 'not_empty']
  return ['in', 'nin', 'empty', 'not_empty']
}

function onFieldChange(cond) {
  const ops = availableOps(cond.field)
  cond.op = ops[0] || 'in'
  cond.values = []
  if (cond.field) loadSuggestions(cond.field)
}
function onOpChange(cond) {
  // Al cambiar de tipo de operador, reinicia valores (el widget cambia).
  cond.values = []
}

// Opciones (select_one / select_multiple) del campo, o null si es texto libre.
function fieldOptions(field) {
  const f = fieldByKey.value.get(field)
  return f && f.options && f.options.length ? f.options : null
}
function showOptions(cond) {
  return (['in', 'nin'].includes(cond.op) || SET_OPS.includes(cond.op)) && fieldOptions(cond.field)
}
function showFreeText(cond) {
  return ['in', 'nin'].includes(cond.op) && !fieldOptions(cond.field)
}
function showRange(cond) {
  return RANGE_OPS.includes(cond.op)
}
function rangeInputType(cond) {
  const meta = fieldByKey.value.get(cond.field)
  if (isNumeric(meta)) return 'number'
  if (isDate(meta)) return 'date'
  return 'text'
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
function rangeValue(cond) {
  return cond.values[0] ?? ''
}
function setRangeValue(cond, val) {
  cond.values = val === '' ? [] : [String(val)]
}

async function loadSuggestions(field) {
  if (!field || suggestions.value[field]) return
  try {
    const { data } = await api.get(fieldsEndpoint(), { params: { values: field } })
    suggestions.value = { ...suggestions.value, [field]: data.data.values }
  } catch {
    suggestions.value = { ...suggestions.value, [field]: [] }
  }
}
function addSuggestion(cond, value) {
  if (!cond.values.includes(value)) cond.values.push(value)
}

// ---------- salida ----------

function condHasValue(c) {
  if (NOVAL_OPS.includes(c.op)) return true
  return c.values.filter((v) => v !== '' && v !== null && v !== undefined).length > 0
}
function cleanLeaf(c) {
  if (NOVAL_OPS.includes(c.op)) return { field: c.field, op: c.op, values: [] }
  if (RANGE_OPS.includes(c.op)) return { field: c.field, op: c.op, values: [String(c.values[0]).trim()] }
  return { field: c.field, op: c.op, values: [...new Set(c.values.filter((v) => v !== ''))] }
}

/** Devuelve el row_filter canónico (o null si no hay condiciones útiles). */
function getValue() {
  const gs = groups.value
    .map((g) => ({
      match: g.match === 'any' ? 'any' : 'all',
      conditions: g.conditions.filter((c) => c.field && condHasValue(c)).map(cleanLeaf),
    }))
    .filter((g) => g.conditions.length)
  if (!gs.length) return null
  return { match: rootMatch.value === 'any' ? 'any' : 'all', groups: gs }
}

defineExpose({ getValue })
</script>

<template>
  <div class="space-y-4">
    <p class="text-sm text-slate-500">{{ $t('rowfilter.intro') }}</p>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900">
      {{ error }}
    </div>
    <div v-if="loading" class="text-sm text-slate-500">{{ $t('common.loading') }}</div>

    <template v-else>
      <div v-if="!groups.length" class="rounded-lg bg-slate-50 px-3 py-3 text-sm text-slate-500">
        {{ $t('rowfilter.noFilter') }}
      </div>

      <!-- Conector raíz entre grupos (solo si hay más de uno y no está forzado a Y) -->
      <div v-if="groups.length > 1 && !forceRootAll" class="flex items-center gap-2 text-sm">
        <span class="text-slate-600">{{ $t('rowfilter.betweenGroups') }}</span>
        <select
          v-model="rootMatch"
          class="rounded-lg border border-slate-300 px-2 py-1 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
        >
          <option value="all">{{ $t('rowfilter.rootAll') }}</option>
          <option value="any">{{ $t('rowfilter.rootAny') }}</option>
        </select>
      </div>

      <div
        v-for="(g, gi) in groups"
        :key="gi"
        class="space-y-3 rounded-xl border border-slate-200 p-3"
      >
        <div class="flex items-center justify-between gap-2">
          <div class="flex items-center gap-2 text-sm">
            <span class="text-slate-600">{{ $t('rowfilter.withinGroup') }}</span>
            <select
              v-model="g.match"
              class="rounded-lg border border-slate-300 px-2 py-1 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
            >
              <option value="all">{{ $t('rowfilter.groupAll') }}</option>
              <option value="any">{{ $t('rowfilter.groupAny') }}</option>
            </select>
          </div>
          <button
            type="button"
            class="rounded-lg px-2 py-1 text-xs text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/40"
            @click="removeGroup(gi)"
          >
            {{ $t('rowfilter.removeGroup') }}
          </button>
        </div>

        <div
          v-for="(cond, ci) in g.conditions"
          :key="ci"
          class="space-y-2 rounded-lg border border-slate-200 bg-slate-50/50 p-3"
        >
          <div class="flex flex-wrap items-center gap-2">
            <!-- Campo -->
            <select
              v-model="cond.field"
              class="min-w-0 flex-1 truncate rounded-lg border border-slate-300 px-3 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
              @change="onFieldChange(cond)"
            >
              <option value="">{{ $t('rowfilter.selectField') }}</option>
              <optgroup :label="$t('rowfilter.metaGroup')">
                <option value="_submitted_by">{{ $t('rowfilter.submittedBy') }}</option>
              </optgroup>
              <optgroup :label="$t('rowfilter.fieldsGroup')">
                <option v-for="f in selectableFields" :key="f.key" :value="f.key">{{ f.label }}</option>
              </optgroup>
            </select>

            <!-- Operador -->
            <select
              v-if="cond.field"
              v-model="cond.op"
              class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
              @change="onOpChange(cond)"
            >
              <option v-for="op in availableOps(cond.field)" :key="op" :value="op">
                {{ $t('rowfilter.ops.' + op) }}
              </option>
            </select>

            <button
              type="button"
              class="rounded-lg px-2 py-1 text-xs text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/40"
              @click="removeCondition(gi, ci)"
            >
              {{ $t('rowfilter.removeCondition') }}
            </button>
          </div>

          <!-- Valor según operador -->
          <div v-if="cond.field && !NOVAL_OPS.includes(cond.op)">
            <!-- Opciones (select_one / select_multiple): checkboxes con etiqueta -->
            <div v-if="showOptions(cond)" class="flex flex-wrap gap-2">
              <label
                v-for="opt in fieldOptions(cond.field)"
                :key="opt.value"
                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-sm"
                :class="cond.values.includes(opt.value) ? 'bg-accent-50 border-accent-300 dark:bg-accent-900/40 dark:border-accent-700' : ''"
              >
                <input
                  type="checkbox"
                  :checked="cond.values.includes(opt.value)"
                  @change="toggleValue(cond, opt.value)"
                />
                <span>{{ opt.label }} <span class="text-slate-400">({{ opt.value }})</span></span>
              </label>
            </div>

            <!-- Rango: un único operando -->
            <div v-else-if="showRange(cond)">
              <input
                :type="rangeInputType(cond)"
                :value="rangeValue(cond)"
                step="any"
                class="w-48 rounded-lg border border-slate-300 px-3 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
                :placeholder="$t('rowfilter.boundHint')"
                @input="setRangeValue(cond, $event.target.value)"
              />
            </div>

            <!-- Texto libre / metadatos: in/nin -->
            <div v-else-if="showFreeText(cond)" class="space-y-1.5">
              <textarea
                :value="valuesText(cond)"
                rows="3"
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
                :placeholder="$t('rowfilter.valuesHint')"
                @input="setValuesText(cond, $event.target.value)"
              ></textarea>
              <div class="flex flex-wrap items-center gap-1.5">
                <button
                  type="button"
                  class="rounded-md bg-slate-100 px-2 py-0.5 text-xs text-slate-600 hover:bg-slate-200"
                  @click="loadSuggestions(cond.field)"
                >
                  {{ $t('rowfilter.suggest') }}
                </button>
                <button
                  v-for="s in suggestions[cond.field] || []"
                  :key="s"
                  type="button"
                  class="rounded-md bg-primary-50 px-2 py-0.5 text-xs text-primary-700 hover:bg-primary-100 dark:bg-primary-900/30 dark:text-primary-300 dark:hover:bg-primary-900/50"
                  @click="addSuggestion(cond, s)"
                >
                  + {{ s }}
                </button>
                <span
                  v-if="suggestions[cond.field] && !suggestions[cond.field].length"
                  class="text-xs text-slate-400"
                >
                  {{ $t('rowfilter.noSuggest') }}
                </span>
              </div>
            </div>
          </div>
        </div>

        <button
          type="button"
          class="rounded-lg border border-dashed border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:bg-white"
          @click="addCondition(gi)"
        >
          + {{ $t('rowfilter.addCondition') }}
        </button>
      </div>

      <button
        type="button"
        class="rounded-lg border border-dashed border-primary-300 px-3 py-1.5 text-sm font-medium text-primary-700 hover:bg-primary-50 dark:hover:bg-primary-900/30"
        @click="addGroup"
      >
        + {{ $t('rowfilter.addGroup') }}
      </button>
    </template>
  </div>
</template>
