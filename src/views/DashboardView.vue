<script setup>
import { ref, computed, onMounted } from 'vue'
import { useAuthStore } from '../stores/auth'
import { RouterLink } from 'vue-router'
import api from '../services/api'

const auth = useAuthStore()

// Contador de mensajes de contacto nuevos (solo admin; best-effort).
const newMessages = ref(0)
// «Primeros pasos»: en una instancia recién instalada (admin sin ninguna cuenta
// Kobo conectada) el Dashboard orienta el arranque. Best-effort: si la señal no
// llega, simplemente no se muestra la tarjeta.
const accountsCount = ref(null) // null = aún sin saber
const showFirstSteps = computed(() => auth.isAdmin && accountsCount.value === 0)
onMounted(async () => {
  if (!auth.isAdmin) return
  try {
    const { data } = await api.get('/admin/messages', { params: { per_page: 1 } })
    newMessages.value = data.data.new_count
  } catch {
    /* la card funciona igual sin contador */
  }
  try {
    const { data } = await api.get('/admin/accounts')
    accountsCount.value = data.data.length
  } catch {
    /* sin tarjeta de primeros pasos */
  }
})
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold tracking-tight text-slate-900">
        {{ $t('dashboard.hello', { name: auth.user?.name }) }}
      </h1>
      <p class="mt-1 text-sm text-slate-500">
        {{ $t('dashboard.loggedAs', { email: auth.user?.email, role: auth.isAdmin ? $t('common.roleAdmin') : $t('common.roleViewer') }) }}
      </p>
    </header>

    <!-- Primeros pasos: solo para el admin de una instancia sin cuentas Kobo. -->
    <section
      v-if="showFirstSteps"
      class="rounded-xl bg-primary-50 p-5 ring-1 ring-primary-200 dark:bg-primary-900/20 dark:ring-primary-800"
    >
      <h2 class="font-semibold text-primary-900 dark:text-primary-200">{{ $t('dashboard.firstStepsTitle') }}</h2>
      <p class="mt-1 text-sm text-primary-800 dark:text-primary-300">{{ $t('dashboard.firstStepsIntro') }}</p>
      <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-primary-900 dark:text-primary-200">
        <li>{{ $t('dashboard.firstStep1') }}</li>
        <li>{{ $t('dashboard.firstStep2') }}</li>
        <li>{{ $t('dashboard.firstStep3') }}</li>
      </ol>
      <div class="mt-4 flex flex-wrap items-center gap-3">
        <RouterLink
          :to="{ name: 'admin-accounts' }"
          class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700"
        >{{ $t('dashboard.firstStepsCta') }}</RouterLink>
        <RouterLink
          :to="{ name: 'about-kobo' }"
          class="text-sm font-medium text-primary-700 hover:underline dark:text-primary-300"
        >{{ $t('dashboard.firstStepsHelp') }}</RouterLink>
      </div>
    </section>

    <section class="grid gap-4 sm:grid-cols-2">
      <template v-if="auth.isAdmin">
        <RouterLink
          :to="{ name: 'admin-users' }"
          class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 transition hover:ring-primary-300"
        >
          <h2 class="font-semibold text-slate-900">{{ $t('dashboard.users') }}</h2>
          <p class="mt-1 text-sm text-slate-500">{{ $t('dashboard.usersDesc') }}</p>
        </RouterLink>
        <RouterLink
          :to="{ name: 'admin-accounts' }"
          class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 transition hover:ring-primary-300"
        >
          <h2 class="font-semibold text-slate-900">{{ $t('dashboard.accounts') }}</h2>
          <p class="mt-1 text-sm text-slate-500">{{ $t('dashboard.accountsDesc') }}</p>
        </RouterLink>
        <RouterLink
          :to="{ name: 'admin-audit' }"
          class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 transition hover:ring-primary-300"
        >
          <h2 class="font-semibold text-slate-900">{{ $t('dashboard.audit') }}</h2>
          <p class="mt-1 text-sm text-slate-500">{{ $t('dashboard.auditDesc') }}</p>
        </RouterLink>
        <RouterLink
          :to="{ name: 'admin-messages' }"
          class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 transition hover:ring-primary-300"
        >
          <h2 class="flex items-center gap-2 font-semibold text-slate-900">
            {{ $t('dashboard.messages') }}
            <span
              v-if="newMessages"
              class="inline-flex rounded-full bg-primary-600 px-2 py-0.5 text-xs font-semibold text-white"
            >{{ newMessages }}</span>
          </h2>
          <p class="mt-1 text-sm text-slate-500">{{ $t('dashboard.messagesDesc') }}</p>
        </RouterLink>
        <RouterLink
          :to="{ name: 'admin-settings' }"
          class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 transition hover:ring-primary-300"
        >
          <h2 class="font-semibold text-slate-900">{{ $t('dashboard.settings') }}</h2>
          <p class="mt-1 text-sm text-slate-500">{{ $t('dashboard.settingsDesc') }}</p>
        </RouterLink>
      </template>

      <RouterLink
        v-else
        :to="{ name: 'forms' }"
        class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 transition hover:ring-primary-300"
      >
        <h2 class="font-semibold text-slate-900">{{ $t('dashboard.myForms') }}</h2>
        <p class="mt-1 text-sm text-slate-500">{{ $t('dashboard.myFormsDesc') }}</p>
      </RouterLink>

      <RouterLink
        :to="{ name: 'about-kobo' }"
        class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 transition hover:ring-primary-300"
      >
        <h2 class="font-semibold text-slate-900">{{ $t('dashboard.about') }}</h2>
        <p class="mt-1 text-sm text-slate-500">{{ $t('dashboard.aboutDesc') }}</p>
      </RouterLink>

      <RouterLink
        :to="{ name: 'guide' }"
        class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 transition hover:ring-primary-300"
      >
        <h2 class="font-semibold text-slate-900">{{ $t('dashboard.guide') }}</h2>
        <p class="mt-1 text-sm text-slate-500">{{ $t('dashboard.guideDesc') }}</p>
      </RouterLink>
    </section>
  </div>
</template>
