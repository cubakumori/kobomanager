<script setup>
import { ref, computed, onMounted, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '../../services/api'
import { apiError } from '../../stores/auth'
import { useAuthStore } from '../../stores/auth'
import { setLocale } from '../../i18n'
import { useTableFreeze, useTableHeaderLines, useDemoMode, usePctFormat } from '../../composables/appConfig'
import Skeleton from '../../components/Skeleton.vue'

const { t } = useI18n()
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const STATUS_KEYS = ['deployed', 'draft', 'archived']

const selected = ref([])
const defaultLocale = ref('es')
const validLocales = ref(['es', 'en'])
const labelMode = ref('labels')
const validLabelModes = ref(['labels', 'raw'])
const passwordResetEnabled = ref(false)
const auditSelfViewEnabled = ref(false)
const auditRetentionDays = ref(0)
const auditRetentionMax = ref(3650)
const notificationsDefaultOn = ref(false)
const defaultTheme = ref('auto')
const validThemes = ref(['light', 'dark', 'auto'])
const showThemeToggle = ref(true)
const supportPageEnabled = ref(true)
const landingCtaEnabled = ref(true)
const { tableFreeze: appTableFreeze } = useTableFreeze()
const { pctFormat: appPctFormat } = usePctFormat()
const { tableHeaderLines: appHeaderLines } = useTableHeaderLines()
// En demo los ajustes globales son de solo lectura (PUT bloqueado).
const { demoMode } = useDemoMode()
const tableFreeze = ref('first')
const validTableFreeze = ref(['first', 'none'])
const formsOrder = ref('account_name')
const validFormsOrder = ref(['account_name', 'name', 'recent_sync', 'recent_created'])
const statsDefaultScope = ref('approved')
const validStatsDefaultScope = ref(['all', 'approved'])
const statsTeamCap = ref('20')
const validStatsTeamCap = ref(['20', '50', 'all'])
const showViewSubmissionsLink = ref(true)
const qcScope = ref('pending_hold')
const validQcScope = ref(['pending_hold', 'all'])
const pctFormat = ref('integer')
const validPctFormat = ref(['integer', 'decimals'])
const qcAdmitBatch = ref('table')
const validQcAdmitBatch = ref(['table', 'qc', 'both', 'off'])
const tableHeaderLines = ref(2)
const validTableHeaderLines = ref([1, 2, 3])
const mailConfigured = ref(false)
const viewerActions = ref({ enketo: false, update: false, resync: false, login: false })
const VIEWER_ACTION_KEYS = ['enketo', 'update', 'resync', 'login']
const sharePasswordPolicy = ref('optional')
const validSharePolicies = ref(['off', 'optional', 'required'])
const shareAttachmentsPolicy = ref('off')
const validShareAttachmentsPolicies = ref(['off', 'require_password'])
const fieldTruncate = ref({ enabled: false, chars: 24 })
const fieldTruncateMin = ref(8)
const fieldTruncateMax = ref(120)
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const saved = ref(false)

// Pestañas temáticas. «Base de datos» es fija (copia de seguridad/restauración
// para toda instancia); dentro, la tarjeta de la semilla de la demo sigue siendo
// condicional a DEMO_SEED_PATH. El tab activo se refleja en ?tab= para poder
// enlazarlo y sobrevivir a recargas; 'general' va sin query (URL limpia).
const TAB_IDS = ['general', 'tables', 'sync', 'sharing', 'security', 'database']
const initialTab = typeof route.query.tab === 'string' && TAB_IDS.includes(route.query.tab)
  ? route.query.tab
  : 'general'
const tab = ref(initialTab)
const tabs = computed(() => TAB_IDS)

function selectTab(id) {
  tab.value = id
  router.replace({ query: { ...route.query, tab: id === 'general' ? undefined : id } })
}

// Flechas izquierda/derecha mueven la pestaña activa (patrón tablist accesible).
function onTablistKeydown(e) {
  if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return
  e.preventDefault()
  const ids = tabs.value
  const i = ids.indexOf(tab.value)
  const next = ids[(i + (e.key === 'ArrowRight' ? 1 : ids.length - 1)) % ids.length]
  selectTab(next)
  nextTick(() => document.getElementById('settings-tab-' + next)?.focus())
}

// Semilla de la demo (DEMO_SEED_PATH): la pestaña solo aparece si está configurada.
const demoSeed = ref({ configured: false, path: '', exists: false, bytes: null, generated_at: null })
const seedBusy = ref(false)
const seedError = ref('')
const seedDone = ref(null)

function fmtSeedSize(bytes) {
  if (bytes == null) return ''
  return bytes >= 1024 * 1024 ? (bytes / (1024 * 1024)).toFixed(1) + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' KB'
}

function fmtSeedDate(iso) {
  if (!iso) return ''
  try { return new Date(iso).toLocaleString() } catch { return iso }
}

// --- Copia de seguridad / restauración de la BD (pestaña Base de datos) ---
const backupScope = ref('full')
const BACKUP_SCOPES = ['full', 'settings']
// La descarga es una navegación GET normal (attachment): mismo origen, lleva la
// cookie de sesión; en demo el backend responde DEMO_LOCKED igualmente.
const backupHref = computed(() => `/api/v1/admin/db/export?scope=${backupScope.value}`)

const importFile = ref(null)
const importArmed = ref(false) // segundo paso: mostrar aviso + confirmación
const importBusy = ref(false)
const importError = ref('')
const importDone = ref(null)

function onImportFileChange(e) {
  importFile.value = e.target.files?.[0] || null
  importArmed.value = false
  importDone.value = null
  importError.value = ''
}

async function runImport() {
  if (!importFile.value) return
  importBusy.value = true
  importError.value = ''
  importDone.value = null
  try {
    const fd = new FormData()
    fd.append('file', importFile.value)
    // Content-Type indefinido: el navegador pone multipart/form-data con su boundary.
    const { data } = await api.post('/admin/db/import', fd, { headers: { 'Content-Type': undefined } })
    importDone.value = data.data
    importArmed.value = false
  } catch (e) {
    importError.value = apiError(e, t('settings.dbImportError'))
  } finally {
    importBusy.value = false
  }
}

async function generateSeed() {
  seedBusy.value = true
  seedError.value = ''
  seedDone.value = null
  try {
    const { data } = await api.post('/admin/demo/seed')
    seedDone.value = data.data
    if (data.data.status) demoSeed.value = data.data.status
  } catch (e) {
    seedError.value = apiError(e, t('settings.demoSeedError'))
  } finally {
    seedBusy.value = false
  }
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/admin/settings')
    selected.value = data.data.sync_deployment_statuses
    defaultLocale.value = data.data.default_locale
    validLocales.value = data.data.valid_locales
    labelMode.value = data.data.label_mode
    validLabelModes.value = data.data.valid_label_modes
    passwordResetEnabled.value = data.data.password_reset_enabled
    auditSelfViewEnabled.value = data.data.audit_self_view_enabled
    if (data.data.audit_retention_days != null) auditRetentionDays.value = data.data.audit_retention_days
    if (data.data.audit_retention_max != null) auditRetentionMax.value = data.data.audit_retention_max
    if (data.data.notifications_default_on != null) notificationsDefaultOn.value = data.data.notifications_default_on
    defaultTheme.value = data.data.default_theme
    validThemes.value = data.data.valid_themes ?? validThemes.value
    showThemeToggle.value = data.data.show_theme_toggle
    if (data.data.support_page_enabled != null) supportPageEnabled.value = data.data.support_page_enabled
    if (data.data.landing_cta_enabled != null) landingCtaEnabled.value = data.data.landing_cta_enabled
    tableFreeze.value = data.data.table_freeze
    validTableFreeze.value = data.data.valid_table_freeze ?? validTableFreeze.value
    tableHeaderLines.value = data.data.table_header_lines ?? tableHeaderLines.value
    validTableHeaderLines.value = data.data.valid_table_header_lines ?? validTableHeaderLines.value
    if (data.data.forms_order != null) formsOrder.value = data.data.forms_order
    validFormsOrder.value = data.data.valid_forms_order ?? validFormsOrder.value
    if (data.data.stats_default_scope != null) statsDefaultScope.value = data.data.stats_default_scope
    validStatsDefaultScope.value = data.data.valid_stats_default_scope ?? validStatsDefaultScope.value
    if (data.data.stats_team_cap != null) statsTeamCap.value = String(data.data.stats_team_cap)
    validStatsTeamCap.value = data.data.valid_stats_team_cap ?? validStatsTeamCap.value
    if (data.data.show_view_submissions_link != null) showViewSubmissionsLink.value = data.data.show_view_submissions_link
    if (data.data.qc_scope != null) qcScope.value = data.data.qc_scope
    validQcScope.value = data.data.valid_qc_scope ?? validQcScope.value
    if (data.data.pct_format != null) pctFormat.value = data.data.pct_format
    validPctFormat.value = data.data.valid_pct_format ?? validPctFormat.value
    if (data.data.qc_admit_batch != null) qcAdmitBatch.value = data.data.qc_admit_batch
    validQcAdmitBatch.value = data.data.valid_qc_admit_batch ?? validQcAdmitBatch.value
    mailConfigured.value = data.data.mail_configured
    if (data.data.viewer_actions) viewerActions.value = data.data.viewer_actions
    sharePasswordPolicy.value = data.data.share_password_policy
    if (data.data.valid_share_password_policies) validSharePolicies.value = data.data.valid_share_password_policies
    shareAttachmentsPolicy.value = data.data.share_attachments_policy
    if (data.data.valid_share_attachments_policies) validShareAttachmentsPolicies.value = data.data.valid_share_attachments_policies
    if (data.data.field_truncate) fieldTruncate.value = data.data.field_truncate
    if (data.data.field_truncate_min != null) fieldTruncateMin.value = data.data.field_truncate_min
    if (data.data.field_truncate_max != null) fieldTruncateMax.value = data.data.field_truncate_max
    if (data.data.demo_seed) demoSeed.value = data.data.demo_seed
  } catch (e) {
    error.value = apiError(e, t('settings.loadError'))
  } finally {
    loading.value = false
  }
}

