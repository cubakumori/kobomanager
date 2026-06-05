<script setup>
import { useRouter, useRoute, RouterLink } from 'vue-router'
import LoginForm from '../components/LoginForm.vue'
import logo from '../assets/km_logo.png'

const router = useRouter()
const route = useRoute()

function onSuccess() {
  const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/dashboard'
  router.push(redirect)
}
</script>

<template>
  <div class="grid min-h-screen place-items-center bg-slate-100 px-4">
    <div class="w-full max-w-sm space-y-5 rounded-2xl bg-white p-8 shadow-sm ring-1 ring-slate-200">
      <RouterLink to="/" class="flex items-center gap-2">
        <img :src="logo" alt="KoboManager" class="h-9 w-9" />
        <span class="text-xl font-semibold tracking-tight text-slate-900">KoboManager</span>
      </RouterLink>
      <p class="text-sm text-slate-500">{{ $t('login.subtitle') }}</p>
      <LoginForm @success="onSuccess" />
    </div>
  </div>
</template>
