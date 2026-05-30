<script setup>
// Vista de login — placeholder de la Fase 0.
// La autenticación real (POST /auth/login + cookie HttpOnly) llega en la Fase 1.
import { ref } from 'vue'

const email = ref('')
const password = ref('')

function onSubmit() {
  // Implementación pendiente (Fase 1).
  alert('Autenticación pendiente de implementar (Fase 1).')
}
</script>

<template>
  <div class="login">
    <form class="card" @submit.prevent="onSubmit">
      <h1>KoboManager</h1>
      <p class="subtitle">Inicia sesión</p>

      <label>
        Email
        <input v-model="email" type="email" autocomplete="username" required />
      </label>
      <label>
        Contraseña
        <input v-model="password" type="password" autocomplete="current-password" required />
      </label>

      <button type="submit">Entrar</button>
    </form>
  </div>
</template>

<style scoped>
.login {
  min-height: 100vh;
  display: grid;
  place-items: center;
}
.card {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  width: 320px;
  padding: 2rem;
  border: 1px solid #e0e0e0;
  border-radius: 12px;
}
h1 { margin: 0; }
.subtitle { margin: 0 0 1rem; color: #666; }
label { display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.9rem; }
input { padding: 0.5rem; border: 1px solid #ccc; border-radius: 6px; }
button { padding: 0.6rem; border: 0; border-radius: 6px; background: #2563eb; color: #fff; cursor: pointer; }
</style>
