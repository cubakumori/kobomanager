<script setup>
import { ref, computed, defineAsyncComponent, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { publicApi } from '../services/api'
import { i18n, setLocale } from '../i18n'
import { makeLabeler } from '../composables/labels'
import AttachmentsGallery from '../components/AttachmentsGallery.vue'
import Skeleton from '../components/Skeleton.vue'
import { useTableFreeze, useTableHeaderLines, usePctFormat } from '../composables/appConfig'

// Leaflet y Chart.js solo se descargan si el enlace expone mapa/estadísticas/
// muestra (chunks aparte; los componentes se renderizan bajo v-if).
const LeafletMap = defineAsyncComponent(() => import('../components/LeafletMap.vue'))
const StatsPanels = defineAsyncComponent(() => import('../components/StatsPanels.vue'))
const SamplePanel = defineAsyncComponent(() => import('../components/SamplePanel.vue'))

const { t, locale } = useI18n()
const { freezeFirst } = useTableFreeze()
const { headerLinesClass } = useTableHeaderLines()
const route = useRoute()
const router = useRouter()
const token = computed(() => String(route.params.token))

// Estado general del enlace.
const meta = ref(null)
const loading = ref(true)
const fatal = ref('') // error que impide cargar (enlace inválido/caducado)

// Ticket para enlaces con contraseña (en memoria; no se persiste).
const ticket = ref(null)
const password = ref('')
const unlocking = ref(false)
const pwError = ref('')

function cfg(params) {
  return {
    params,
    headers: ticket.value ? { 'X-Share-Ticket': ticket.value } : {},
  }
}

// Vistas disponibles según los flags del enlace (en orden de pestaña).
const availableViews = computed(() => {
  const v = []
  if (meta.value?.expose_list) v.push('list')
  if (meta.value?.expose_map) v.push('map')
  if (meta.value?.expose_stats) v.push('stats')
  if (meta.value?.expose_sample) v.push('sample')
  if (meta.value?.expose_review_summary) v.push('review')
  return v
})

// Vista activa: 'list' | 'map' | 'stats'; el detalle se controla con ?sub=<uid>.
const view = computed(() => {
  const q = String(route.query.view || '')
  if (q && availableViews.value.includes(q)) return q
  return availableViews.value[0] || 'list'
})
const currentSub = computed(() => (route.query.sub ? String(route.query.sub) : null))

// push (no replace): abrir un detalle o cambiar de pestaña debe crear entrada de
// historial — el botón «atrás» (sobre todo el físico en móvil) tiene que volver a
// la vista anterior del enlace, no sacar al visitante del share. `replace: true`
// queda para correcciones internas (p. ej. cerrar un detalle que falló al cargar).
function go(query, { replace = false } = {}) {
  const to = { name: 'share', params: { token: token.value }, query: { ...route.query, ...query } }
  replace ? router.replace(to) : router.push(to)
}
function setView(v) {
  router.push({ name: 'share', params: { token: token.value }, query: v === 'list' ? {} : { view: v } })
}

// ---------- carga de metadatos ----------
async function loadMeta() {
  loading.value = true
  fatal.value = ''
  try {
    const { data } = await publicApi.get(`/public/share/${token.value}`, cfg())
    meta.value = data.data
    // Idioma por defecto del servidor en la primera carga.
    if (meta.value.default_locale && i18n.global.locale.value !== meta.value.default_locale) {
      setLocale(meta.value.default_locale)
    }
    if (meta.value.unlocked) await loadActiveView()
  } catch (e) {
    fatal.value = t('share.loadError')
  } finally {
    loading.value = false
  }
}

async function unlock() {
  unlocking.value = true
  pwError.value = ''
  try {
    const { data } = await publicApi.post(`/public/share/${token.value}/unlock`, { password: password.value })
    ticket.value = data.data.ticket
    await loadMeta()
  } catch (e) {
    const code = e?.response?.data?.error?.code
    pwError.value = code === 'AUTH_RATE_LIMITED' ? t('errors.AUTH_RATE_LIMITED') : t('share.wrongPassword')
  } finally {
    unlocking.value = false
  }
}

// ---------- lista ----------
const list = ref({ items: [], total: 0, page: 1, per_page: 25, schema: null, label_mode: 'raw' })
const search = ref('')
const listLoading = ref(false)
const labeler = computed(() => makeLabeler(list.value.schema, list.value.label_mode, list.value.field_truncate))

// Columnas: hasta 4 campos con etiqueta (o las primeras 4), como en el panel.
const columns = computed(() => {
  const first = list.value.items[0]?.data
  if (!first) return []
  const all = Object.keys(first).filter((k) => !k.startsWith('_'))
  if (labeler.value.on) {
    const labeled = all.filter((k) => labeler.value.hasLabel(k))
    if (labeled.length) return labeled.slice(0, 4)
  }
  return all.slice(0, 4)
})
const pages = computed(() => Math.max(1, Math.ceil(list.value.total / list.value.per_page)))

// Errores de carga por sección: un 500 o un rate-limit NO debe disfrazarse de
// «no hay datos» (formulario aparentemente vacío) ni dejar la página anterior en
// pantalla sin aviso. Cada loader marca su sección; el template lo diferencia.
const loadErrors = ref({ list: false, map: false, stats: false, sample: false, review: false })

async function loadList(page = 1) {
  listLoading.value = true
  loadErrors.value.list = false
  try {
    const { data } = await publicApi.get(
      `/public/share/${token.value}/submissions`,
      cfg({ page, per_page: list.value.per_page, search: search.value || undefined }),
    )
    list.value = { ...data.data }
  } catch {
    // Se conserva la lista anterior, pero con aviso (sin él, el visitante creería
    // estar viendo la página que pidió).
    loadErrors.value.list = true
  } finally {
    listLoading.value = false
  }
}

// Búsqueda con debounce (como la tabla interna); Enter sigue disparando al momento.
let searchTimer
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => loadList(1), 300)
})
function searchNow() {
  clearTimeout(searchTimer)
  loadList(1)
}

