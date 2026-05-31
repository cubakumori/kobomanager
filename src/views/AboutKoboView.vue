<script setup>
import { RouterLink } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $t('about.title') }}</h1>
      <p class="mt-1 text-sm text-slate-500">{{ $t('about.subtitle') }}</p>
    </header>

    <!-- Qué es -->
    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-2">
      <h2 class="font-semibold text-slate-900">{{ $t('about.whatTitle') }}</h2>
      <p class="text-sm text-slate-600">{{ $t('about.whatBody') }}</p>
    </section>

    <!-- Crear cuenta -->
    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-3">
      <h2 class="font-semibold text-slate-900">{{ $t('about.createTitle') }}</h2>
      <i18n-t keypath="about.createBody" tag="p" class="text-sm text-slate-600" scope="global">
        <template #link>
          <a href="https://www.kobotoolbox.org" target="_blank" rel="noopener" class="text-blue-600 hover:underline">kobotoolbox.org</a>
        </template>
      </i18n-t>
      <p class="text-sm text-slate-600">{{ $t('about.serversIntro') }}</p>
      <div class="overflow-hidden rounded-lg ring-1 ring-slate-200">
        <table class="w-full text-left text-sm">
          <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
            <tr>
              <th class="px-4 py-2">{{ $t('about.colServer') }}</th>
              <th class="px-4 py-2">{{ $t('about.colUrl') }}</th>
              <th class="px-4 py-2">{{ $t('about.colNotes') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr>
              <td class="px-4 py-2 font-medium">Global</td>
              <td class="px-4 py-2"><code>https://kf.kobotoolbox.org</code></td>
              <td class="px-4 py-2 text-slate-500">{{ $t('about.globalNotes') }}</td>
            </tr>
            <tr>
              <td class="px-4 py-2 font-medium">Europa (UE)</td>
              <td class="px-4 py-2"><code>https://eu.kobotoolbox.org</code></td>
              <td class="px-4 py-2 text-slate-500">{{ $t('about.euNotes') }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-amber-200">
        {{ $t('about.crossWarning') }}
      </p>
    </section>

    <!-- Obtener token -->
    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-3">
      <h2 class="font-semibold text-slate-900">{{ $t('about.tokenTitle') }}</h2>
      <p class="text-sm text-slate-600">{{ $t('about.tokenIntro') }}</p>
      <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600">
        <li>{{ $t('about.tokenUi') }}</li>
        <li><span class="break-all">{{ $t('about.tokenUrl') }}</span></li>
      </ul>
      <p class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600 ring-1 ring-slate-200">
        {{ $t('about.tokenSecurity') }}
      </p>
    </section>

    <!-- Cómo lo usa esta app -->
    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-3">
      <h2 class="font-semibold text-slate-900">{{ $t('about.usesTitle') }}</h2>
      <ol class="list-decimal space-y-1 pl-5 text-sm text-slate-600">
        <li>{{ $t('about.use1') }}</li>
        <li>{{ $t('about.use2') }}</li>
        <li>{{ $t('about.use3') }}</li>
        <li>{{ $t('about.use4') }}</li>
      </ol>
      <p class="text-xs text-slate-400">{{ $t('about.techNote') }}</p>
      <div v-if="auth.isAdmin" class="pt-1">
        <RouterLink
          :to="{ name: 'admin-accounts' }"
          class="inline-block rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"
        >
          {{ $t('about.goToAccounts') }}
        </RouterLink>
      </div>
    </section>
  </div>
</template>
