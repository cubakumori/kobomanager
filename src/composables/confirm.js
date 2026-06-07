import { reactive } from 'vue'
import i18n from '../i18n'

// Estado singleton del diálogo de confirmación. <ConfirmDialog> (montado una vez en
// el layout) lo lee; las vistas llaman a confirmDialog() y reciben una promesa.
export const confirmState = reactive({
  open: false,
  title: '',
  message: '',
  confirmText: '',
  cancelText: '',
  danger: false,
  resolve: null,
})

/** Abre el diálogo y resuelve a true/false según la elección del usuario. */
export function confirmDialog(opts = {}) {
  // Los textos por defecto se traducen en el momento (según el idioma activo).
  const t = i18n.global.t
  confirmState.title = opts.title ?? t('common.areYouSure')
  confirmState.message = opts.message ?? ''
  confirmState.confirmText = opts.confirmText ?? t('common.confirm')
  confirmState.cancelText = opts.cancelText ?? t('common.cancel')
  confirmState.danger = opts.danger ?? false
  confirmState.open = true
  return new Promise((resolve) => {
    confirmState.resolve = resolve
  })
}

export function answerConfirm(value) {
  confirmState.open = false
  const r = confirmState.resolve
  confirmState.resolve = null
  if (r) r(value)
}
