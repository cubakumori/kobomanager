<script setup>
/**
 * Editor del PLAN de muestra por equipo (sección de los ajustes del formulario).
 * Elige el campo de muestreo (select_one) y los secundarios, el denominador de
 * «hecho», y rellena la matriz equipo × valor → objetivo. Para no rellenar celda a
 * celda: un total por equipo con reparto (uniforme o proporcional a lo recibido) y
 * ajuste fino por celda. Guarda contra PUT /admin/forms/{id}/sample-plan, que
 * reemplaza el plan vigente y archiva un snapshot.
 *
 * El eje de EQUIPO reutiliza `stats_team_field` (se configura en la sección de
 * arriba); aquí solo se usan sus valores como filas.
 */
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../services/api'
import { apiError } from '../stores/auth'
import { useDemoMode, useSampleConfig } from '../composables/appConfig'
import { confirmDialog } from '../composables/confirm'

const props = defineProps({
  formId: { type: Number, required: true },
  fields: { type: Array, default: () => [] }, // [{ key, label, type, multi, options:[{value,label}] }]
  teamField: { type: String, default: '' },   // stats_team_field vigente (reactivo desde el padre)
})

const { t } = useI18n()
const { demoMode } = useDemoMode()
// Ajuste global: ocultar la columna «Reparto rápido» (para organizaciones cuyo
// diseño muestral viene 100 % de fuera, los atajos de reparto son ruido).
const { sampleShowQuickFill } = useSampleConfig()

const loading = ref(true)
const saving = ref(false)
const error = ref('')
const flash = ref('')

const sampleField = ref('')
const sampleField2 = ref('')
const sampleField3 = ref('')
const denominator = ref('approved')
// targets: mapa "teamCode|sampleCode" => cadena numérica; received: mapa igual => número.
const targets = ref({})
const received = ref({})
// Total por equipo (ayuda de reparto): teamCode => cadena numérica.
const teamTotals = ref({})
// Campo de muestreo GUARDADO y nº de objetivos guardados (para avisar de que cambiar
// el campo descartará el plan del campo anterior, que queda en el histórico).
const savedSampleField = ref('')
const savedTargetCount = ref(0)

// Campos elegibles como muestreo (principal y secundarios): opción única (`select_one`)
// con valores conocidos. Se excluye `select_multiple` (una fila caería en varias celdas)
// y los campos sin opciones (sin conjunto cerrado que forme las columnas/distribución).
const singleFields = computed(() => props.fields.filter(f => (f.options?.length || 0) > 0 && !f.multi))

// Exclusión mutua entre los tres selects: un campo elegido en uno deja de listarse en
// los otros dos (el propio valor sigue en su lista porque no se excluye a sí mismo).
const without = (...taken) => {
  const set = new Set(taken.filter(Boolean))
  return singleFields.value.filter(f => !set.has(f.key))
}
const principalFields = computed(() => without(sampleField2.value, sampleField3.value))
const secondary2Fields = computed(() => without(sampleField.value, sampleField3.value))
const secondary3Fields = computed(() => without(sampleField.value, sampleField2.value))

const teamOptions = computed(() => {
  const f = props.fields.find(x => x.key === props.teamField)
  return f?.options || []
})
const sampleOptions = computed(() => {
  const f = props.fields.find(x => x.key === sampleField.value)
  return f?.options || []
})

const key = (tv, sv) => `${tv}|${sv}`

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get(`/admin/forms/${props.formId}/sample-plan`)
    const d = data.data
    sampleField.value = d.sample_field || ''
    sampleField2.value = d.sample_field2 || ''
    sampleField3.value = d.sample_field3 || ''
    denominator.value = d.denominator || 'approved'
    const tgt = {}
    for (const [k, v] of Object.entries(d.targets || {})) tgt[k] = String(v)
    targets.value = tgt
    received.value = d.received || {}
    savedSampleField.value = d.sample_field || ''
    savedTargetCount.value = Object.keys(d.targets || {}).length
  } catch (e) {
    error.value = apiError(e, t('formSettings.sampleLoadError'))
  } finally {
    loading.value = false
  }
}

