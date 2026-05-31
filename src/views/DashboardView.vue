<script setup>
import { useAuthStore } from '../stores/auth'
import { RouterLink } from 'vue-router'

const auth = useAuthStore()
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold tracking-tight text-slate-900">
        {{ $t('dashboard.hello', { name: auth.user?.name }) }}
      </h1>
      <p class="mt-1 text-sm text-slate-500">
        {{ $t('dashboard.loggedAs', { email: auth.user?.email, role: auth.user?.role }) }}
      </p>
    </header>

    <section class="grid gap-4 sm:grid-cols-2">
      <template v-if="auth.isAdmin">
        <RouterLink
          :to="{ name: 'admin-users' }"
          class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 transition hover:ring-blue-300"
        >
          <h2 class="font-semibold text-slate-900">{{ $t('dashboard.users') }}</h2>
          <p class="mt-1 text-sm text-slate-500">{{ $t('dashboard.usersDesc') }}</p>
        </RouterLink>
        <RouterLink
          :to="{ name: 'admin-accounts' }"
          class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 transition hover:ring-blue-300"
        >
          <h2 class="font-semibold text-slate-900">{{ $t('dashboard.accounts') }}</h2>
          <p class="mt-1 text-sm text-slate-500">{{ $t('dashboard.accountsDesc') }}</p>
        </RouterLink>
        <RouterLink
          :to="{ name: 'admin-settings' }"
          class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 transition hover:ring-blue-300"
        >
          <h2 class="font-semibold text-slate-900">{{ $t('dashboard.settings') }}</h2>
          <p class="mt-1 text-sm text-slate-500">{{ $t('dashboard.settingsDesc') }}</p>
        </RouterLink>
      </template>

      <RouterLink
        v-else
        :to="{ name: 'forms' }"
        class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 transition hover:ring-blue-300"
      >
        <h2 class="font-semibold text-slate-900">{{ $t('dashboard.myForms') }}</h2>
        <p class="mt-1 text-sm text-slate-500">{{ $t('dashboard.myFormsDesc') }}</p>
      </RouterLink>

      <RouterLink
        :to="{ name: 'about-kobo' }"
        class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 transition hover:ring-blue-300"
      >
        <h2 class="font-semibold text-slate-900">{{ $t('dashboard.about') }}</h2>
        <p class="mt-1 text-sm text-slate-500">{{ $t('dashboard.aboutDesc') }}</p>
      </RouterLink>
    </section>
  </div>
</template>
