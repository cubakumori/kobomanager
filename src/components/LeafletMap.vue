<script setup>
import { ref, onMounted, onBeforeUnmount, watch, nextTick } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'

// features: [{ kind: 'point'|'line'|'polygon', points: [[lat,lng],…], label?, uid? }]
const props = defineProps({
  features: { type: Array, default: () => [] },
  height: { type: String, default: '400px' },
})
const emit = defineEmits(['select'])

const el = ref(null)
let map = null
let layer = null

function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]),
  )
}

function draw() {
  if (!map) return
  if (layer) { layer.remove(); layer = null }
  layer = L.layerGroup().addTo(map)

  const bounds = []
  for (const f of props.features) {
    const pts = f.points || []
    if (!pts.length) continue
    pts.forEach((p) => bounds.push(p))

    let shape
    if (f.kind === 'polygon') {
      shape = L.polygon(pts, { color: '#2563eb', weight: 2, fillOpacity: 0.15 })
    } else if (f.kind === 'line') {
      shape = L.polyline(pts, { color: '#2563eb', weight: 3 })
    } else {
      shape = L.circleMarker(pts[0], {
        radius: 7, color: '#1d4ed8', weight: 2, fillColor: '#3b82f6', fillOpacity: 0.9,
      })
    }
    if (f.label) shape.bindPopup(escapeHtml(f.label))
    if (f.uid) shape.on('click', () => emit('select', f.uid))
    shape.addTo(layer)
  }

  if (bounds.length === 1) {
    map.setView(bounds[0], 15)
  } else if (bounds.length > 1) {
    map.fitBounds(bounds, { padding: [30, 30], maxZoom: 16 })
  }
}

onMounted(async () => {
  await nextTick()
  map = L.map(el.value, { scrollWheelZoom: false }).setView([0, 0], 2)
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap',
  }).addTo(map)
  draw()
})

watch(() => props.features, draw, { deep: true })

onBeforeUnmount(() => {
  if (map) { map.remove(); map = null }
})
</script>

<template>
  <!-- relative z-0 crea un contexto de apilamiento propio: los z-index altos internos
       de Leaflet (controles ~1000) quedan confinados y nunca tapan el drawer de la app. -->
  <div ref="el" :style="{ height }" class="relative z-0 w-full overflow-hidden rounded-lg ring-1 ring-slate-200"></div>
</template>
