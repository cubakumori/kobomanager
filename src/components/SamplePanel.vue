<script setup>
/**
 * Panel de cumplimiento de la MUESTRA por equipo — componente COMPARTIDO entre la
 * vista interna (SampleView) y la vista pública de los enlaces de solo lectura
 * (PublicShareView, pestaña «Muestra»), como StatsPanels con las estadísticas.
 *
 * Recibe el payload ya calculado (forms/sample o public/share/:token/sample: la
 * misma forma) y NO carga nada por su cuenta. La PRESENTACIÓN se elige con un
 * selector (preferencia por dispositivo en localStorage): lineal (tarjetas con
 * barras), tabla, mapa de calor, semáforo, barras agrupadas (Chart.js) y resumen
 * (doughnut + estadísticas agregadas). Un segundo selector elige el ORDEN de los
 * equipos (misma persistencia; aplica a todos los modos, «fuera de plan» al
 * final). Con meta-equipo configurado, toggle «Agrupar equipos» (roll-up de
 * solo presentación sobre `teams[].group`).
 *
 * `readonly` (vista pública): el chip del denominador es texto informativo, no un
 * botón — el toggle transitorio `?denominator=` es una herramienta del revisor
 * interno y el endpoint público no lo admite. Sin él, el chip sigue siendo clave
 * para interpretar el panel (qué estados cuentan como «hecho»).
 */
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePctFormat } from '../composables/appConfig'
import { useSamplePalette } from '../composables/samplePalette'
import StatsChart from './StatsChart.vue'

const props = defineProps({
  data: { type: Object, required: true },
  loading: { type: Boolean, default: false },
  readonly: { type: Boolean, default: false },
})
const emit = defineEmits(['toggle-denominator'])

const { t, locale } = useI18n()
const { formatPct } = usePctFormat()

const num = (n) => (n ?? 0).toLocaleString(locale.value)
const pct = (p) => formatPct(p, locale.value)
// Ancho de la barra: acotado a 100 % aunque haya sobre-muestra.
const barWidth = (done, target) => {
  if (!target || target <= 0) return 0
  return Math.min(100, Math.round((done * 100) / target))
}

// ---------- Selector de tipo de vista ----------
// Preferencia por dispositivo (no por formulario), como el tema. La clave es la
// MISMA en la vista interna y la pública: es una preferencia de presentación.
const VIEW_KEY = 'km.sample.view'
// Orden: el semáforo va JUNTO al mapa de calor (son primos: la misma rejilla,
// con gradiente por % uno y tres estados el otro).
const VIEWS = [
  { key: 'linear', label: 'sample.viewLinear' },
  { key: 'table', label: 'sample.viewTable' },
  { key: 'heatmap', label: 'sample.viewHeatmap' },
  { key: 'traffic', label: 'sample.viewTraffic' },
  { key: 'bars', label: 'sample.viewBars' },
  { key: 'summary', label: 'sample.viewSummary' },
]
const storedView = localStorage.getItem(VIEW_KEY)
const view = ref(VIEWS.some((v) => v.key === storedView) ? storedView : 'linear')
function setView(key) {
  view.value = key
  localStorage.setItem(VIEW_KEY, key)
}

// ---------- Selector de ORDEN de los equipos ----------
// Preferencia por dispositivo, como el tipo de vista. Se aplica a TODOS los
// modos (es un reordenado de `displayTeams`, no una vista): 'target' conserva el
// orden del backend (planificados por objetivo desc), 'pct' pone delante a los
// más atrasados (% ascendente), 'done' y 'backlog' descendentes, 'alpha' por
// nombre. Los «fuera de plan» (y «Sin agrupar», su primo en el roll-up) van
// SIEMPRE al final, conservando su orden relativo (sort estable).
const SORT_KEY = 'km.sample.sort'
const SORTS = [
  { key: 'target', label: 'sample.sortTarget' },
  { key: 'pct', label: 'sample.sortPct' },
  { key: 'done', label: 'sample.sortDone' },
  { key: 'backlog', label: 'sample.sortBacklog' },
  { key: 'alpha', label: 'sample.sortAlpha' },
]
const storedSort = localStorage.getItem(SORT_KEY)
const sortBy = ref(SORTS.some((s) => s.key === storedSort) ? storedSort : 'target')
function setSort(key) {
  sortBy.value = key
  localStorage.setItem(SORT_KEY, key)
}
// Ordena filas de equipo O de meta-equipo (misma forma). El % nulo (sin
// objetivo) se trata como infinito: nunca adelanta a un equipo con plan.
function sortRows(rows) {
  const toEnd = (r) => (r.out_of_plan || r.ungrouped ? 1 : 0)
  const cmp = {
    target: () => 0, // orden del backend
    pct: (a, b) => (a.pct ?? Infinity) - (b.pct ?? Infinity),
    done: (a, b) => b.done - a.done,
    backlog: (a, b) => b.pending + b.on_hold - (a.pending + a.on_hold),
    alpha: (a, b) => a.name.localeCompare(b.name, locale.value),
  }[sortBy.value]
  return [...rows].sort((a, b) => toEnd(a) - toEnd(b) || cmp(a, b))
}

// ---------- Toggle «Agrupar equipos» (meta-equipo) ----------
// Solo aparece si el formulario tiene campo de agrupación configurado (y visible
// en este alcance: el backend lo omite si FieldScope lo oculta). Preferencia por
// dispositivo, como el tipo de vista. El roll-up es de SOLO PRESENTACIÓN: el plan
// sigue por equipo y aquí solo se agrega lo que ya viaja en `teams[]`.
const GROUP_KEY = 'km.sample.group'
const grouped = ref(localStorage.getItem(GROUP_KEY) === '1')
const groupable = computed(() => !!props.data?.group_field)
const groupOn = computed(() => groupable.value && grouped.value)
function toggleGrouped() {
  grouped.value = !grouped.value
  localStorage.setItem(GROUP_KEY, grouped.value ? '1' : '0')
}

