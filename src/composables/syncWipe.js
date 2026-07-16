// Confirmación del VACIADO tras un sync manual.
//
// Si Kobo devuelve 0 envíos vivos con la caché poblada, el backend no borra nada
// (guardia anti-vaciado de SubmissionSync) y responde `wipe_available: true` +
// `cached: n`. Este helper pregunta al usuario y, si confirma, repite el sync con
// `confirm_wipe: true` (el backend re-verifica contra Kobo en esa pasada). Devuelve
// el resultado final: el del segundo sync si hubo confirmación, el original si no.
import { confirmDialog } from './confirm'
import { i18n } from '../i18n'

export async function resolveWipe(res, name, retryWithWipe) {
  if (!res.wipe_available) return res
  const t = i18n.global.t
  const ok = await confirmDialog({
    title: t('forms.wipeTitle'),
    message: t('forms.wipeMessage', { name, n: res.cached }),
    confirmText: t('forms.wipeConfirm'),
    danger: true,
  })
  if (!ok) return res
  return await retryWithWipe()
}
