<script setup>
import { useRouter, useRoute, RouterLink } from 'vue-router'
import LoginForm from '../components/LoginForm.vue'
import DemoBadge from '../components/DemoBadge.vue'
import logo from '../assets/km_logo.png'

const router = useRouter()
const route = useRoute()

function onSuccess() {
  const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/dashboard'
  router.push(redirect)
}
</script>

<template>
  <div class="grid min-h-dvh place-items-center bg-slate-100 px-4 py-10">
    <div class="w-full max-w-sm">
      <!-- Logo encima del recuadro, centrado -->
      <RouterLink to="/" class="mx-auto mb-5 block w-fit">
        <img :src="logo" alt="KoboManager" class="h-40 w-40" />
      </RouterLink>

      <div class="space-y-5 rounded-2xl bg-white p-8 shadow-sm ring-1 ring-slate-200">
        <div class="text-center">
          <h1 class="inline-flex items-center gap-2 text-xl font-semibold tracking-tight text-slate-900">KoboManager <DemoBadge /></h1>
          <p class="mt-1 text-sm text-slate-500">{{ $t('login.subtitle') }}</p>
        </div>
        <LoginForm @success="onSuccess" />
      </div>
    </div>
  </div>
</template>