// Filas agregadas por meta-equipo, con la MISMA forma que una fila de equipo (name,
// done, target, pct, backlog, cells) para que tabla/mapa de calor/semáforo/barras/
// resumen conmuten sin rama propia; añaden `teams` (sus equipos, para el árbol del
// modo lineal). El orden de grupos lo da el backend (esquema → extras → «Sin agrupar»).
const groupRows = computed(() => {
  if (!groupable.value) return []
  const byKey = new Map()
  for (const g of props.data.groups ?? []) {
    byKey.set(g.key, {
      key: g.key,
      name: g.key === '__none__' ? t('sample.ungrouped') : g.label,
      ungrouped: g.key === '__none__',
      done: 0, target: 0, pending: 0, on_hold: 0,
      out_of_plan: false, projection: null,
      teams: [], _cells: new Map(),
    })
  }
  for (const team of props.data.teams ?? []) {
    const g = byKey.get(team.group ?? '__none__')
    if (!g) continue
    g.done += team.done
    g.target += team.target
    g.pending += team.pending
    g.on_hold += team.on_hold
    g.teams.push(team)
    for (const c of team.cells) {
      const acc = g._cells.get(c.value) ?? { value: c.value, label: c.label, done: 0, target: 0, hasTarget: false }
      acc.done += c.done
      if (c.target != null) { acc.target += c.target; acc.hasTarget = true }
      g._cells.set(c.value, acc)
    }
  }
  const rows = []
  for (const g of byKey.values()) {
    if (!g.teams.length) continue
    g.cells = (props.data.values ?? [])
      .map((v) => g._cells.get(v.value))
      .filter(Boolean)
      .map((c) => ({
        value: c.value,
        label: c.label,
        done: c.done,
        target: c.hasTarget ? c.target : null,
        pct: c.hasTarget && c.target > 0 ? (c.done * 100) / c.target : null,
        out_of_plan: !c.hasTarget,
      }))
    delete g._cells
    g.pct = g.target > 0 ? (g.done * 100) / g.target : null
    rows.push(g)
  }
  return rows
})

// Filas efectivas de los modos tabulares y de gráficos: equipo ⇄ meta-equipo,
// ya con el ORDEN elegido aplicado (agrupado, ordena los grupos; los equipos de
// cada grupo se ordenan al pintarlos en el árbol del modo lineal).
const displayTeams = computed(() => sortRows(groupOn.value ? groupRows.value : props.data?.teams ?? []))

// ---------- Total general: SOLO equipos planificados ----------
// La barra de cumplimiento compara manzanas con manzanas: Σ hecho de los equipos
// CON plan frente a Σ objetivos (sin topar la sobre-muestra: puede superar el
// 100 %, igual que una fila). Los equipos «fuera de plan» no entran en el
// numerador (no participan en el denominador); su volumen se muestra aparte como
// nota, no se esconde. Siempre sobre data.teams (con agrupado activo, igual).
const plannedGrand = computed(() => {
  let done = 0
  let target = 0
  let outOfPlanDone = 0
  for (const tm of props.data?.teams ?? []) {
    if (tm.out_of_plan) outOfPlanDone += tm.done
    else { done += tm.done; target += tm.target }
  }
  return { done, target, pct: target > 0 ? (done * 100) / target : null, outOfPlanDone }
})

// ---------- Plegado de tarjetas de equipo (modo lineal) ----------
// Estado efímero (se resetea al recargar: es un panel de monitoreo, no una preferencia).
const collapsedTeams = ref(new Set())
function toggleTeam(key) {
  const s = new Set(collapsedTeams.value)
  if (s.has(key)) s.delete(key)
  else s.add(key)
  collapsedTeams.value = s
}
// Plegado de tarjetas de META-EQUIPO (árbol de dos niveles del modo lineal agrupado):
// plegar un grupo oculta sus equipos y deja su tarjeta como resumen.
const collapsedGroups = ref(new Set())
function toggleGroup(key) {
  const s = new Set(collapsedGroups.value)
  if (s.has(key)) s.delete(key)
  else s.add(key)
  collapsedGroups.value = s
}
// Pleca global del «Total general»: pliega todos los equipos — o, agrupado, todos
// los meta-equipos (colapsa a la vista-resumen de grupos) — y viceversa.
const allCollapsed = computed(() => {
  if (groupOn.value) {
    const groups = groupRows.value
    return groups.length > 0 && groups.every((g) => collapsedGroups.value.has(g.key))
  }
  const teams = props.data?.teams ?? []
  return teams.length > 0 && teams.every((tm) => collapsedTeams.value.has(tm.key))
})
function toggleAllTeams() {
  if (groupOn.value) {
    collapsedGroups.value = allCollapsed.value
      ? new Set()
      : new Set(groupRows.value.map((g) => g.key))
    return
  }
  collapsedTeams.value = allCollapsed.value
    ? new Set()
    : new Set((props.data?.teams ?? []).map((tm) => tm.key))
}