// Al cambiar el campo de muestreo, conservamos los objetivos cuyos códigos sigan
// existiendo (misma clave). No hace falta limpiar: las claves obsoletas se ignoran
// al guardar porque solo se envían las celdas de la matriz visible.

function distribute(teamValue, mode) {
  const total = Number(teamTotals.value[teamValue] || 0)
  const cols = sampleOptions.value
  if (!cols.length || total <= 0) return
  if (mode === 'proportional') {
    const weights = cols.map(c => Number(received.value[key(teamValue, c.value)] || 0))
    const sum = weights.reduce((a, b) => a + b, 0)
    if (sum > 0) {
      // Reparto proporcional con redondeo, cuadrando el resto en la última columna.
      let assigned = 0
      cols.forEach((c, i) => {
        let v = i === cols.length - 1 ? total - assigned : Math.round((weights[i] / sum) * total)
        if (v < 0) v = 0
        assigned += v
        targets.value[key(teamValue, c.value)] = String(v)
      })
      return
    }
    // Sin datos recibidos: cae a uniforme.
  }
  // Uniforme: base + reparto del resto en las primeras columnas.
  const base = Math.floor(total / cols.length)
  let rem = total - base * cols.length
  cols.forEach((c) => {
    const v = base + (rem-- > 0 ? 1 : 0)
    targets.value[key(teamValue, c.value)] = String(v)
  })
}

// Borrar objetivos: vacía la matriz y los campos de reparto EN PANTALLA; el plan
// guardado no se toca hasta que el usuario pulse «Guardar» (que además archiva
// snapshot). Confirmación porque deshacer un mis-clic exige recargar sin guardar.
async function clearTargets() {
  const ok = await confirmDialog({
    title: t('formSettings.sampleClearTitle'),
    message: t('formSettings.sampleClearBody', { n: cellsWithTarget.value }),
    confirmText: t('formSettings.sampleClearConfirm'),
  })
  if (!ok) return
  targets.value = {}
  teamTotals.value = {}
}

const rowTotal = (teamValue) =>
  sampleOptions.value.reduce((s, c) => s + Number(targets.value[key(teamValue, c.value)] || 0), 0)

const grandTotal = computed(() =>
  teamOptions.value.reduce((s, tm) => s + rowTotal(tm.value), 0))

// Cobertura viva del plan (en vez de un binario «completado», difuso para una matriz):
// celdas con objetivo, equipos con al menos un objetivo, y total de equipos.
const cellsWithTarget = computed(() => {
  let n = 0
  for (const tm of teamOptions.value) {
    for (const c of sampleOptions.value) {
      if (Number(targets.value[key(tm.value, c.value)] || 0) > 0) n++
    }
  }
  return n
})
const teamsCovered = computed(() => teamOptions.value.filter(tm => rowTotal(tm.value) > 0).length)

// Aviso: se cambió el campo de muestreo respecto al guardado y había objetivos → al
// guardar, esos objetivos del campo anterior se descartan del plan vigente (quedan en
// el histórico). Solo se muestra mientras el cambio esté pendiente de guardar.
const fieldChangeWarning = computed(() =>
  savedSampleField.value !== '' && sampleField.value !== savedSampleField.value && savedTargetCount.value > 0)

async function save() {
  // Guardar un plan SIN ningún objetivo equivale a no monitorear: se avisa antes.
  if (sampleField.value && grandTotal.value === 0) {
    const ok = await confirmDialog({
      title: t('formSettings.sampleEmptyTitle'),
      message: t('formSettings.sampleEmptyBody'),
      confirmText: t('formSettings.sampleEmptyConfirm'),
    })
    if (!ok) return
  }
  saving.value = true
  error.value = ''
  flash.value = ''
  try {
    const cells = []
    for (const tm of teamOptions.value) {
      for (const c of sampleOptions.value) {
        const v = Number(targets.value[key(tm.value, c.value)] || 0)
        if (v > 0) cells.push({ team_value: tm.value, sample_value: c.value, target: v })
      }
    }
    await api.put(`/admin/forms/${props.formId}/sample-plan`, {
      sample_field: sampleField.value || null,
      sample_field2: sampleField2.value || null,
      sample_field3: sampleField3.value || null,
      denominator: denominator.value,
      cells,
    })
    flash.value = t('formSettings.sampleSaved')
    await load()
  } catch (e) {
    error.value = apiError(e, t('formSettings.sampleSaveError'))
  } finally {
    saving.value = false
  }
}