// ---------- detalle ----------
const detail = ref(null)
const detailLoading = ref(false)
const detailLabeler = computed(() => makeLabeler(detail.value?.schema, detail.value?.label_mode, detail.value?.field_truncate))
const detailFields = computed(() => {
  const d = detail.value?.data ?? {}
  return Object.entries(d).filter(([k]) => !k.startsWith('_'))
})
// URL del proxy público de adjuntos. El ticket (si el enlace tiene contraseña)
// viaja en ?k= porque un <img>/<audio> no puede enviar la cabecera X-Share-Ticket.
function attUrl(att) {
  const base = `/api/v1/public/share/${token.value}/submissions/${currentSub.value}/attachments/${att.uid}`
  return ticket.value ? `${base}?k=${encodeURIComponent(ticket.value)}` : base
}

async function loadDetail(uid) {
  detailLoading.value = true
  try {
    const { data } = await publicApi.get(`/public/share/${token.value}/submissions/${uid}`, cfg())
    detail.value = data.data
  } catch {
    detail.value = null
    go({ sub: undefined }, { replace: true })
  } finally {
    detailLoading.value = false
  }
}

// ---------- mapa ----------
const points = ref([])
const mapLoading = ref(false)
const features = computed(() =>
  points.value.map((p) => ({ kind: 'point', points: [[p.lat, p.lng]], label: p.submitted_at, uid: p.submission_uid })),
)
async function loadMap() {
  mapLoading.value = true
  loadErrors.value.map = false
  try {
    const { data } = await publicApi.get(`/public/share/${token.value}/map`, cfg())
    points.value = data.data.points
  } catch {
    points.value = []
    loadErrors.value.map = true
  } finally {
    mapLoading.value = false
  }
}

// ---------- estadísticas ----------
const stats = ref(null)
const statsLoading = ref(false)
async function loadStats() {
  statsLoading.value = true
  loadErrors.value.stats = false
  try {
    const { data } = await publicApi.get(`/public/share/${token.value}/stats`, cfg())
    stats.value = data.data
  } catch {
    stats.value = null
    loadErrors.value.stats = true
  } finally {
    statsLoading.value = false
  }
}

// ---------- panel de muestra (opt-in del enlace) ----------
// El mismo SamplePanel de la vista interna, en modo readonly (el denominador es
// el del plan, informativo; el toggle transitorio es herramienta del revisor).
const sample = ref(null)
const sampleLoading = ref(false)
async function loadSample() {
  sampleLoading.value = true
  loadErrors.value.sample = false
  try {
    const { data } = await publicApi.get(`/public/share/${token.value}/sample`, cfg())
    sample.value = data.data
  } catch {
    sample.value = null
    loadErrors.value.sample = true
  } finally {
    sampleLoading.value = false
  }
}

