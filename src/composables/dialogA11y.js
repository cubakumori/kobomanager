import { onMounted, onBeforeUnmount, watch, nextTick } from 'vue'

// Selectores de elementos enfocables dentro de un diálogo/drawer.
const FOCUSABLE = [
  'a[href]',
  'button:not([disabled])',
  'textarea:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(',')

// Bloqueo del scroll del documento mientras haya diálogos abiertos. Contador a
// nivel de módulo porque pueden solaparse (p. ej. ConfirmDialog sobre otro modal):
// al pasar de 0→1 se guarda el overflow previo y se pone 'hidden'; al volver a 0
// se restaura. activate/deactivate ya van emparejados (flag `active`).
let openDialogs = 0
let prevOverflow = ''

// Pila de diálogos abiertos (orden de apertura): con diálogos APILADOS, solo el
// del TOPE debe gestionar Escape y el ciclo de Tab. Sin la pila, cada diálogo
// registraba su propio keydown en document y con dos abiertos Escape los cerraba
// todos (stopPropagation no frena a otros listeners del mismo nodo) y el trap del
// de abajo robaba el foco al de arriba.
const dialogStack = []

function lockScroll() {
  if (openDialogs++ === 0) {
    prevOverflow = document.documentElement.style.overflow
    document.documentElement.style.overflow = 'hidden'
  }
}

function unlockScroll() {
  if (openDialogs > 0 && --openDialogs === 0) {
    document.documentElement.style.overflow = prevOverflow
    prevOverflow = ''
  }
}

/**
 * Accesibilidad para diálogos y drawers:
 *   - Cerrar con la tecla Escape.
 *   - Bloquear el scroll del documento mientras el diálogo esté abierto.
 *   - Focus trap: Tab/Shift+Tab circulan dentro del contenedor.
 *   - Al abrir, el foco entra en el contenedor (o su primer elemento enfocable);
 *     al cerrar, se devuelve al elemento que tenía el foco antes (p. ej. el botón
 *     que abrió el diálogo).
 *
 * @param {import('vue').Ref<HTMLElement|null>} containerRef  raíz del diálogo/drawer
 * @param {() => void} onClose   acción de cierre (Escape)
 * @param {import('vue').Ref<boolean>|null} openRef  si se pasa, activa/desactiva
 *        según su valor (drawers que viven siempre en el DOM). Si es null, se
 *        considera abierto durante todo el ciclo de vida del componente (modales
 *        montados con v-if).
 */
export function useDialogA11y(containerRef, onClose, openRef = null) {
  let prevFocused = null
  let active = false
  const handle = {} // identidad de este diálogo en la pila

  const focusable = () => {
    const el = containerRef.value
    if (!el) return []
    return Array.from(el.querySelectorAll(FOCUSABLE)).filter((n) => n.offsetParent !== null)
  }

  const onKeydown = (e) => {
    if (!active) return
    // Solo el diálogo del tope de la pila gestiona el teclado.
    if (dialogStack[dialogStack.length - 1] !== handle) return
    if (e.key === 'Escape') {
      e.preventDefault()
      e.stopPropagation()
      onClose?.()
      return
    }
    if (e.key === 'Tab') {
      const items = focusable()
      const el = containerRef.value
      if (!items.length || !el) return
      const first = items[0]
      const last = items[items.length - 1]
      if (e.shiftKey) {
        if (document.activeElement === first || !el.contains(document.activeElement)) {
          e.preventDefault()
          last.focus()
        }
      } else if (document.activeElement === last || !el.contains(document.activeElement)) {
        e.preventDefault()
        first.focus()
      }
    }
  }

  const activate = async () => {
    if (active) return
    active = true
    dialogStack.push(handle)
    lockScroll()
    prevFocused = document.activeElement
    document.addEventListener('keydown', onKeydown, true)
    await nextTick()
    const el = containerRef.value
    if (!el) return
    el.focus?.()
    if (!el.contains(document.activeElement)) {
      focusable()[0]?.focus?.()
    }
  }

  const deactivate = () => {
    if (!active) return
    active = false
    const i = dialogStack.indexOf(handle)
    if (i !== -1) dialogStack.splice(i, 1)
    unlockScroll()
    document.removeEventListener('keydown', onKeydown, true)
    if (prevFocused && typeof prevFocused.focus === 'function') prevFocused.focus()
    prevFocused = null
  }

  if (openRef) {
    watch(openRef, (v) => (v ? activate() : deactivate()), { immediate: true })
  } else {
    onMounted(activate)
  }
  onBeforeUnmount(deactivate)
}
