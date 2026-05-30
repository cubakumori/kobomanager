import { reactive } from 'vue'

// Estado singleton del diálogo de confirmación. <ConfirmDialog> (montado una vez en
// el layout) lo lee; las vistas llaman a confirmDialog() y reciben una promesa.
export const confirmState = reactive({
  open: false,
  title: '',
  message: '',
  confirmText: 'Confirmar',
  cancelText: 'Cancelar',
  danger: false,
  resolve: null,
})

/** Abre el diálogo y resuelve a true/false según la elección del usuario. */
export function confirmDialog(opts = {}) {
  confirmState.title = opts.title ?? '¿Estás seguro?'
  confirmState.message = opts.message ?? ''
  confirmState.confirmText = opts.confirmText ?? 'Confirmar'
  confirmState.cancelText = opts.cancelText ?? 'Cancelar'
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
