// Formato de los valores «calculados» (derivados) de un envío.
//
// El backend (lib/Derived.php) devuelve, junto a cada envío, un objeto `derived`
// con métricas que Kobo no entrega directamente (duración, completitud, adjuntos
// por tipo, geo, retraso de subida, hora/día, validación Kobo…). Aquí se les da
// formato legible y localizado, compartido entre el detalle (lista completa) y la
// tabla (columnas opcionales). Las métricas sin dato se muestran como «—».

import { useI18n } from 'vue-i18n'

// Columnas derivadas ofrecidas en el selector de la tabla. El prefijo «@» evita
// colisiones con las claves de campos de datos (nombres XLSForm válidos).
export const DERIVED_TABLE_COLS = ['@duration', '@has_attachments', '@has_geo']

export function isDerivedCol(c) {
  return typeof c === 'string' && c.startsWith('@')
}

// Duración en segundos → «45 s» / «30 min» / «1 h 5 min» (unidades neutras es/en).
export function fmtDuration(s) {
  if (s == null) return '—'
  if (s < 60) return `${s} s`
  const m = Math.floor(s / 60)
  if (m < 60) {
    const r = s % 60
    return r ? `${m} min ${r} s` : `${m} min`
  }
  const h = Math.floor(m / 60)
  const rm = m % 60
  return rm ? `${h} h ${rm} min` : `${h} h`
}

export function useDerivedFormat() {
  const { t } = useI18n()
  const yesNo = (b) => (b ? t('common.yes') : t('common.no'))

  function validationLabel(uid) {
    if (!uid) return '—'
    const key = `derived.validationStatus.${uid}`
    const txt = t(key)
    return txt === key ? uid : txt // uid desconocido → crudo
  }

  function attachmentsText(d) {
    if (!d || !d.attachments_total) return yesNo(false)
    const by = d.attachments_by_kind || {}
    const parts = []
    for (const k of ['image', 'audio', 'video', 'file']) {
      if (by[k]) parts.push(`${by[k]} ${t('derived.kind.' + k)}`)
    }
    return parts.length ? `${d.attachments_total} (${parts.join(' · ')})` : String(d.attachments_total)
  }

  function submittedText(d) {
    if (!d || d.submitted_hour == null) return '—'
    const hh = String(d.submitted_hour).padStart(2, '0')
    const day = d.submitted_dow == null ? '' : t('derived.dow.' + d.submitted_dow) + ', '
    return `${day}${hh}:00`
  }

  // Etiqueta de una columna derivada de la tabla (id con prefijo «@»).
  function tableLabel(col) {
    return t('derived.col.' + col.slice(1))
  }

  // Valor mostrable de una columna derivada en la tabla.
  function tableValue(col, d) {
    if (!d) return '—'
    switch (col) {
      case '@duration':
        return fmtDuration(d.duration_s)
      case '@has_attachments':
        return yesNo(d.has_attachments)
      case '@has_geo':
        return yesNo(d.has_geo)
    }
    return '—'
  }

  // Lista completa para el acápite «Resumen» del detalle: [{ label, value }].
  function summaryRows(d) {
    if (!d) return []
    const pct = d.completeness == null ? '—' : `${Math.round(d.completeness * 100)} % (${d.answered}/${d.questions})`
    const speed = d.speed_s_per_q == null ? '—' : `${fmtDuration(Math.round(d.speed_s_per_q))} · ${t('derived.perQuestion')}`
    return [
      { label: t('derived.duration'), value: fmtDuration(d.duration_s) },
      { label: t('derived.completeness'), value: pct },
      { label: t('derived.speed'), value: speed },
      { label: t('derived.uploadDelay'), value: fmtDuration(d.upload_delay_s) },
      { label: t('derived.submitted'), value: submittedText(d) },
      { label: t('derived.attachments'), value: attachmentsText(d) },
      { label: t('derived.hasGeo'), value: yesNo(d.has_geo) },
      { label: t('derived.validation'), value: validationLabel(d.validation_status) },
      { label: t('derived.submittedBy'), value: d.submitted_by ?? '—' },
      { label: t('derived.version'), value: d.version ?? '—' },
      { label: t('derived.tags'), value: String(d.tags_count ?? 0) },
      { label: t('derived.notes'), value: String(d.notes_count ?? 0) },
    ]
  }

  return { tableLabel, tableValue, summaryRows, isDerivedCol }
}