// ---------- Ejes de la tabla (tabla / mapa de calor / semáforo) ----------
// El backend omite en `team.cells` las celdas vacías sin objetivo, así que la
// tabla se arma sobre el eje canónico `data.values` mapeando cada fila por valor.
// Con «Agrupar equipos» las filas son los meta-equipos (misma forma agregada).
const tableRows = computed(() =>
  displayTeams.value.map((team) => ({
    team,
    map: Object.fromEntries(team.cells.map((c) => [c.value, c])),
  })),
)
// Columnas: solo los valores con alguna celda (evita columnas 100 % vacías de
// opciones del esquema sin datos ni plan).
const tableValues = computed(() =>
  (props.data?.values ?? []).filter((v) => tableRows.value.some((r) => r.map[v.value])),
)
const colTotals = computed(() =>
  tableValues.value.map((v) => {
    let done = 0
    let target = 0
    let hasTarget = false
    for (const r of tableRows.value) {
      const c = r.map[v.value]
      if (!c) continue
      done += c.done
      if (c.target != null) {
        target += c.target
        hasTarget = true
      }
    }
    return { done, target: hasTarget ? target : null }
  }),
)

// Mapa de calor: chip coloreado por tramo de cumplimiento según la PALETA global
// (Configuración → «Muestras»; ver composables/samplePalette). «Fuera de plan»
// queda fuera de la paleta: ámbar fijo (otra semántica, no un grado).
const { heatChip, trafficChip, doneColor, barFill, metText, chartDone, distBar } = useSamplePalette()
// Texto «cumplido» vs neutro (sobre-muestra/faltan de barras y tabla).
const metOrMuted = (met) => (met ? metText() : { class: 'text-slate-500', style: null })
function heatCell(cell) {
  if (cell.target == null) return { class: 'bg-amber-100 text-amber-800', style: null }
  return heatChip(cell.pct ?? 0)
}

// Avance REAL del plan: solo celdas con objetivo y con el hecho acotado a su
// objetivo (el `grand.done` incluye lo fuera de plan y la sobre-muestra, que
// distorsionan: un equipo que se pasa no cubre lo que falta en otro). Base del
// umbral del semáforo y del doughnut de resumen.
const plannedAgg = computed(() => {
  let done = 0
  let target = 0
  for (const tm of props.data?.teams ?? []) {
    for (const c of tm.cells) {
      if (c.target != null) {
        done += Math.min(c.done, c.target)
        target += c.target
      }
    }
  }
  return { done, target }
})
// Semáforo: «atrasado» es RELATIVO a este avance global (quién va por debajo
// del ritmo común), no un umbral fijo.
const globalPct = computed(() => {
  const { done, target } = plannedAgg.value
  return target > 0 ? (done * 100) / target : 0
})
function trafficState(cell) {
  if (!cell || cell.target == null) return 'none'
  const p = cell.pct ?? 0
  if (p >= 100) return 'met'
  if (p >= globalPct.value) return 'onpace'
  return 'behind'
}
const trafficCell = (cell) => trafficChip(trafficState(cell))
function trafficTitle(cell, label) {
  if (!cell) return label
  const base = `${label}: ${num(cell.done)}${cell.target != null ? ' / ' + num(cell.target) : ''}`
  return cell.target == null ? `${base} · ${t('sample.trafficNoTarget')}` : base
}

// ---------- Gráficos (barras agrupadas y doughnut de resumen) ----------
const barsData = computed(() => {
  const teams = displayTeams.value
  return {
    labels: teams.map((tm) => tm.name),
    datasets: [
      { label: t('sample.done'), data: teams.map((tm) => tm.done), backgroundColor: chartDone(), borderRadius: 4 },
      { label: t('sample.target'), data: teams.map((tm) => tm.target), backgroundColor: '#cbd5e1', borderRadius: 4 },
    ],
  }
})
const barsOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { display: true, position: 'bottom' }, valueLabels: { enabled: true } },
  scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
}

const summaryData = computed(() => {
  const { done, target } = plannedAgg.value
  return {
    labels: [t('sample.done'), t('sample.summaryMissing')],
    datasets: [{ data: [done, Math.max(0, target - done)], backgroundColor: [doneColor(), '#e2e8f0'] }],
  }
})
const summaryOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { display: true, position: 'bottom' }, valueLabels: { enabled: true } },
}

// ---------- Estadísticas del modo RESUMEN (bajo el doughnut) ----------
// Siempre sobre los EQUIPOS reales con plan (no los meta-equipos: la proyección
// es por equipo), y sin repetir la tarjeta de cabecera (hecho/objetivo/backlog).
// «Sin empezar» prima sobre «atrasado»: un equipo a 0 no está atrasado, no ha
// arrancado. «Proyectan cumplir» = objetivo ya alcanzado o ritmo>0 (hay ETA).
const summaryStats = computed(() => {
  const planned = (props.data?.teams ?? []).filter((tm) => !tm.out_of_plan && tm.target > 0)
  const s = {
    teams: planned.length,
    projMet: 0,
    complete: 0, inProgress: 0, behind: 0, notStarted: 0,
    best: null, worst: null,
    cellsMet: 0, cellsTotal: 0,
  }
  for (const tm of planned) {
    if (tm.projection && (tm.projection.met || tm.projection.eta)) s.projMet++
    const p = tm.pct ?? 0
    if (tm.done === 0) s.notStarted++
    else if (p >= 100) s.complete++
    else if (p < 50) s.behind++
    else s.inProgress++
    if (!s.best || p > s.best.pct) s.best = { name: tm.name, pct: p }
    if (!s.worst || p < s.worst.pct) s.worst = { name: tm.name, pct: p }
    for (const c of tm.cells) {
      if (c.target == null) continue
      s.cellsTotal++
      if (c.done >= c.target) s.cellsMet++
    }
  }
  return s
})
// Barra apilada del reparto por estado: segmentos con la paleta del semáforo
// (misma semántica: cumplido/en ritmo/atrasado) + gris de «sin objetivo» para
// los que no han empezado.
const summaryStates = computed(() => {
  const s = summaryStats.value
  return [
    { key: 'complete', label: 'sample.summaryComplete', n: s.complete, chip: trafficChip('met') },
    { key: 'inprogress', label: 'sample.summaryInProgress', n: s.inProgress, chip: trafficChip('onpace') },
    { key: 'behind', label: 'sample.summaryBehind', n: s.behind, chip: trafficChip('behind') },
    { key: 'notstarted', label: 'sample.summaryNotStarted', n: s.notStarted, chip: trafficChip('none') },
  ]
})
</script>