function toggle(value) {
  saved.value = false
  const i = selected.value.indexOf(value)
  if (i === -1) selected.value.push(value)
  else selected.value.splice(i, 1)
}

async function save() {
  if (!selected.value.length) {
    error.value = t('settings.selectOne')
    return
  }
  saving.value = true
  error.value = ''
  saved.value = false
  try {
    const { data } = await api.put('/admin/settings', {
      sync_deployment_statuses: selected.value,
      default_locale: defaultLocale.value,
      label_mode: labelMode.value,
      password_reset_enabled: passwordResetEnabled.value,
      audit_self_view_enabled: auditSelfViewEnabled.value,
      audit_retention_days: Math.max(0, Number(auditRetentionDays.value) || 0),
      notifications_default_on: notificationsDefaultOn.value,
      default_theme: defaultTheme.value,
      show_theme_toggle: showThemeToggle.value,
      support_page_enabled: supportPageEnabled.value,
      landing_cta_enabled: landingCtaEnabled.value,
      table_freeze: tableFreeze.value,
      table_header_lines: tableHeaderLines.value,
      forms_order: formsOrder.value,
      stats_default_scope: statsDefaultScope.value,
      stats_team_cap: statsTeamCap.value,
      show_view_submissions_link: showViewSubmissionsLink.value,
      qc_scope: qcScope.value,
      pct_format: pctFormat.value,
      qc_admit_batch: qcAdmitBatch.value,
      viewer_actions: viewerActions.value,
      share_password_policy: sharePasswordPolicy.value,
      share_attachments_policy: shareAttachmentsPolicy.value,
      field_truncate: {
        enabled: fieldTruncate.value.enabled,
        chars: Number(fieldTruncate.value.chars) || fieldTruncateMin.value,
      },
    })
    selected.value = data.data.sync_deployment_statuses
    defaultLocale.value = data.data.default_locale
    labelMode.value = data.data.label_mode
    passwordResetEnabled.value = data.data.password_reset_enabled
    if (data.data.audit_self_view_enabled != null) auditSelfViewEnabled.value = data.data.audit_self_view_enabled
    if (data.data.audit_retention_days != null) auditRetentionDays.value = data.data.audit_retention_days
    if (data.data.notifications_default_on != null) notificationsDefaultOn.value = data.data.notifications_default_on
    if (data.data.default_theme != null) defaultTheme.value = data.data.default_theme
    if (data.data.show_theme_toggle != null) showThemeToggle.value = data.data.show_theme_toggle
    if (data.data.support_page_enabled != null) supportPageEnabled.value = data.data.support_page_enabled
    if (data.data.landing_cta_enabled != null) landingCtaEnabled.value = data.data.landing_cta_enabled
    if (data.data.table_header_lines != null) {
      tableHeaderLines.value = data.data.table_header_lines
      appHeaderLines.value = data.data.table_header_lines
      try { localStorage.setItem('km.cfg.tableHeaderLines', String(data.data.table_header_lines)) } catch { /* noop */ }
    }
    if (data.data.table_freeze != null) {
      tableFreeze.value = data.data.table_freeze
      // Reflejar el cambio en esta misma pestaña (módulo reactivo + caché local).
      appTableFreeze.value = data.data.table_freeze
      try { localStorage.setItem('km.cfg.tableFreeze', data.data.table_freeze) } catch { /* noop */ }
    }
    if (data.data.forms_order != null) formsOrder.value = data.data.forms_order
    if (data.data.stats_default_scope != null) statsDefaultScope.value = data.data.stats_default_scope
    if (data.data.stats_team_cap != null) statsTeamCap.value = String(data.data.stats_team_cap)
    if (data.data.show_view_submissions_link != null) showViewSubmissionsLink.value = data.data.show_view_submissions_link
    if (data.data.qc_scope != null) qcScope.value = data.data.qc_scope
    if (data.data.pct_format != null) {
      pctFormat.value = data.data.pct_format
      // Reflejar el cambio al instante en esta pestaña (módulo reactivo + caché local).
      appPctFormat.value = data.data.pct_format
      try { localStorage.setItem('km.cfg.pctFormat', data.data.pct_format) } catch { /* noop */ }
    }
    if (data.data.qc_admit_batch != null) qcAdmitBatch.value = data.data.qc_admit_batch
    if (data.data.viewer_actions) viewerActions.value = data.data.viewer_actions
    if (data.data.share_password_policy) sharePasswordPolicy.value = data.data.share_password_policy
    if (data.data.share_attachments_policy) shareAttachmentsPolicy.value = data.data.share_attachments_policy
    if (data.data.field_truncate) fieldTruncate.value = data.data.field_truncate
    saved.value = true
    // Si el usuario sigue el idioma por defecto, refleja el cambio al instante.
    if (!auth.user?.locale_pref) setLocale(defaultLocale.value)
  } catch (e) {
    error.value = apiError(e, t('settings.saveError'))
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $t('settings.title') }}</h1>
      <p class="mt-1 text-sm text-slate-500">{{ $t('settings.subtitle') }}</p>
    </header>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900">
      {{ error }}
    </div>
    <Skeleton v-if="loading" variant="lines" :lines="6" />

    <template v-else>
      <!-- Pestañas temáticas -->
      <div
        role="tablist"
        :aria-label="$t('settings.title')"
        class="mb-8 flex gap-1 overflow-x-auto border-b border-slate-200"
        @keydown="onTablistKeydown"
      >
        <button
          v-for="id in tabs"
          :id="'settings-tab-' + id"
          :key="id"
          role="tab"
          :aria-selected="tab === id"
          :tabindex="tab === id ? 0 : -1"
          class="-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium transition-colors"
          :class="tab === id
            ? 'border-primary-600 text-primary-700'
            : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'"
          @click="selectTab(id)"
        >
          {{ $t('settings.tab_' + id) }}
        </button>
      </div>

      <!-- Tipos a sincronizar -->
      <section v-show="tab === 'sync'" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-4">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('settings.syncTypes') }}</h2>
          <p class="mt-0.5 text-sm text-slate-500">{{ $t('settings.syncTypesDesc') }}</p>
        </div>
        <label
          v-for="key in STATUS_KEYS"
          :key="key"
          class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 hover:bg-slate-50"
        >
          <input
            type="checkbox"
            class="mt-0.5 h-4 w-4"
            :checked="selected.includes(key)"
            @change="toggle(key)"
          />
          <span>
            <span class="block text-sm font-medium text-slate-800">{{ $t('settings.' + key) }}</span>
            <span class="block text-xs text-slate-400">{{ $t('settings.' + key + 'Hint') }}</span>
          </span>
        </label>
        <p class="text-xs text-slate-400">{{ $t('settings.syncDefault') }}</p>
      </section>

      <!-- Idioma por defecto -->
      <section v-show="tab === 'general'" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-3">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('settings.language') }}</h2>
          <p class="mt-0.5 text-sm text-slate-500">{{ $t('settings.languageDesc') }}</p>
        </div>
        <select
          v-model="defaultLocale"
          class="w-56 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
          @change="saved = false"
        >
          <option v-for="l in validLocales" :key="l" :value="l">{{ $t('lang.' + l) }}</option>
        </select>
      </section>

      <!-- Tema por defecto -->
      <section v-show="tab === 'general'" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-3">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('settings.theme') }}</h2>
          <p class="mt-0.5 text-sm text-slate-500">{{ $t('settings.themeDesc') }}</p>
        </div>
        <select
          v-model="defaultTheme"
          class="w-56 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
          @change="saved = false"
        >
          <option v-for="th in validThemes" :key="th" :value="th">{{ $t('common.theme_' + th) }}</option>
        </select>
        <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 hover:bg-slate-50">
          <input
            type="checkbox"
            class="mt-0.5 h-4 w-4"
            :checked="showThemeToggle"
            @change="showThemeToggle = !showThemeToggle; saved = false"
          />
          <span>
            <span class="block text-sm font-medium text-slate-800">{{ $t('settings.themeToggle') }}</span>
            <span class="block text-xs text-slate-400">{{ $t('settings.themeToggleHint') }}</span>
          </span>
        </label>
      </section>

      <!-- Parte pública (escaparate): página Apoyar y CTA de la portada -->
      <section v-show="tab === 'general'" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-3">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('settings.publicSurface') }}</h2>
          <p class="mt-0.5 text-sm text-slate-500">{{ $t('settings.publicSurfaceDesc') }}</p>
        </div>
        <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 hover:bg-slate-50">
          <input
            type="checkbox"
            class="mt-0.5 h-4 w-4"
            :checked="supportPageEnabled"
            @change="supportPageEnabled = !supportPageEnabled; saved = false"
          />
          <span>
            <span class="block text-sm font-medium text-slate-800">{{ $t('settings.supportPageToggle') }}</span>
            <span class="block text-xs text-slate-400">{{ $t('settings.supportPageHint') }}</span>
          </span>
        </label>
        <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 hover:bg-slate-50">
          <input
            type="checkbox"
            class="mt-0.5 h-4 w-4"
            :checked="landingCtaEnabled"
            @change="landingCtaEnabled = !landingCtaEnabled; saved = false"
          />
          <span>
            <span class="block text-sm font-medium text-slate-800">{{ $t('settings.landingCtaToggle') }}</span>
            <span class="block text-xs text-slate-400">{{ $t('settings.landingCtaHint') }}</span>
          </span>
        </label>
      </section>

      <!-- Congelado de columnas en tablas -->
      <section v-show="tab === 'tables'" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-3">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('settings.tableFreeze') }}</h2>
          <p class="mt-0.5 text-sm text-slate-500">{{ $t('settings.tableFreezeDesc') }}</p>
        </div>
        <select
          v-model="tableFreeze"
          class="w-72 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
          @change="saved = false"
        >
          <option v-for="tf in validTableFreeze" :key="tf" :value="tf">{{ $t('settings.tableFreeze_' + tf) }}</option>
        </select>
      </section>

      <!-- Orden de las tarjetas en «Mis formularios» -->
      <section v-show="tab === 'tables'" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-3">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('settings.formsOrder') }}</h2>
          <p class="mt-0.5 text-sm text-slate-500">{{ $t('settings.formsOrderDesc') }}</p>
        </div>
        <select
          v-model="formsOrder"
          class="w-72 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
          @change="saved = false"
        >
          <option v-for="fo in validFormsOrder" :key="fo" :value="fo">{{ $t('settings.formsOrder_' + fo) }}</option>
        </select>
        <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 hover:bg-slate-50">
          <input
            type="checkbox"
            class="mt-0.5 h-4 w-4"
            :checked="showViewSubmissionsLink"
            @change="showViewSubmissionsLink = !showViewSubmissionsLink; saved = false"
          />
          <span>
            <span class="block text-sm font-medium text-slate-800">{{ $t('settings.showViewSubmissionsLink') }}</span>
            <span class="block text-xs text-slate-400">{{ $t('settings.showViewSubmissionsLinkHint') }}</span>
          </span>
        </label>
      </section>

      <!-- Alcance por defecto de las estadísticas -->
      <section v-show="tab === 'tables'" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-3">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('settings.statsDefaultScope') }}</h2>
          <p class="mt-0.5 text-sm text-slate-500">{{ $t('settings.statsDefaultScopeDesc') }}</p>
        </div>
        <select
          v-model="statsDefaultScope"
          class="w-72 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
          @change="saved = false"
        >
          <option v-for="sc in validStatsDefaultScope" :key="sc" :value="sc">{{ $t('settings.statsScope_' + sc) }}</option>
        </select>
      </section>

      <!-- Tope del desglose por equipo en estadísticas -->
      <section v-show="tab === 'tables'" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-3">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('settings.statsTeamCap') }}</h2>
          <p class="mt-0.5 text-sm text-slate-500">{{ $t('settings.statsTeamCapDesc') }}</p>
        </div>
        <select
          v-model="statsTeamCap"
          class="w-72 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
          @change="saved = false"
        >
          <option value="20">{{ $t('settings.statsTeamCap20') }}</option>
          <option value="50">{{ $t('settings.statsTeamCap50') }}</option>
          <option value="all">{{ $t('settings.statsTeamCapAll') }}</option>
        </select>
      </section>

      <!-- Alcance del control de calidad -->
      <section v-show="tab === 'tables'" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-3">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('settings.qcScope') }}</h2>
          <p class="mt-0.5 text-sm text-slate-500">{{ $t('settings.qcScopeDesc') }}</p>
        </div>
        <select
          v-model="qcScope"
          class="w-72 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
          @change="saved = false"
        >
          <option v-for="sc in validQcScope" :key="sc" :value="sc">{{ $t('settings.qcScope_' + sc) }}</option>
        </select>
      </section>

      <!-- Formato de los valores porcentuales -->
      <section v-show="tab === 'tables'" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-3">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('settings.pctFormat') }}</h2>
          <p class="mt-0.5 text-sm text-slate-500">{{ $t('settings.pctFormatDesc') }}</p>
        </div>
        <select
          v-model="pctFormat"
          class="w-72 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
          @change="saved = false"
        >
          <option v-for="pf in validPctFormat" :key="pf" :value="pf">{{ $t('settings.pctFormat_' + pf) }}</option>
        </select>
      </section>

      <!-- Atajo «aprobar en lote los admisibles»: dónde se ofrece -->
      <section v-show="tab === 'tables'" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-3">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('settings.qcAdmitBatch') }}</h2>
          <p class="mt-0.5 text-sm text-slate-500">{{ $t('settings.qcAdmitBatchDesc') }}</p>
        </div>
        <select
          v-model="qcAdmitBatch"
          class="w-72 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
          @change="saved = false"
        >
          <option v-for="ab in validQcAdmitBatch" :key="ab" :value="ab">{{ $t('settings.qcAdmitBatch_' + ab) }}</option>
        </select>
        <p class="text-xs text-slate-400">{{ $t('settings.qcAdmitBatchWarn') }}</p>
      </section>

      <!-- Líneas del encabezado de columna en tablas -->
      <section v-show="tab === 'tables'" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-3">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('settings.headerLines') }}</h2>
          <p class="mt-0.5 text-sm text-slate-500">{{ $t('settings.headerLinesDesc') }}</p>
        </div>
        <select
          v-model.number="tableHeaderLines"
          class="w-72 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
          @change="saved = false"
        >
          <option v-for="hl in validTableHeaderLines" :key="hl" :value="hl">{{ $t('settings.headerLines_' + hl) }}</option>
        </select>
      </section>

      <!-- Etiquetas en tabla y detalles -->
      <section v-show="tab === 'tables'" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-4">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('settings.labels') }}</h2>
          <p class="mt-0.5 text-sm text-slate-500">{{ $t('settings.labelsDesc') }}</p>
        </div>
        <label
          v-for="mode in validLabelModes"
          :key="mode"
          class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 hover:bg-slate-50"
        >
          <input
            type="radio"
            class="mt-0.5 h-4 w-4"
            name="label_mode"
            :value="mode"
            :checked="labelMode === mode"
            @change="labelMode = mode; saved = false"
          />
          <span>
            <span class="block text-sm font-medium text-slate-800">{{ $t('settings.labelMode_' + mode) }}</span>
            <span class="block text-xs text-slate-400">{{ $t('settings.labelMode_' + mode + 'Hint') }}</span>
          </span>
        </label>
      </section>

      <!-- Acortar nombres de campo -->
      <section v-show="tab === 'tables'" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-4">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('settings.fieldTruncate') }}</h2>
          <p class="mt-0.5 text-sm text-slate-500">{{ $t('settings.fieldTruncateDesc') }}</p>
        </div>
        <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 hover:bg-slate-50">
          <input
            type="checkbox"
            class="mt-0.5 h-4 w-4"
            :checked="fieldTruncate.enabled"
            @change="fieldTruncate.enabled = !fieldTruncate.enabled; saved = false"
          />
          <span>
            <span class="block text-sm font-medium text-slate-800">{{ $t('settings.fieldTruncateToggle') }}</span>
            <span class="block text-xs text-slate-400">{{ $t('settings.fieldTruncateHint') }}</span>
          </span>
        </label>
        <label class="flex items-center gap-3 pl-3 text-sm" :class="fieldTruncate.enabled ? '' : 'opacity-50'">
          <span class="text-slate-700">{{ $t('settings.fieldTruncateChars') }}</span>
          <input
            type="number"
            class="w-24 rounded-lg border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
            :min="fieldTruncateMin"
            :max="fieldTruncateMax"
            :disabled="!fieldTruncate.enabled"
            :value="fieldTruncate.chars"
            @input="fieldTruncate.chars = Number($event.target.value); saved = false"
          />
          <span class="text-xs text-slate-400">{{ $t('settings.fieldTruncateRange', { min: fieldTruncateMin, max: fieldTruncateMax }) }}</span>
        </label>
      </section>

      <!-- Recuperación de contraseña -->
      <section v-show="tab === 'security'" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-4">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('settings.passwordReset') }}</h2>
          <p class="mt-0.5 text-sm text-slate-500">{{ $t('settings.passwordResetDesc') }}</p>
        </div>
        <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 hover:bg-slate-50">
          <input
            type="checkbox"
            class="mt-0.5 h-4 w-4"
            :checked="passwordResetEnabled"
            @change="passwordResetEnabled = !passwordResetEnabled; saved = false"
          />
          <span>
            <span class="block text-sm font-medium text-slate-800">{{ $t('settings.passwordResetToggle') }}</span>
            <span class="block text-xs text-slate-400">{{ $t('settings.passwordResetHint') }}</span>
          </span>
        </label>
        <p
          v-if="passwordResetEnabled && !mailConfigured"
          class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900"
        >
          {{ $t('settings.passwordResetNoMail') }}
        </p>
      </section>

      <!-- Auditoría propia (autoservicio) -->
      <section v-show="tab === 'security'" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-4">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('settings.auditSelfView') }}</h2>
          <p class="mt-0.5 text-sm text-slate-500">{{ $t('settings.auditSelfViewDesc') }}</p>
        </div>
        <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 hover:bg-slate-50">
          <input
            type="checkbox"
            class="mt-0.5 h-4 w-4"
            :checked="auditSelfViewEnabled"
            @change="auditSelfViewEnabled = !auditSelfViewEnabled; saved = false"
          />
          <span>
            <span class="block text-sm font-medium text-slate-800">{{ $t('settings.auditSelfViewToggle') }}</span>
            <span class="block text-xs text-slate-400">{{ $t('settings.auditSelfViewHint') }}</span>
          </span>
        </label>
        <label class="flex items-center gap-3 pl-3 text-sm">
          <span class="text-slate-700">{{ $t('settings.auditRetentionLabel') }}</span>
          <input
            type="number"
            class="w-24 rounded-lg border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30"
            :min="0"
            :max="auditRetentionMax"
            :value="auditRetentionDays"
            @input="auditRetentionDays = Number($event.target.value); saved = false"
          />
          <span class="text-xs text-slate-400">{{ $t('settings.auditRetentionHint') }}</span>
        </label>
      </section>

      <!-- Notificaciones por defecto -->
      <section v-show="tab === 'sync'" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-4">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('settings.notificationsDefault') }}</h2>
          <p class="mt-0.5 text-sm text-slate-500">{{ $t('settings.notificationsDefaultDesc') }}</p>
        </div>
        <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 hover:bg-slate-50">
          <input
            type="checkbox"
            class="mt-0.5 h-4 w-4"
            :checked="notificationsDefaultOn"
            @change="notificationsDefaultOn = !notificationsDefaultOn; saved = false"
          />
          <span>
            <span class="block text-sm font-medium text-slate-800">{{ $t('settings.notificationsDefaultToggle') }}</span>
            <span class="block text-xs text-slate-400">{{ $t('settings.notificationsDefaultHint') }}</span>
          </span>
        </label>
      </section>

      <!-- Contraseña de los enlaces de compartir -->
      <section v-show="tab === 'sharing'" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-4">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('settings.sharePassword') }}</h2>
          <p class="mt-0.5 text-sm text-slate-500">{{ $t('settings.sharePasswordDesc') }}</p>
        </div>
        <label
          v-for="pol in validSharePolicies"
          :key="pol"
          class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 hover:bg-slate-50"
        >
          <input
            type="radio"
            class="mt-0.5 h-4 w-4"
            name="share_password_policy"
            :value="pol"
            :checked="sharePasswordPolicy === pol"
            @change="sharePasswordPolicy = pol; saved = false"
          />
          <span>
            <span class="block text-sm font-medium text-slate-800">{{ $t('settings.sharePolicy_' + pol) }}</span>
            <span class="block text-xs text-slate-400">{{ $t('settings.sharePolicy_' + pol + 'Hint') }}</span>
          </span>
        </label>
      </section>

      <!-- Adjuntos en los enlaces de compartir -->
      <section v-show="tab === 'sharing'" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-4">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('settings.shareAttachments') }}</h2>
          <p class="mt-0.5 text-sm text-slate-500">{{ $t('settings.shareAttachmentsDesc') }}</p>
        </div>
        <label
          v-for="pol in validShareAttachmentsPolicies"
          :key="pol"
          class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 hover:bg-slate-50"
        >
          <input
            type="radio"
            class="mt-0.5 h-4 w-4"
            name="share_attachments_policy"
            :value="pol"
            :checked="shareAttachmentsPolicy === pol"
            @change="shareAttachmentsPolicy = pol; saved = false"
          />
          <span>
            <span class="block text-sm font-medium text-slate-800">{{ $t('settings.shareAttachmentsPolicy_' + pol) }}</span>
            <span class="block text-xs text-slate-400">{{ $t('settings.shareAttachmentsPolicy_' + pol + 'Hint') }}</span>
          </span>
        </label>
      </section>

      <!-- Acciones de los viewers sobre formularios -->
      <section v-show="tab === 'security'" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-4">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('settings.viewerActions') }}</h2>
          <p class="mt-0.5 text-sm text-slate-500">{{ $t('settings.viewerActionsDesc') }}</p>
        </div>
        <label
          v-for="key in VIEWER_ACTION_KEYS"
          :key="key"
          class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 hover:bg-slate-50"
        >
          <input
            type="checkbox"
            class="mt-0.5 h-4 w-4"
            :checked="viewerActions[key]"
            @change="viewerActions[key] = !viewerActions[key]; saved = false"
          />
          <span>
            <span class="block text-sm font-medium text-slate-800">{{ $t('settings.viewerAction_' + key) }}</span>
            <span class="block text-xs text-slate-400">{{ $t('settings.viewerAction_' + key + 'Hint') }}</span>
          </span>
        </label>
      </section>

      <!-- La pestaña Base de datos no tiene ajustes que guardar: su acción es el botón de la semilla -->
      <div v-show="tab !== 'database'" class="flex items-center gap-3">
        <button
          :disabled="demoMode || saving"
          class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-60"
          :title="demoMode ? $t('common.demoDisabled') : undefined"
          @click="save"
        >
          {{ saving ? $t('common.saving') : $t('common.save') }}
        </button>
        <span v-if="saved" class="text-sm text-success-600 dark:text-success-400">{{ $t('common.saved') }}</span>
      </div>

      <!-- Copia de seguridad (pestaña Base de datos) -->
      <section v-show="tab === 'database'" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-4">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('settings.dbBackup') }}</h2>
          <p class="mt-0.5 text-sm text-slate-500">{{ $t('settings.dbBackupDesc') }}</p>
        </div>
        <label
          v-for="sc in BACKUP_SCOPES"
          :key="sc"
          class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 hover:bg-slate-50"
        >
          <input
            type="radio"
            class="mt-0.5 h-4 w-4"
            name="backup_scope"
            :value="sc"
            :checked="backupScope === sc"
            @change="backupScope = sc"
          />
          <span>
            <span class="block text-sm font-medium text-slate-800">{{ $t('settings.dbScope_' + sc) }}</span>
            <span class="block text-xs text-slate-400">{{ $t('settings.dbScope_' + sc + 'Hint') }}</span>
          </span>
        </label>
        <div>
          <a
            v-if="!demoMode"
            :href="backupHref"
            class="inline-block rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700"
          >
            {{ $t('settings.dbDownload') }}
          </a>
          <button
            v-else
            type="button"
            disabled
            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white opacity-60"
            :title="$t('common.demoDisabled')"
          >
            {{ $t('settings.dbDownload') }}
          </button>
        </div>
      </section>

      <!-- Restaurar (pestaña Base de datos) -->
      <section v-show="tab === 'database'" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-4">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('settings.dbRestore') }}</h2>
          <p class="mt-0.5 text-sm text-slate-500">{{ $t('settings.dbRestoreDesc') }}</p>
        </div>
        <input
          type="file"
          accept=".sql"
          class="block w-full max-w-md text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200"
          :disabled="demoMode || importBusy"
          @change="onImportFileChange"
        />
        <div v-if="importError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900">
          {{ importError }}
        </div>
        <!-- Confirmación en dos pasos: el aviso aparece antes del botón definitivo -->
        <div
          v-if="importArmed"
          class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900"
        >
          {{ $t('settings.dbRestoreWarn') }}
        </div>
        <div class="flex items-center gap-3">
          <button
            v-if="!importArmed"
            type="button"
            :disabled="demoMode || !importFile || importBusy"
            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-60"
            :title="demoMode ? $t('common.demoDisabled') : undefined"
            @click="importArmed = true"
          >
            {{ $t('settings.dbRestoreArm') }}
          </button>
          <template v-else>
            <button
              type="button"
              :disabled="importBusy"
              class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-60"
              @click="runImport"
            >
              {{ importBusy ? $t('settings.dbRestoring') : $t('settings.dbRestoreConfirm') }}
            </button>
            <button
              type="button"
              :disabled="importBusy"
              class="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100"
              @click="importArmed = false"
            >
              {{ $t('common.cancel') }}
            </button>
          </template>
          <span v-if="importDone" class="text-sm text-success-600 dark:text-success-400">
            {{ $t('settings.dbRestoreDone', { scope: $t('settings.dbScope_' + importDone.scope), rows: importDone.rows }) }}
          </span>
        </div>
      </section>

      <!-- Semilla de la demo (pestaña Base de datos; solo existe si DEMO_SEED_PATH está configurada) -->
      <section v-if="demoSeed.configured" v-show="tab === 'database'" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-4">
        <div>
          <h2 class="font-semibold text-slate-900">{{ $t('settings.demoSeed') }}</h2>
          <p class="mt-0.5 text-sm text-slate-500">{{ $t('settings.demoSeedDesc') }}</p>
        </div>
        <p class="text-sm text-slate-600">
          <template v-if="demoSeed.exists">
            {{ $t('settings.demoSeedCurrent', { date: fmtSeedDate(demoSeed.generated_at), size: fmtSeedSize(demoSeed.bytes) }) }}
          </template>
          <template v-else>{{ $t('settings.demoSeedNone') }}</template>
          <span class="mt-1 block break-all font-mono text-xs text-slate-400">{{ $t('settings.demoSeedPath', { path: demoSeed.path }) }}</span>
        </p>
        <div v-if="seedError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900">
          {{ seedError }}
        </div>
        <div class="flex items-center gap-3">
          <button
            :disabled="demoMode || seedBusy"
            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-60"
            :title="demoMode ? $t('common.demoDisabled') : undefined"
            @click="generateSeed"
          >
            {{ seedBusy ? $t('settings.demoSeedGenerating') : $t('settings.demoSeedGenerate') }}
          </button>
          <span v-if="seedDone" class="text-sm text-success-600 dark:text-success-400">
            {{ $t('settings.demoSeedDone', { rows: seedDone.rows, tables: seedDone.tables, size: fmtSeedSize(seedDone.bytes) }) }}
          </span>
        </div>
      </section>
    </template>
  </div>
</template>