// ---------- resumen de revisión (opt-in del enlace) ----------
// Misma presentación que la sección homónima de Control de calidad, pero con los
// recuentos que devuelve el endpoint público (agregados; sin datos de envíos).
const review = ref(null)
const reviewLoading = ref(false)
const REVIEW_STATUSES = ['pending', 'on_hold', 'approved', 'rejected']
const REVIEW_CLS = {
  pending: 'bg-amber-100 text-amber-700',
  on_hold: 'bg-sky-100 text-sky-700',
  approved: 'bg-success-100 text-success-700',
  rejected: 'bg-red-100 text-red-700',
}
const { formatPctNumber } = usePctFormat()
const pctOf = (n, total) => (total > 0 ? formatPctNumber((n * 100) / total, locale.value) + ' %' : '—')
const reviewHasTeams = computed(() => !!review.value?.team_field)
async function loadReview() {
  reviewLoading.value = true
  loadErrors.value.review = false
  try {
    const { data } = await publicApi.get(`/public/share/${token.value}/review-summary`, cfg())
    review.value = data.data
  } catch {
    review.value = null
    loadErrors.value.review = true
  } finally {
    reviewLoading.value = false
  }
}

// Carga la vista activa (lista / mapa / estadísticas / revisión) según los flags del enlace.
async function loadActiveView() {
  if (currentSub.value && meta.value?.expose_detail) {
    await loadDetail(currentSub.value)
  } else if (view.value === 'map') {
    await loadMap()
  } else if (view.value === 'stats') {
    await loadStats()
  } else if (view.value === 'sample') {
    await loadSample()
  } else if (view.value === 'review') {
    await loadReview()
  } else if (meta.value?.expose_list) {
    await loadList(1)
  }
}

function toggleLocale() {
  setLocale(i18n.global.locale.value === 'es' ? 'en' : 'es')
}

// Reaccionar a cambios de query (vista/detalle) sin recargar metadatos.
watch(
  () => [route.query.view, route.query.sub],
  () => {
    if (!meta.value?.unlocked) return
    loadActiveView()
  },
)

onMounted(loadMeta)
</script>

