import { defineStore } from 'pinia'
import api from '../services/api'
import i18n from '../i18n'

// Catálogo de estados de revisión (global). Se carga una vez tras el login y lo usan
// los badges, los botones de revisión (detalle + lote), el filtro y las estadísticas.
// La etiqueta de un built-in sin relabelar sale de i18n (`review.<key>`); si está
// relabelado o es un estado personalizado, se usa la etiqueta guardada.
export const useReviewStatusesStore = defineStore('reviewStatuses', {
  state: () => ({
    statuses: [], // [{ key, label, color, is_open }]
    loaded: false,
  }),
  getters: {
    // Estados que se pueden ASIGNAR al revisar (todos menos «pending», que es el
    // estado por defecto «sin revisar»), en el orden del catálogo.
    actionable: (s) => s.statuses.filter((x) => x.key !== 'pending'),
    byKey: (s) => (key) => s.statuses.find((x) => x.key === key) || null,
  },
  actions: {
    async load(force = false) {
      if (this.loaded && !force) return
      try {
        const { data } = await api.get('/review-statuses')
        this.statuses = data.data.statuses || []
        this.loaded = true
      } catch {
        // Silencioso: los componentes caen a los built-in por i18n si no hay catálogo.
      }
    },
    // Etiqueta legible de una clave de estado.
    label(key) {
      const t = i18n.global.t
      const row = this.byKey(key)
      if (row && row.label) return row.label
      if (i18n.global.te(`review.${key}`)) return t(`review.${key}`)
      return row?.label || key
    },
    color(key) {
      return this.byKey(key)?.color ?? (key === 'pending' ? 'amber' : 'slate')
    },
    clear() {
      this.statuses = []
      this.loaded = false
    },
  },
})