onMounted(load)
watch(() => props.formId, load)
</script>

<template>
  <!-- Dos tarjetas: la SELECCIÓN (qué se muestrea y cómo se cuenta) y la MATRIZ de
       objetivos. El guardado es único (config y celdas viajan en el mismo PUT). -->
  <div class="space-y-6">
    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900">
      {{ error }}
    </div>

    <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <h2 class="font-semibold text-slate-900">{{ $t('formSettings.sampleSection') }}</h2>
      <p class="mt-1 text-sm text-slate-500">{{ $t('formSettings.sampleSectionDesc') }}</p>

    <template v-if="!loading">
      <!-- Selección de campos y denominador -->
      <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <label class="block">
          <span class="text-sm font-medium text-slate-700">{{ $t('formSettings.sampleField') }}</span>
          <select v-model="sampleField" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30">
            <option value="">{{ $t('formSettings.sampleNone') }}</option>
            <option v-for="f in principalFields" :key="f.key" :value="f.key">{{ f.label }}</option>
          </select>
          <span class="mt-1 block text-xs text-slate-400">{{ $t('formSettings.sampleFieldHint') }}</span>
        </label>

        <label class="block">
          <span class="text-sm font-medium text-slate-700">{{ $t('formSettings.sampleDenominator') }}</span>
          <select v-model="denominator" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30">
            <option value="approved">{{ $t('formSettings.sampleDenomApproved') }}</option>
            <option value="approved_pending">{{ $t('formSettings.sampleDenomApprovedPending') }}</option>
          </select>
          <span class="mt-1 block text-xs text-slate-400">{{ $t('formSettings.sampleDenominatorHint') }}</span>
        </label>
      </div>

      <div v-if="sampleField" class="mt-4">
        <span class="text-sm font-medium text-slate-700">{{ $t('formSettings.sampleSecondary') }}</span>
        <span class="mt-0.5 block text-xs text-slate-400">{{ $t('formSettings.sampleSecondaryHint') }}</span>
        <div class="mt-2 grid gap-4 sm:grid-cols-2">
          <select v-model="sampleField2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30">
            <option value="">{{ $t('formSettings.sampleField2') }} —</option>
            <option v-for="f in secondary2Fields" :key="f.key" :value="f.key">{{ f.label }}</option>
          </select>
          <select v-model="sampleField3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30">
            <option value="">{{ $t('formSettings.sampleField3') }} —</option>
            <option v-for="f in secondary3Fields" :key="f.key" :value="f.key">{{ f.label }}</option>
          </select>
        </div>
      </div>

      <!-- Aviso: cambiar el campo descartará el plan del campo anterior (queda en histórico) -->
      <div v-if="fieldChangeWarning" class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900">
        {{ $t('formSettings.sampleFieldChanged', { n: savedTargetCount }) }}
      </div>
    </template>
    </section>

    <!-- Tarjeta 2: matriz equipo × valor -->
    <section v-if="!loading && sampleField" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <div v-if="!teamField" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900">
          {{ $t('formSettings.sampleNeedsTeam') }}
        </div>
        <div v-else-if="!teamOptions.length" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900">
          {{ $t('formSettings.sampleNoTeamValues') }}
        </div>

        <div v-else>
          <div class="flex items-baseline justify-between">
            <h2 class="font-semibold text-slate-900">{{ $t('formSettings.sampleMatrix') }}</h2>
            <span class="text-xs text-slate-400">{{ $t('formSettings.sampleTotalHeader') }}: {{ grandTotal.toLocaleString() }}</span>
          </div>
          <p class="mt-1 text-sm text-slate-500">{{ $t('formSettings.sampleMatrixHint') }}</p>
          <!-- Cobertura viva del plan (celdas con objetivo · equipos con plan · total). -->
          <p class="mt-1 text-xs" :class="grandTotal > 0 ? 'text-slate-500' : 'text-amber-600 dark:text-amber-400'">
            {{ $t('formSettings.sampleCoverage', { cells: cellsWithTarget, teams: teamsCovered, teamsTotal: teamOptions.length, total: grandTotal.toLocaleString() }) }}
          </p>

          <div class="mt-3 overflow-x-auto">
            <table class="w-full border-collapse text-sm">
              <thead>
                <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wider text-slate-400">
                  <th class="sticky left-0 bg-white py-2 pr-3 font-medium">{{ $t('formSettings.sampleTeamHeader') }}</th>
                  <th v-for="c in sampleOptions" :key="c.value" class="px-2 py-2 font-medium">{{ c.label }}</th>
                  <th class="px-2 py-2 text-right font-medium">{{ $t('formSettings.sampleTotalHeader') }}</th>
                  <th v-if="sampleShowQuickFill" class="px-2 py-2 font-medium">{{ $t('formSettings.samplePerTeam') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="tm in teamOptions" :key="tm.value" class="border-b border-slate-100">
                  <td class="sticky left-0 bg-white py-2 pr-3 font-medium text-slate-700">{{ tm.label }}</td>
                  <td v-for="c in sampleOptions" :key="c.value" class="px-1 py-1.5">
                    <input
                      v-model="targets[key(tm.value, c.value)]"
                      type="number"
                      min="0"
                      class="w-16 rounded-md border border-slate-300 px-2 py-1 text-sm tabular-nums outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500/30"
                    />
                    <span v-if="received[key(tm.value, c.value)]" class="mt-0.5 block text-[0.65rem] text-slate-400">{{ $t('formSettings.sampleReceived', { n: received[key(tm.value, c.value)] }) }}</span>
                  </td>
                  <td class="px-2 py-1.5 text-right tabular-nums text-slate-500">{{ rowTotal(tm.value).toLocaleString() }}</td>
                  <!-- Reparto rápido: ayuda de escritura, ocultable por ajuste global -->
                  <td v-if="sampleShowQuickFill" class="px-2 py-1.5">
                    <div class="flex items-center gap-1">
                      <input
                        v-model="teamTotals[tm.value]"
                        type="number"
                        min="0"
                        class="w-16 rounded-md border border-slate-300 px-2 py-1 text-sm tabular-nums outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500/30"
                        :placeholder="$t('formSettings.samplePerTeamPlaceholder')"
                      />
                      <button type="button" class="rounded-md px-1.5 py-1 text-xs font-medium text-primary-600 hover:bg-primary-50" :title="$t('formSettings.sampleDistributeEven')" @click="distribute(tm.value, 'even')">{{ $t('formSettings.sampleDistributeEven') }}</button>
                      <button type="button" class="rounded-md px-1.5 py-1 text-xs font-medium text-primary-600 hover:bg-primary-50" :title="$t('formSettings.sampleDistributeProportional')" @click="distribute(tm.value, 'proportional')">{{ $t('formSettings.sampleDistributeProportional') }}</button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
    </section>

    <div v-if="!loading" class="flex items-center justify-end gap-3">
        <span v-if="flash" class="text-sm font-medium text-success-700 dark:text-success-400">✓ {{ flash }}</span>
        <button
          v-if="sampleField && teamField && teamOptions.length"
          type="button"
          :disabled="cellsWithTarget === 0"
          class="rounded-lg px-4 py-2 text-sm font-medium text-red-600 ring-1 ring-red-200 hover:bg-red-50 disabled:opacity-50 disabled:hover:bg-transparent dark:text-red-400 dark:ring-red-900 dark:hover:bg-red-950/40"
          @click="clearTargets"
        >
          {{ $t('formSettings.sampleClearBtn') }}
        </button>
        <button
          :disabled="demoMode || saving"
          class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-60"
          :title="demoMode ? $t('common.demoDisabled') : undefined"
          @click="save"
        >
          {{ saving ? $t('formSettings.saving') : $t('formSettings.sampleSaveBtn') }}
        </button>
    </div>
  </div>
</template>