<template>
  <div class="space-y-6">
    <!-- Selector de tipo de vista + toggle «Agrupar equipos» (meta-equipo) -->
    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
      <div role="group" :aria-label="$t('sample.viewLabel')" class="inline-flex flex-wrap gap-1 rounded-lg bg-slate-100 p-1">
        <button
          v-for="v in VIEWS"
          :key="v.key"
          type="button"
          :aria-pressed="view === v.key"
          class="rounded-md px-2.5 py-1 text-xs font-medium transition"
          :class="view === v.key ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
          @click="setView(v.key)"
        >
          {{ $t(v.label) }}
        </button>
      </div>
      <!-- Selector de ORDEN de equipos (preferencia por dispositivo) -->
      <label class="flex items-center gap-1.5 text-xs font-medium text-slate-600">
        <span>{{ $t('sample.sortLabel') }}</span>
        <select
          :value="sortBy"
          class="rounded-lg border border-slate-300 px-2 py-1 text-xs dark:border-slate-600 dark:bg-slate-800"
          @change="setSort($event.target.value)"
        >
          <option v-for="s in SORTS" :key="s.key" :value="s.key">{{ $t(s.label) }}</option>
        </select>
      </label>
      <label
        v-if="groupable"
        class="flex cursor-pointer items-center gap-2 text-xs font-medium text-slate-600"
        :title="$t('sample.groupToggleTitle', { field: data.group_field.label })"
      >
        <button
          type="button"
          role="switch"
          :aria-checked="String(groupOn)"
          class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-primary-500/30"
          :class="groupOn ? 'bg-primary-600' : 'bg-slate-300 dark:bg-slate-600'"
          @click="toggleGrouped"
        >
          <span
            class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
            :class="groupOn ? 'translate-x-4' : 'translate-x-0.5'"
          />
        </button>
        <span @click="toggleGrouped">{{ $t('sample.groupToggle', { field: data.group_field.label }) }}</span>
      </label>
    </div>

    <!-- Caveat del roll-up: la pertenencia se infiere de los envíos -->
    <p v-if="groupOn && groupRows.some((g) => g.ungrouped)" class="text-xs text-slate-400">
      {{ $t('sample.ungroupedHint') }}
    </p>

    <!-- Total general -->
    <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="font-semibold text-slate-900">{{ $t('sample.grandTotal') }}</h2>
        <!-- Chip del denominador: en la vista interna es un BOTÓN que lo alterna
             TEMPORALMENTE (no toca el plan); en la pública, texto informativo. -->
        <button
          v-if="!readonly"
          type="button"
          :disabled="loading"
          :aria-pressed="String(data.denominator === 'approved_pending')"
          :title="$t('sample.denominatorToggleTitle')"
          class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs uppercase tracking-wider text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40 disabled:cursor-wait dark:hover:bg-slate-800"
          @click="emit('toggle-denominator')"
        >
          {{ data.denominator === 'approved_pending' ? $t('sample.denominatorApprovedPending') : $t('sample.denominatorApproved') }}
          <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" class="h-3 w-3">
            <path fill-rule="evenodd" d="M13.2 2.24a.75.75 0 0 0 .04 1.06l2.1 1.95H6.75a.75.75 0 0 0 0 1.5h8.59l-2.1 1.95a.75.75 0 1 0 1.02 1.1l3.5-3.25a.75.75 0 0 0 0-1.1l-3.5-3.25a.75.75 0 0 0-1.06.04Zm-6.4 8a.75.75 0 0 0-1.06-.04l-3.5 3.25a.75.75 0 0 0 0 1.1l3.5 3.25a.75.75 0 1 0 1.02-1.1l-2.1-1.95h8.59a.75.75 0 0 0 0-1.5H4.66l2.1-1.95a.75.75 0 0 0 .04-1.06Z" clip-rule="evenodd" />
          </svg>
        </button>
        <span v-else class="rounded-md px-1.5 py-0.5 text-xs uppercase tracking-wider text-slate-400">
          {{ data.denominator === 'approved_pending' ? $t('sample.denominatorApprovedPending') : $t('sample.denominatorApproved') }}
        </span>
      </div>
      <div class="mt-3">
        <div class="flex items-baseline justify-between text-sm">
          <span class="font-semibold text-slate-900">{{ num(plannedGrand.done) }} / {{ num(plannedGrand.target) }}</span>
          <span class="text-slate-500">{{ pct(plannedGrand.pct) }}</span>
        </div>
        <div class="mt-1 h-2.5 w-full overflow-hidden rounded-full bg-slate-100">
          <div
            class="h-full rounded-full transition-all"
            :class="barFill((plannedGrand.pct ?? 0) >= 100).class"
            :style="[barFill((plannedGrand.pct ?? 0) >= 100).style, { width: barWidth(plannedGrand.done, plannedGrand.target) + '%' }]"
          ></div>
        </div>
        <!-- Volumen fuera de plan: visible pero fuera de la barra (no participa en el denominador) -->
        <p v-if="plannedGrand.outOfPlanDone" class="mt-1 text-xs text-amber-600">
          {{ $t('sample.grandOutOfPlan', { n: num(plannedGrand.outOfPlanDone) }) }}
        </p>
      </div>
      <!-- Corte de revisión (última aprobación) + backlog actual -->
      <div class="mt-3 flex flex-wrap items-baseline gap-x-4 gap-y-1 border-t border-slate-100 pt-3 text-xs text-slate-500">
        <span>
          <span class="font-medium text-slate-600">{{ $t('sample.cutoff') }}:</span>
          <span class="ml-1 tabular-nums">{{ data.last_approved_at || $t('sample.cutoffNone') }}</span>
        </span>
        <span>
          {{ $t('sample.pendingNow') }}:
          <span class="font-semibold tabular-nums text-slate-700">{{ num(data.grand.pending) }}</span>
        </span>
        <span>
          {{ $t('sample.onHoldNow') }}:
          <span class="font-semibold tabular-nums text-slate-700">{{ num(data.grand.on_hold) }}</span>
        </span>
        <!-- Pleca global: plegar/desplegar todos los equipos (solo modo lineal) -->
        <button
          v-if="view === 'linear' && data.teams.length"
          type="button"
          class="ml-auto flex items-center gap-1 font-medium text-slate-500 transition hover:text-slate-700"
          :aria-expanded="!allCollapsed"
          @click="toggleAllTeams"
        >
          <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" class="h-3.5 w-3.5 transition-transform" :class="allCollapsed ? '-rotate-90' : ''">
            <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
          </svg>
          {{ allCollapsed ? $t('sample.expandAll') : $t('sample.collapseAll') }}
        </button>
      </div>
    </section>

    <template v-if="data.teams.length">
      <!-- ===== Modo LINEAL: tarjeta por equipo con barras y proyección; agrupado,
           árbol de dos niveles (tarjeta de meta-equipo → sus equipos) ===== -->
      <template v-if="view === 'linear'">
      <template v-for="grp in groupOn ? displayTeams : [null]" :key="grp?.key ?? '__flat__'">
        <!-- Tarjeta de meta-equipo (agregados; clic = plegar/desplegar sus equipos) -->
        <section v-if="grp" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <div
            role="button"
            tabindex="0"
            :aria-expanded="!collapsedGroups.has(grp.key)"
            :title="$t('sample.toggleGroup')"
            class="flex cursor-pointer flex-wrap items-baseline justify-between gap-2 rounded-md -m-1 p-1 hover:bg-slate-50"
            @click="toggleGroup(grp.key)"
            @keydown.enter.prevent="toggleGroup(grp.key)"
            @keydown.space.prevent="toggleGroup(grp.key)"
          >
            <h3 class="flex items-center gap-1.5 font-semibold text-slate-900">
              <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" class="h-4 w-4 shrink-0 text-slate-400 transition-transform" :class="collapsedGroups.has(grp.key) ? '-rotate-90' : ''">
                <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
              </svg>
              <span>{{ grp.name }}</span>
              <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[0.65rem] font-medium uppercase tracking-wide text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                {{ $t('sample.groupTeamCount', { n: grp.teams.length }) }}
              </span>
            </h3>
            <span class="text-sm text-slate-500">
              <span class="font-semibold text-slate-900">{{ num(grp.done) }} / {{ num(grp.target) }}</span>
              · {{ pct(grp.pct) }}
            </span>
          </div>
          <div class="mt-2 h-2.5 w-full overflow-hidden rounded-full bg-slate-100">
            <div
              class="h-full rounded-full transition-all"
              :class="barFill((grp.pct ?? 0) >= 100).class"
              :style="[barFill((grp.pct ?? 0) >= 100).style, { width: barWidth(grp.done, grp.target) + '%' }]"
            ></div>
          </div>
          <p v-if="grp.pending + grp.on_hold > 0" class="mt-1 text-xs text-slate-500">
            {{ $t('sample.pendingNow') }}: <span class="font-medium tabular-nums text-slate-700">{{ num(grp.pending) }}</span>
            · {{ $t('sample.onHoldNow') }}: <span class="font-medium tabular-nums text-slate-700">{{ num(grp.on_hold) }}</span>
          </p>
          <p v-if="grp.ungrouped" class="mt-1 text-xs text-slate-400">{{ $t('sample.ungroupedHint') }}</p>
        </section>

        <template v-if="!grp || !collapsedGroups.has(grp.key)">
        <section
          v-for="team in grp ? sortRows(grp.teams) : displayTeams"
          :key="team.key"
          class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200"
          :class="grp ? 'ml-4 sm:ml-8' : ''"
        >
          <!-- Header clicable: pliega/despliega el detalle de la tarjeta -->
          <div
            role="button"
            tabindex="0"
            :aria-expanded="!collapsedTeams.has(team.key)"
            :title="$t('sample.toggleTeam')"
            class="flex cursor-pointer flex-wrap items-baseline justify-between gap-2 rounded-md -m-1 p-1 hover:bg-slate-50"
            @click="toggleTeam(team.key)"
            @keydown.enter.prevent="toggleTeam(team.key)"
            @keydown.space.prevent="toggleTeam(team.key)"
          >
            <h3 class="flex items-center gap-1.5 font-semibold text-slate-900">
              <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" class="h-4 w-4 shrink-0 text-slate-400 transition-transform" :class="collapsedTeams.has(team.key) ? '-rotate-90' : ''">
                <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
              </svg>
              <span>{{ team.name }}</span>
              <span v-if="team.out_of_plan" class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-[0.65rem] font-medium uppercase tracking-wide text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">{{ $t('sample.outOfPlan') }}</span>
            </h3>
            <span class="text-sm text-slate-500">
              <span class="font-semibold text-slate-900">{{ num(team.done) }} / {{ num(team.target) }}</span>
              · {{ pct(team.pct) }}
            </span>
          </div>

          <!-- Barra del total del equipo (visible también plegada: resumen de un vistazo) -->
          <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-slate-100">
            <div
              class="h-full rounded-full transition-all"
              :class="barFill((team.pct ?? 0) >= 100).class"
              :style="[barFill((team.pct ?? 0) >= 100).style, { width: barWidth(team.done, team.target) + '%' }]"
            ></div>
          </div>

          <div v-show="!collapsedTeams.has(team.key)">
            <p v-if="team.target > 0" class="mt-1 text-xs" v-bind="metOrMuted(team.done >= team.target)">
              <template v-if="team.done >= team.target">{{ $t('sample.surplus', { n: num(team.done - team.target) }) }}</template>
              <template v-else>{{ $t('sample.remaining', { n: num(team.target - team.done) }) }}</template>
            </p>
            <!-- Backlog de revisión del equipo (solo si hay algo esperando) -->
            <p v-if="team.pending + team.on_hold > 0" class="mt-1 text-xs text-slate-500">
              {{ $t('sample.pendingNow') }}: <span class="font-medium tabular-nums text-slate-700">{{ num(team.pending) }}</span>
              · {{ $t('sample.onHoldNow') }}: <span class="font-medium tabular-nums text-slate-700">{{ num(team.on_hold) }}</span>
            </p>

            <!-- Celdas equipo × valor -->
            <div class="mt-4 grid gap-x-6 gap-y-3 sm:grid-cols-2">
              <div v-for="cell in team.cells" :key="cell.value">
                <div class="flex items-baseline justify-between text-sm">
                  <span class="text-slate-700">
                    {{ cell.label }}
                    <span v-if="cell.out_of_plan" class="ml-1 text-[0.65rem] uppercase tracking-wide text-amber-600">{{ $t('sample.outOfPlan') }}</span>
                  </span>
                  <span class="tabular-nums text-slate-500">
                    {{ num(cell.done) }}<template v-if="cell.target != null"> / {{ num(cell.target) }}</template>
                  </span>
                </div>
                <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                  <div
                    class="h-full rounded-full"
                    :class="cell.target == null ? 'bg-amber-400' : barFill((cell.pct ?? 0) >= 100).class"
                    :style="[cell.target == null ? null : barFill((cell.pct ?? 0) >= 100).style, { width: (cell.target == null ? 100 : barWidth(cell.done, cell.target)) + '%' }]"
                  ></div>
                </div>
              </div>
            </div>

            <!-- Proyección -->
            <div v-if="team.projection" class="mt-4 border-t border-slate-100 pt-3 text-xs text-slate-500">
              <span class="font-medium text-slate-600">{{ $t('sample.projection') }}:</span>
              <template v-if="team.projection.met">
                <span class="ml-1" v-bind="metText()">{{ $t('sample.projectionMet') }}</span>
              </template>
              <template v-else-if="team.projection.eta">
                <span class="ml-1">{{ $t('sample.projectionRate', { n: (team.projection.rate_per_day ?? 0).toLocaleString(locale, { maximumFractionDigits: 1 }) }) }}</span>
                · <span class="font-medium text-slate-700">{{ $t('sample.projectionEta', { date: team.projection.eta }) }}</span>
              </template>
              <template v-else>
                <span class="ml-1">{{ $t('sample.projectionNoData') }}</span>
              </template>
              <span v-if="team.projection.first_submission" class="ml-2 text-slate-400">· {{ $t('sample.firstSubmission', { date: (team.projection.first_submission || '').slice(0, 10) }) }}</span>
            </div>
          </div>
        </section>
        </template>
      </template>
      </template>

      <!-- ===== Modos TABLA y MAPA DE CALOR: filas=equipos o meta-equipos, columnas=valores ===== -->
      <section v-else-if="view === 'table' || view === 'heatmap'" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <div class="overflow-x-auto">
          <table class="w-full whitespace-nowrap text-left text-sm">
            <thead class="text-xs uppercase tracking-wider text-slate-400">
              <tr>
                <th class="py-1.5 pr-4">{{ groupOn ? data.group_field.label : $t('sample.teamHeader') }}</th>
                <th v-for="v in tableValues" :key="v.value" class="py-1.5 pr-4">{{ v.label }}</th>
                <th class="py-1.5">{{ $t('sample.totalHeader') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in tableRows" :key="row.team.key" class="border-t border-slate-100">
                <td class="py-2 pr-4 font-medium text-slate-700">
                  {{ row.team.name }}
                  <span v-if="row.team.out_of_plan" class="ml-1 text-[0.65rem] uppercase tracking-wide text-amber-600">{{ $t('sample.outOfPlan') }}</span>
                </td>
                <td v-for="v in tableValues" :key="v.value" class="py-2 pr-4 tabular-nums align-top">
                  <template v-if="row.map[v.value]">
                    <!-- Mapa de calor: chip coloreado por % de cumplimiento -->
                    <span
                      v-if="view === 'heatmap'"
                      class="inline-block min-w-14 rounded-md px-2 py-1 text-center text-xs font-semibold"
                      v-bind="heatCell(row.map[v.value])"
                      :title="row.map[v.value].pct != null ? pct(row.map[v.value].pct) : $t('sample.trafficNoTarget')"
                    >
                      {{ num(row.map[v.value].done) }}<template v-if="row.map[v.value].target != null">/{{ num(row.map[v.value].target) }}</template>
                    </span>
                    <!-- Tabla: hecho / objetivo + faltan -->
                    <template v-else>
                      <span class="font-semibold text-slate-700">{{ num(row.map[v.value].done) }}</span><span v-if="row.map[v.value].target != null" class="text-slate-400"> / {{ num(row.map[v.value].target) }}</span>
                      <div class="text-xs">
                        <span v-if="row.map[v.value].target == null" class="text-amber-600">{{ $t('sample.outOfPlan') }}</span>
                        <span v-else-if="row.map[v.value].done >= row.map[v.value].target" v-bind="metText()">{{ row.map[v.value].done > row.map[v.value].target ? '+' + num(row.map[v.value].done - row.map[v.value].target) : '✓' }}</span>
                        <span v-else class="text-slate-400">{{ $t('sample.cellRemaining', { n: num(row.map[v.value].target - row.map[v.value].done) }) }}</span>
                      </div>
                    </template>
                  </template>
                  <span v-else class="text-slate-300">—</span>
                </td>
                <td class="py-2 tabular-nums align-top">
                  <span class="font-semibold text-slate-700">{{ num(row.team.done) }}</span><span class="text-slate-400"> / {{ num(row.team.target) }}</span>
                  <div v-if="view === 'table' && row.team.target > 0" class="text-xs">
                    <span v-if="row.team.done >= row.team.target" v-bind="metText()">{{ row.team.done > row.team.target ? '+' + num(row.team.done - row.team.target) : '✓' }}</span>
                    <span v-else class="text-slate-400">{{ $t('sample.cellRemaining', { n: num(row.team.target - row.team.done) }) }}</span>
                  </div>
                </td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="border-t-2 border-slate-200">
                <td class="py-2 pr-4 text-xs font-medium uppercase tracking-wider text-slate-400">{{ $t('sample.totalHeader') }}</td>
                <td v-for="(ct, i) in colTotals" :key="tableValues[i].value" class="py-2 pr-4 tabular-nums">
                  <span class="font-semibold text-slate-700">{{ num(ct.done) }}</span><span v-if="ct.target != null" class="text-slate-400"> / {{ num(ct.target) }}</span>
                </td>
                <td class="py-2 tabular-nums">
                  <span class="font-semibold text-slate-900">{{ num(data.grand.done) }}</span><span class="text-slate-400"> / {{ num(data.grand.target) }}</span>
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      </section>

      <!-- ===== Modo BARRAS: hecho vs objetivo por equipo (Chart.js). En pantalla
           estrecha las barras NO se encogen: ancho mínimo por equipo y scroll
           horizontal, como las tablas. ===== -->
      <section v-else-if="view === 'bars'" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <div class="overflow-x-auto">
          <div class="h-72" :style="{ minWidth: `max(${displayTeams.length * 44}px, 100%)` }">
            <StatsChart type="bar" :data="barsData" :options="barsOptions" />
          </div>
        </div>
      </section>

      <!-- ===== Modo SEMÁFORO: rejilla sin cifras, estado por celda ===== -->
      <section v-else-if="view === 'traffic'" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <div class="overflow-x-auto">
          <table class="whitespace-nowrap text-left text-sm">
            <thead class="text-xs uppercase tracking-wider text-slate-400">
              <tr>
                <th class="py-1.5 pr-4">{{ groupOn ? data.group_field.label : $t('sample.teamHeader') }}</th>
                <th v-for="v in tableValues" :key="v.value" class="py-1.5 pr-3 font-medium">{{ v.label }}</th>
                <th class="py-1.5">{{ $t('sample.totalHeader') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in tableRows" :key="row.team.key" class="border-t border-slate-100">
                <td class="py-2 pr-4 font-medium text-slate-700">
                  {{ row.team.name }}
                  <span v-if="row.team.out_of_plan" class="ml-1 text-[0.65rem] uppercase tracking-wide text-amber-600">{{ $t('sample.outOfPlan') }}</span>
                </td>
                <td v-for="v in tableValues" :key="v.value" class="py-2 pr-3">
                  <span
                    class="inline-block h-6 w-6 rounded-md"
                    v-bind="trafficCell(row.map[v.value])"
                    :title="trafficTitle(row.map[v.value], v.label)"
                  ></span>
                </td>
                <td class="py-2">
                  <span class="inline-flex items-center gap-2">
                    <span
                      class="inline-block h-6 w-6 rounded-md"
                      v-bind="trafficCell(row.team.target > 0 ? row.team : null)"
                      :title="trafficTitle(row.team.target > 0 ? row.team : null, row.team.name)"
                    ></span>
                    <!-- % cumplido del total de la fila: el extra de cifra que el semáforo no da -->
                    <span v-if="row.team.pct != null" class="text-xs tabular-nums text-slate-500">{{ pct(row.team.pct) }}</span>
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <!-- Leyenda (sigue la paleta activa) -->
        <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-slate-100 pt-3 text-xs text-slate-500">
          <span class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded" v-bind="trafficChip('met')"></span>{{ $t('sample.trafficMet') }}</span>
          <span class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded" v-bind="trafficChip('onpace')"></span>{{ $t('sample.trafficOnPace') }}</span>
          <span class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded" v-bind="trafficChip('behind')"></span>{{ $t('sample.trafficBehind') }}</span>
          <span class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded" v-bind="trafficChip('none')"></span>{{ $t('sample.trafficNoTarget') }}</span>
        </div>
        <p class="mt-1 text-xs text-slate-400">{{ $t('sample.trafficLegendHint', { pct: pct(globalPct) }) }}</p>
      </section>

      <!-- ===== Modo RESUMEN: doughnut global + lista compacta de equipos ===== -->
      <section v-else-if="view === 'summary'" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <div class="grid items-start gap-6 sm:grid-cols-2">
          <div>
            <div class="h-64"><StatsChart type="doughnut" :data="summaryData" :options="summaryOptions" /></div>
            <!-- Estadísticas agregadas (sobre los equipos CON plan; la cabecera ya
                 da hecho/objetivo/backlog, aquí va lo que la tarjeta no cuenta) -->
            <div v-if="summaryStats.teams" class="mt-4 space-y-3 border-t border-slate-100 pt-3 text-xs text-slate-500">
              <!-- (1) Proyección agregada -->
              <p>
                <span class="font-medium text-slate-600">{{ $t('sample.projection') }}:</span>
                {{ $t('sample.summaryProjection', { n: num(summaryStats.projMet), m: num(summaryStats.teams) }) }}
              </p>
              <!-- (2) Reparto por estado de avance: barra apilada + leyenda con cifras -->
              <div>
                <div class="flex h-2.5 w-full overflow-hidden rounded-full bg-slate-100">
                  <div
                    v-for="st in summaryStates"
                    :key="st.key"
                    class="h-full"
                    :class="st.chip.class"
                    :style="[st.chip.style, { width: (st.n * 100) / summaryStats.teams + '%' }]"
                  ></div>
                </div>
                <div class="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1">
                  <span v-for="st in summaryStates" :key="st.key" class="flex items-center gap-1.5">
                    <span class="inline-block h-3 w-3 rounded" :class="st.chip.class" :style="st.chip.style"></span>
                    {{ $t(st.label) }}: <span class="font-semibold tabular-nums text-slate-700">{{ num(st.n) }}</span>
                  </span>
                </div>
              </div>
              <!-- (3) Mejor y peor equipo + (5) celdas del plan cubiertas -->
              <p v-if="summaryStats.teams > 1" class="space-x-3">
                <span>{{ $t('sample.summaryBest') }}: <span class="font-medium text-slate-700">{{ summaryStats.best.name }}</span> · <span class="tabular-nums">{{ pct(summaryStats.best.pct) }}</span></span>
                <span>{{ $t('sample.summaryWorst') }}: <span class="font-medium text-slate-700">{{ summaryStats.worst.name }}</span> · <span class="tabular-nums">{{ pct(summaryStats.worst.pct) }}</span></span>
              </p>
              <p v-if="summaryStats.cellsTotal">
                {{ $t('sample.summaryCells') }}:
                <span class="font-semibold tabular-nums text-slate-700">{{ num(summaryStats.cellsMet) }} / {{ num(summaryStats.cellsTotal) }}</span>
              </p>
            </div>
          </div>
          <div class="space-y-2">
            <div v-for="team in displayTeams" :key="team.key" class="flex items-baseline justify-between gap-3 text-sm">
              <span class="min-w-0 truncate text-slate-700">
                {{ team.name }}
                <span v-if="team.out_of_plan" class="ml-1 text-[0.65rem] uppercase tracking-wide text-amber-600">{{ $t('sample.outOfPlan') }}</span>
              </span>
              <span class="shrink-0 tabular-nums text-slate-500">
                <span class="font-semibold text-slate-900">{{ num(team.done) }} / {{ num(team.target) }}</span>
                <template v-if="team.pct != null"> · {{ pct(team.pct) }}</template>
              </span>
            </div>
          </div>
        </div>
      </section>
    </template>

    <p v-else class="rounded-xl bg-white p-6 text-center text-sm text-slate-400 shadow-sm ring-1 ring-slate-200">
      {{ $t('sample.noData') }}
    </p>

    <!-- Distribución observada de campos secundarios -->
    <section v-if="data.secondary && data.secondary.length" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <h2 class="font-semibold text-slate-900">{{ $t('sample.secondaryTitle') }}</h2>
      <p class="mt-1 text-sm text-slate-500">{{ $t('sample.secondaryHint') }}</p>
      <div class="mt-4 space-y-5">
        <div v-for="sec in data.secondary" :key="sec.field">
          <div class="flex items-baseline justify-between">
            <h3 class="text-sm font-medium text-slate-700">{{ sec.label }}</h3>
            <span class="text-xs text-slate-400">{{ $t('sample.answered', { n: num(sec.answered) }) }}</span>
          </div>
          <div class="mt-2 space-y-1.5">
            <div v-for="opt in sec.options" :key="opt.label" class="flex items-center gap-2">
              <span class="w-32 shrink-0 truncate text-xs text-slate-600" :title="opt.label">{{ opt.label }}</span>
              <div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100">
                <div class="h-full rounded-full" :class="distBar().class" :style="[distBar().style, { width: Math.min(100, opt.pct) + '%' }]"></div>
              </div>
              <span class="w-20 shrink-0 text-right text-xs tabular-nums text-slate-500">{{ num(opt.count) }} · {{ pct(opt.pct) }}</span>
            </div>
            <p v-if="sec.others" class="text-xs text-slate-400">{{ $t('sample.others') }}: {{ num(sec.others) }}</p>
          </div>
        </div>
      </div>
    </section>

    <p v-if="data.generated_at" class="text-right text-xs text-slate-400">
      {{ $t('sample.generatedAt', { date: data.generated_at }) }} · {{ $t('sample.projectionNote') }}
    </p>
  </div>
</template>
