<script setup>
import { RouterLink } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Acerca de KoboToolbox</h1>
      <p class="mt-1 text-sm text-slate-500">
        Qué es KoboToolbox y cómo se conecta con esta aplicación.
      </p>
    </header>

    <!-- Qué es -->
    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-2">
      <h2 class="font-semibold text-slate-900">¿Qué es KoboToolbox?</h2>
      <p class="text-sm text-slate-600">
        KoboToolbox es una plataforma libre para la recogida de datos mediante formularios
        (encuestas), muy usada en contextos humanitarios y de investigación. KoboManager
        actúa como una capa intermedia: el administrador conecta aquí las cuentas de Kobo y
        el resto de usuarios consultan, editan y validan los envíos <strong>sin necesidad de
        tener cuenta en KoboToolbox</strong>.
      </p>
    </section>

    <!-- Crear cuenta -->
    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-3">
      <h2 class="font-semibold text-slate-900">1. Crear una cuenta en KoboToolbox</h2>
      <p class="text-sm text-slate-600">
        Regístrate desde
        <a href="https://www.kobotoolbox.org" target="_blank" rel="noopener" class="text-blue-600 hover:underline">kobotoolbox.org</a>
        (botón <em>Sign up</em>). El nombre de usuario debe ir en minúsculas, sin espacios ni símbolos.
        Recibirás un email de activación.
      </p>
      <p class="text-sm text-slate-600">Hay dos servidores públicos independientes:</p>
      <div class="overflow-hidden rounded-lg ring-1 ring-slate-200">
        <table class="w-full text-left text-sm">
          <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
            <tr><th class="px-4 py-2">Servidor</th><th class="px-4 py-2">URL</th><th class="px-4 py-2">Notas</th></tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr>
              <td class="px-4 py-2 font-medium">Global</td>
              <td class="px-4 py-2"><code>https://kf.kobotoolbox.org</code></td>
              <td class="px-4 py-2 text-slate-500">Servidor general.</td>
            </tr>
            <tr>
              <td class="px-4 py-2 font-medium">Europa (UE)</td>
              <td class="px-4 py-2"><code>https://eu.kobotoolbox.org</code></td>
              <td class="px-4 py-2 text-slate-500">Datos alojados en la UE (Irlanda).</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-amber-200">
        Los dos servidores son independientes: una cuenta de uno <strong>no</strong> sirve en el otro,
        y no se comparten proyectos entre ellos. Usa la URL del servidor donde está realmente tu proyecto.
      </p>
    </section>

    <!-- Obtener token -->
    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-3">
      <h2 class="font-semibold text-slate-900">2. Obtener tu API token</h2>
      <p class="text-sm text-slate-600">El token es la credencial que esta app usa para hablar con Kobo en tu nombre.</p>
      <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600">
        <li>
          <strong>Desde la interfaz:</strong> icono de tu perfil →
          <em>Account Settings</em> → pestaña <em>Security</em> → botón <em>Display</em> junto a “API Key”.
        </li>
        <li>
          <strong>Por URL</strong> (con la sesión iniciada en Kobo):
          <code>https://&lt;servidor&gt;/token/?format=json</code><br />
          p. ej. <code>https://eu.kobotoolbox.org/token/?format=json</code>
        </li>
      </ul>
      <p class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600 ring-1 ring-slate-200">
        Trata el token como una contraseña. En KoboManager se guarda <strong>cifrado</strong> y
        nunca se muestra de vuelta; si necesitas cambiarlo, edita la cuenta e introduce uno nuevo.
      </p>
    </section>

    <!-- Cómo lo usa esta app -->
    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-3">
      <h2 class="font-semibold text-slate-900">3. Cómo lo usa KoboManager</h2>
      <ol class="list-decimal space-y-1 pl-5 text-sm text-slate-600">
        <li>El administrador añade la cuenta (servidor + email + token) en <em>Cuentas Kobo</em>.</li>
        <li>Se sincronizan los formularios desde <em>Formularios</em>.</li>
        <li>Los envíos se traen a una caché local periódicamente; los usuarios consultan esa caché (rápido y sin saturar la API de Kobo).</li>
        <li>Editar un envío escribe el cambio en Kobo y luego actualiza la caché. La validación (aprobado/rechazado) es interna de esta app.</li>
      </ol>
      <p class="text-xs text-slate-400">
        Nota técnica: KoboManager usa la API v2 de KoboToolbox. La API v1 está en desuso.
      </p>
      <div v-if="auth.isAdmin" class="pt-1">
        <RouterLink
          :to="{ name: 'admin-accounts' }"
          class="inline-block rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"
        >
          Ir a Cuentas Kobo
        </RouterLink>
      </div>
    </section>
  </div>
</template>
