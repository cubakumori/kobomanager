<script setup>
// Dashboard — placeholder de la Fase 0.
// Muestra el resultado del endpoint /health para confirmar conexión front↔back.
import { ref, onMounted } from 'vue'
import api from '../services/api'

const health = ref(null)
const error = ref(null)

onMounted(async () => {
  try {
    const { data } = await api.get('/health')
    health.value = data.data
  } catch (e) {
    error.value = e?.message ?? 'Error desconocido'
  }
})
</script>

<template>
  <main class="dashboard">
    <h1>KoboManager</h1>
    <p class="muted">Scaffolding de la Fase 0 — backend conectado.</p>

    <section class="card">
      <h2>Estado del backend</h2>
      <pre v-if="health">{{ JSON.stringify(health, null, 2) }}</pre>
      <p v-else-if="error" class="error">No se pudo contactar con la API: {{ error }}</p>
      <p v-else>Cargando…</p>
    </section>
  </main>
</template>

<style scoped>
.dashboard { max-width: 720px; margin: 3rem auto; padding: 0 1rem; }
.muted { color: #666; }
.card { border: 1px solid #e0e0e0; border-radius: 12px; padding: 1.5rem; margin-top: 1.5rem; }
pre { background: #f5f5f5; padding: 1rem; border-radius: 8px; overflow: auto; }
.error { color: #dc2626; }
</style>