<template>
  <div class="flex min-h-screen flex-col bg-slate-50 text-slate-800">
    <!-- Encabezado ligero (sin shell de la app) -->
    <header class="sticky top-0 z-20 border-b border-slate-200 bg-white/80 backdrop-blur">
      <div class="mx-auto flex w-full max-w-5xl items-center justify-between gap-4 px-5 py-3">
        <div class="min-w-0">
          <p class="truncate text-base font-semibold tracking-tight text-slate-900">
            {{ meta?.label || meta?.form?.name || 'KoboManager' }}
          </p>
          <p v-if="meta?.form && meta?.label" class="truncate text-xs text-slate-400">{{ meta.form.name }}</p>
        </div>
        <button class="rounded-lg px-2 py-1 text-sm font-semibold text-slate-500 hover:text-slate-900" @click="toggleLocale">
          {{ $i18n.locale === 'es' ? 'EN' : 'ES' }}
        </button>
      </div>
    </header>

    <main class="mx-auto w-full max-w-5xl flex-1 px-5 py-6">
      <Skeleton v-if="loading" variant="lines" :lines="6" />

      <!-- Enlace inválido / caducado -->
      <div v-else-if="fatal" class="rounded-xl bg-white px-6 py-12 text-center shadow-sm ring-1 ring-slate-200">
        <p class="text-lg font-medium text-slate-700">{{ fatal }}</p>
      </div>

      <!-- Puerta de contraseña -->
      <div v-else-if="meta && !meta.unlocked" class="mx-auto max-w-sm rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
        <h1 class="text-lg font-semibold text-slate-900">{{ $t('share.passwordTitle') }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ $t('share.passwordPrompt') }}</p>
        <form class="mt-4 space-y-3" @submit.prevent="unlock">
          <input
            v-model="password"
            type="password"
            autocomplete="current-password"
            :placeholder="$t('share.password')"
            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
          />
          <p v-if="pwError" class="text-sm text-red-600 dark:text-red-400">{{ pwError }}</p>
          <button
            type="submit"
            :disabled="unlocking || !password"
            class="w-full rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-60"
          >
            {{ unlocking ? $t('share.unlocking') : $t('share.unlock') }}
          </button>
        </form>
      </div>

      <!-- Contenido desbloqueado -->
      <template v-else-if="meta && meta.unlocked">
        <!-- Sello de frescura (caché local refrescada por el cron) y, si el enlace fija
             un alcance por estado, su anuncio: así los recuentos de todas las vistas
             (lista, mapa, estadísticas) no desconciertan al visitante. -->
        <div v-if="meta.last_synced_at || meta.status_scope === 'approved'" class="mb-4 space-y-0.5">
          <p v-if="meta.last_synced_at" class="text-xs text-slate-400">
            {{ $t('share.dataAsOf', { date: meta.last_synced_at }) }}
          </p>
          <p v-if="meta.status_scope === 'approved'" class="text-xs font-medium text-slate-500">
            {{ $t('share.scopeApprovedNote') }}
          </p>
        </div>

        <!-- Detalle de un envío -->
        <section v-if="currentSub && meta.expose_detail" class="space-y-5">
          <button class="text-sm text-primary-600 hover:underline" @click="go({ sub: undefined })">{{ $t('share.back') }}</button>
          <Skeleton v-if="detailLoading" variant="lines" :lines="8" />
          <template v-else-if="detail">
            <header>
              <h1 class="text-xl font-semibold tracking-tight text-slate-900">{{ $t('share.detailTitle') }}</h1>
              <p class="mt-1 text-sm text-slate-500">{{ $t('share.submittedAt', { date: detail.submitted_at }) }}</p>
            </header>

            <div class="flex items-center gap-1">
              <button
                :disabled="!detail.prev"
                class="rounded-lg border px-2.5 py-1 text-sm font-medium"
                :class="detail.prev ? 'border-slate-300 text-slate-700 hover:bg-slate-50' : 'cursor-not-allowed border-slate-200 text-slate-300'"
                @click="detail.prev && go({ sub: detail.prev })"
              >{{ $t('share.prev') }}</button>
              <button
                :disabled="!detail.next"
                class="rounded-lg border px-2.5 py-1 text-sm font-medium"
                :class="detail.next ? 'border-slate-300 text-slate-700 hover:bg-slate-50' : 'cursor-not-allowed border-slate-200 text-slate-300'"
                @click="detail.next && go({ sub: detail.next })"
              >{{ $t('share.next') }}</button>
            </div>

            <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
              <dl class="divide-y divide-slate-100">
                <div v-for="[k, v] in detailFields" :key="k" class="grid grid-cols-3 gap-4 px-5 py-3">
                  <dt class="text-sm font-medium text-slate-500" :title="detailLabeler.fullLabel(k)">{{ detailLabeler.label(k) }}</dt>
                  <dd class="col-span-2 text-sm text-slate-800">{{ detailLabeler.value(k, v) }}</dd>
                </div>
                <div v-if="!detailFields.length" class="px-5 py-3 text-sm text-slate-400">{{ $t('share.noFields') }}</div>
              </dl>
            </section>

            <section v-if="detail.geo && detail.geo.length" class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
              <h2 class="border-b border-slate-100 px-5 py-3 font-semibold text-slate-900">{{ $t('share.location') }}</h2>
              <div class="p-5"><LeafletMap :features="detail.geo" height="320px" /></div>
            </section>

            <section v-if="detail.expose_attachments && detail.attachments && detail.attachments.length" class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
              <h2 class="border-b border-slate-100 px-5 py-3 font-semibold text-slate-900">
                {{ $t('attachments.title', { n: detail.attachments.length }) }}
              </h2>
              <div class="p-5"><AttachmentsGallery :attachments="detail.attachments" :url-for="attUrl" /></div>
            </section>
          </template>
        </section>

        <!-- Lista / Mapa / Estadísticas -->
        <section v-else class="space-y-4">
          <!-- Pestañas (solo si hay más de una vista) -->
          <div v-if="availableViews.length > 1" class="flex gap-1 border-b border-slate-200">
            <button
              v-for="v in availableViews"
              :key="v"
              class="border-b-2 px-4 py-2 text-sm font-medium"
              :class="view === v ? 'border-primary-600 text-primary-700' : 'border-transparent text-slate-500 hover:text-slate-700'"
              @click="setView(v)"
            >{{ $t('share.tab' + v.charAt(0).toUpperCase() + v.slice(1)) }}</button>
          </div>

          <!-- Estadísticas -->
          <template v-if="view === 'stats'">
            <Skeleton v-if="statsLoading" variant="cards" :count="4" />
            <p v-else-if="loadErrors.stats" class="rounded-xl bg-red-50 px-5 py-8 text-center text-sm text-red-700 ring-1 ring-red-200" role="alert">
              {{ $t('share.loadError') }}
            </p>
            <StatsPanels v-else-if="stats" :stats="stats" />
            <p v-else class="rounded-xl bg-white px-5 py-8 text-center text-sm text-slate-400 ring-1 ring-slate-200">
              {{ $t('stats.noData') }}
            </p>
          </template>

          <!-- Panel de muestra (cumplimiento agregado hecho/objetivo por equipo) -->
          <template v-else-if="view === 'sample'">
            <Skeleton v-if="sampleLoading" variant="cards" :count="3" />
            <p v-else-if="loadErrors.sample" class="rounded-xl bg-red-50 px-5 py-8 text-center text-sm text-red-700 ring-1 ring-red-200" role="alert">
              {{ $t('share.loadError') }}
            </p>
            <SamplePanel v-else-if="sample" :data="sample" readonly />
            <p v-else class="rounded-xl bg-white px-5 py-8 text-center text-sm text-slate-400 ring-1 ring-slate-200">
              {{ $t('sample.noData') }}
            </p>
          </template>

          <!-- Resumen de revisión (recuentos agregados por equipo/encuestador) -->
          <template v-else-if="view === 'review'">
            <Skeleton v-if="reviewLoading" variant="lines" :lines="6" />
            <p v-else-if="loadErrors.review" class="rounded-xl bg-red-50 px-5 py-8 text-center text-sm text-red-700 ring-1 ring-red-200" role="alert">
              {{ $t('share.loadError') }}
            </p>
            <section v-else-if="review && review.review_summary.length" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
              <h2 class="font-semibold text-slate-900">{{ $t('share.reviewTitle') }}</h2>
              <p class="mb-3 text-xs text-slate-400">{{ $t('share.reviewDesc') }}</p>
              <div class="space-y-3">
                <details
                  v-for="(team, i) in review.review_summary"
                  :key="i"
                  class="overflow-hidden rounded-lg ring-1 ring-slate-200"
                  :open="!reviewHasTeams || review.review_summary.length <= 3"
                >
                  <summary
                    class="flex cursor-pointer list-none flex-wrap items-center gap-x-3 gap-y-1 bg-slate-50 px-4 py-2 hover:bg-slate-100"
                    :class="{ 'pointer-events-none': !reviewHasTeams }"
                  >
                    <span class="font-medium text-slate-800">{{ reviewHasTeams ? team.name : $t('stats.qualityAllEnumerators') }}</span>
                    <span class="text-xs text-slate-500">{{ $t('stats.colVolume') }}: {{ team.total }}</span>
                    <span class="ml-auto flex flex-wrap gap-1.5 text-xs">
                      <span v-for="s in REVIEW_STATUSES" :key="s" v-show="team.status[s]" class="rounded-full px-2 py-0.5" :class="REVIEW_CLS[s]">
                        {{ $t('review.' + s) }}: <span class="font-semibold">{{ team.status[s] }}</span>
                      </span>
                    </span>
                  </summary>
                  <div class="overflow-x-auto border-t border-slate-100">
                    <table class="w-full whitespace-nowrap text-left text-sm">
                      <thead class="text-xs uppercase tracking-wider text-slate-400">
                        <tr>
                          <th class="px-4 py-1.5">{{ $t('stats.colEnumerator') }}</th>
                          <th class="px-4 py-1.5">{{ $t('stats.colVolume') }}</th>
                          <th v-for="s in REVIEW_STATUSES" :key="s" class="px-4 py-1.5">{{ $t('review.' + s) }}</th>
                        </tr>
                      </thead>
                      <tbody class="divide-y divide-slate-100">
                        <tr v-for="(e, j) in team.enumerators" :key="j">
                          <td class="px-4 py-1.5 font-medium text-slate-700">{{ e.name }}</td>
                          <td class="px-4 py-1.5 text-slate-600">{{ e.total }}</td>
                          <td v-for="s in REVIEW_STATUSES" :key="s" class="px-4 py-1.5" :class="e.status[s] ? 'text-slate-700' : 'text-slate-300'">
                            <template v-if="e.status[s]">
                              {{ e.status[s] }}
                              <span class="text-xs text-slate-400">· {{ pctOf(e.status[s], e.total) }}</span>
                            </template>
                            <template v-else>—</template>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </details>
              </div>
            </section>
            <p v-else class="rounded-xl bg-white px-5 py-8 text-center text-sm text-slate-400 ring-1 ring-slate-200">
              {{ $t('share.reviewEmpty') }}
            </p>
          </template>

          <!-- Mapa -->
          <template v-else-if="view === 'map'">
            <Skeleton v-if="mapLoading" variant="lines" :lines="4" />
            <p v-else-if="loadErrors.map" class="rounded-xl bg-red-50 px-5 py-8 text-center text-sm text-red-700 ring-1 ring-red-200" role="alert">
              {{ $t('share.loadError') }}
            </p>
            <LeafletMap
              v-else-if="points.length"
              :features="features"
              height="70vh"
              @select="(uid) => meta.expose_detail && go({ sub: uid })"
            />
            <p v-else class="rounded-xl bg-white px-5 py-8 text-center text-sm text-slate-400 ring-1 ring-slate-200">
              {{ $t('share.mapEmpty') }}
            </p>
          </template>

          <!-- Lista -->
          <template v-else>
            <div class="flex flex-wrap items-center justify-between gap-3">
              <p class="text-sm text-slate-500">
                {{ $t(meta.status_scope === 'approved' ? 'share.totalApproved' : 'share.total', { n: list.total }) }}
              </p>
              <input
                v-model="search"
                :placeholder="$t('share.search')"
                class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
                @keyup.enter="searchNow"
              />
            </div>

            <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
              <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                  <tr>
                    <th class="px-4 py-3" :class="freezeFirst() ? 'sticky left-0 z-10 bg-slate-50' : ''">{{ $t('share.colSubmitted') }}</th>
                    <th v-for="c in columns" :key="c" class="px-4 py-3 align-bottom" :title="labeler.fullLabel(c)">
                      <div :class="headerLinesClass()">{{ labeler.label(c) }}</div>
                    </th>
                    <th v-if="meta.expose_detail" class="px-4 py-3"></th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <tr v-for="it in list.items" :key="it.submission_uid" class="group hover:bg-slate-50">
                    <td class="whitespace-nowrap px-4 py-3 text-slate-500" :class="freezeFirst() ? 'sticky left-0 z-10 bg-white group-hover:bg-slate-50' : ''">{{ it.submitted_at }}</td>
                    <td v-for="c in columns" :key="c" class="max-w-xs truncate whitespace-nowrap px-4 py-3 text-slate-800" :title="labeler.value(c, it.data[c])">{{ labeler.value(c, it.data[c]) }}</td>
                    <td v-if="meta.expose_detail" class="px-4 py-3 text-right">
                      <button class="text-sm font-medium text-primary-600 hover:underline" @click="go({ sub: it.submission_uid })">
                        {{ $t('share.viewDetail') }}
                      </button>
                    </td>
                  </tr>
                  <tr v-if="!list.items.length && !listLoading">
                    <td :colspan="columns.length + (meta.expose_detail ? 2 : 1)" class="px-4 py-8 text-center text-slate-400">
                      {{ $t('share.empty') }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- Aviso de carga fallida (se conserva la página anterior en pantalla) -->
            <p v-if="loadErrors.list" class="rounded-xl bg-red-50 px-4 py-3 text-center text-sm text-red-700 ring-1 ring-red-200" role="alert">
              {{ $t('share.loadError') }}
            </p>

            <!-- Paginación -->
            <div v-if="pages > 1" class="flex items-center justify-center gap-2">
              <button
                :disabled="list.page <= 1"
                class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm disabled:opacity-50"
                @click="loadList(list.page - 1)"
              >{{ $t('share.prevPage') }}</button>
              <span class="text-sm text-slate-500">{{ $t('share.page', { page: list.page, pages }) }}</span>
              <button
                :disabled="list.page >= pages"
                class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm disabled:opacity-50"
                @click="loadList(list.page + 1)"
              >{{ $t('share.nextPage') }}</button>
            </div>
          </template>
        </section>
      </template>
    </main>

    <footer class="border-t border-slate-200 px-5 py-5 text-center text-xs text-slate-400">
      {{ $t('share.poweredBy') }}
    </footer>
  </div>
</template>
