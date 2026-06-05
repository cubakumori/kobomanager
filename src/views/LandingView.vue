<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { i18n, setLocale } from '../i18n'
import Modal from '../components/Modal.vue'
import LoginForm from '../components/LoginForm.vue'
import banner from '../assets/kobomanager.png'
import logo from '../assets/km_logo.png'

const router = useRouter()
const auth = useAuthStore()

const showLogin = ref(false)

function onLoginSuccess() {
  showLogin.value = false
  router.push('/dashboard')
}

function toggleLocale() {
  setLocale(i18n.global.locale.value === 'es' ? 'en' : 'es')
}
</script>

<template>
  <div class="relative flex min-h-screen flex-col overflow-hidden bg-white text-slate-800">
    <!-- Fondos decorativos -->
    <div class="pointer-events-none absolute inset-0 -z-10">
      <div class="absolute -right-32 -top-32 h-96 w-96 rounded-full bg-blue-400/20 blur-3xl"></div>
      <div class="absolute -bottom-40 -left-32 h-96 w-96 rounded-full bg-emerald-400/20 blur-3xl"></div>
    </div>

    <!-- Barra superior -->
    <header class="sticky top-0 z-30 border-b border-slate-200/60 bg-white/70 backdrop-blur">
      <div class="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-6 py-3">
        <div class="flex items-center gap-2">
          <img :src="logo" alt="" class="h-9 w-9" />
          <span class="text-lg font-semibold tracking-tight text-slate-900">KoboManager</span>
        </div>

        <nav class="flex items-center gap-1 sm:gap-2">
          <a
            href="https://www.kobotoolbox.org"
            target="_blank"
            rel="noopener"
            class="whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900"
          >
            {{ $t('landing.navKobo') }}
          </a>
          <span
            class="hidden cursor-not-allowed whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium text-slate-400 md:inline"
            :title="$t('landing.soon')"
          >{{ $t('landing.navTutorials') }}</span>
          <span
            class="hidden cursor-not-allowed whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium text-slate-400 md:inline"
            :title="$t('landing.soon')"
          >{{ $t('landing.navDonate') }}</span>

          <button
            class="rounded-lg px-2 py-2 text-sm font-semibold text-slate-500 hover:text-slate-900"
            title="ES / EN"
            @click="toggleLocale"
          >
            {{ $i18n.locale === 'es' ? 'EN' : 'ES' }}
          </button>

          <button
            v-if="auth.isAuthenticated"
            class="whitespace-nowrap rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700"
            @click="router.push('/dashboard')"
          >
            {{ $t('landing.goDashboard') }}
          </button>
          <button
            v-else
            class="whitespace-nowrap rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700"
            @click="showLogin = true"
          >
            {{ $t('landing.cta') }}
          </button>
        </nav>
      </div>
    </header>

    <!-- Hero -->
    <main class="mx-auto flex w-full max-w-6xl flex-1 flex-col items-center gap-10 px-6 py-12 lg:flex-row lg:py-20">
      <div class="flex-1 text-center lg:text-left">
        <span
          class="inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200"
        >
          <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
          {{ $t('landing.eyebrow') }}
        </span>

        <h1 class="mt-5 text-4xl font-bold leading-[1.1] tracking-tight text-slate-900 sm:text-5xl">
          {{ $t('landing.tagline') }}
        </h1>
        <p class="mx-auto mt-5 max-w-xl text-lg text-slate-600 lg:mx-0">
          {{ $t('landing.subtitle') }}
        </p>

        <div class="mt-8 flex flex-wrap items-center justify-center gap-3 lg:justify-start">
          <button
            v-if="!auth.isAuthenticated"
            class="rounded-xl bg-blue-600 px-7 py-3 text-sm font-semibold text-white shadow-md shadow-blue-600/20 transition hover:bg-blue-700"
            @click="showLogin = true"
          >
            {{ $t('landing.cta') }}
          </button>
          <button
            v-else
            class="rounded-xl bg-blue-600 px-7 py-3 text-sm font-semibold text-white shadow-md shadow-blue-600/20 transition hover:bg-blue-700"
            @click="router.push('/dashboard')"
          >
            {{ $t('landing.goDashboard') }}
          </button>
          <a
            href="https://www.kobotoolbox.org"
            target="_blank"
            rel="noopener"
            class="rounded-xl border border-slate-300 bg-white px-7 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
          >
            {{ $t('landing.navKobo') }}
          </a>
        </div>
      </div>

      <div class="relative flex-1">
        <div class="absolute inset-0 -z-10 mx-auto h-72 w-72 self-center rounded-full bg-gradient-to-tr from-blue-400/30 to-emerald-400/30 blur-3xl"></div>
        <img :src="banner" alt="KoboManager" class="mx-auto w-full max-w-md drop-shadow-2xl" />
      </div>
    </main>

    <!-- Features -->
    <section class="mx-auto grid w-full max-w-6xl gap-4 px-6 pb-6 sm:grid-cols-3">
      <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 transition hover:shadow-md">
        <div class="mb-3 flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-6 w-6">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.5a3 3 0 00-6 0M12 11a3 3 0 100-6 3 3 0 000 6zM5.5 21a2.5 2.5 0 015 0M3 13.5a2 2 0 113-1.7M21 21a2.5 2.5 0 00-5 0M21 13.5a2 2 0 10-3-1.7" />
          </svg>
        </div>
        <h3 class="font-semibold text-slate-900">{{ $t('landing.feat1Title') }}</h3>
        <p class="mt-1 text-sm text-slate-600">{{ $t('landing.feat1Desc') }}</p>
      </div>
      <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 transition hover:shadow-md">
        <div class="mb-3 flex h-11 w-11 items-center justify-center rounded-xl bg-blue-100 text-blue-700">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-6 w-6">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75l2 2 4-4.5M12 3l2.5 1.5L17.5 4 18 7l2.5 2L19 12l1.5 3L18 16l-.5 3-3-.5L12 21l-2.5-2.5L6.5 19 6 16l-2.5-1L5 12 3.5 9 6 8l.5-3 3 .5L12 3z" />
          </svg>
        </div>
        <h3 class="font-semibold text-slate-900">{{ $t('landing.feat2Title') }}</h3>
        <p class="mt-1 text-sm text-slate-600">{{ $t('landing.feat2Desc') }}</p>
      </div>
      <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 transition hover:shadow-md">
        <div class="mb-3 flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-6 w-6">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 11v3m0-3a1.5 1.5 0 10-1.5-1.5" />
          </svg>
        </div>
        <h3 class="font-semibold text-slate-900">{{ $t('landing.feat3Title') }}</h3>
        <p class="mt-1 text-sm text-slate-600">{{ $t('landing.feat3Desc') }}</p>
      </div>
    </section>

    <!-- Cómo funciona -->
    <section class="mx-auto w-full max-w-6xl px-6 py-12">
      <h2 class="text-center text-2xl font-bold tracking-tight text-slate-900">{{ $t('landing.how') }}</h2>
      <div class="mt-8 grid gap-6 sm:grid-cols-3">
        <div v-for="n in 3" :key="n" class="relative rounded-2xl bg-slate-50 p-6 ring-1 ring-slate-200">
          <div class="mb-3 flex h-9 w-9 items-center justify-center rounded-full bg-blue-600 text-sm font-bold text-white">
            {{ n }}
          </div>
          <h3 class="font-semibold text-slate-900">{{ $t('landing.step' + n + 'Title') }}</h3>
          <p class="mt-1 text-sm text-slate-600">{{ $t('landing.step' + n + 'Desc') }}</p>
        </div>
      </div>
    </section>

    <!-- Footer -->
    <footer class="mt-auto border-t border-slate-200/70 px-6 py-6 text-center text-xs text-slate-400">
      {{ $t('landing.footer') }}
    </footer>

    <!-- Modal de login -->
    <Modal v-if="showLogin" :title="$t('landing.loginTitle')" @close="showLogin = false">
      <LoginForm @success="onLoginSuccess" />
    </Modal>
  </div>
</template>
