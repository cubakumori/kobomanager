<script setup>
import { useAuthStore } from '../stores/auth'
import { RouterLink } from 'vue-router'

const auth = useAuthStore()
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold tracking-tight text-slate-900">
        Hola, {{ auth.user?.name }}
      </h1>
      <p class="mt-1 text-sm text-slate-500">
        Sesión iniciada como <span class="font-medium">{{ auth.user?.email }}</span>
        ({{ auth.user?.role }}).
      </p>
    </header>

    <section v-if="auth.isAdmin" class="grid gap-4 sm:grid-cols-2">
      <RouterLink
        :to="{ name: 'admin-users' }"
        class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 transition hover:ring-blue-300"
      >
        <h2 class="font-semibold text-slate-900">Usuarios</h2>
        <p class="mt-1 text-sm text-slate-500">Gestiona los usuarios de la aplicación.</p>
      </RouterLink>
      <RouterLink
        :to="{ name: 'admin-accounts' }"
        class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 transition hover:ring-blue-300"
      >
        <h2 class="font-semibold text-slate-900">Cuentas Kobo</h2>
        <p class="mt-1 text-sm text-slate-500">Conecta y administra las cuentas de KoboToolbox.</p>
      </RouterLink>
      <RouterLink
        :to="{ name: 'admin-settings' }"
        class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 transition hover:ring-blue-300"
      >
        <h2 class="font-semibold text-slate-900">Configuración</h2>
        <p class="mt-1 text-sm text-slate-500">Ajustes generales, como qué formularios sincronizar.</p>
      </RouterLink>
    </section>

    <section v-else>
      <RouterLink
        :to="{ name: 'forms' }"
        class="block rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 transition hover:ring-blue-300"
      >
        <h2 class="font-semibold text-slate-900">Mis formularios</h2>
        <p class="mt-1 text-sm text-slate-500">Consulta los envíos de tus formularios.</p>
      </RouterLink>
    </section>

    <RouterLink
      :to="{ name: 'about-kobo' }"
      class="block rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 transition hover:ring-blue-300"
    >
      <h2 class="font-semibold text-slate-900">Acerca de Kobo</h2>
      <p class="mt-1 text-sm text-slate-500">
        Qué es KoboToolbox, cómo crear una cuenta y dónde obtener tu API token.
      </p>
    </RouterLink>
  </div>
</template>
