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
  <div class="flex min-h-screen flex-col bg-gradient-to-b from-white via-sky-50 to-emerald-50 text-slate-800">
    <!-- Barra superior -->
    <header class="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-6 py-4">
      <div class="flex items-center gap-2">
        <img :src="logo" alt="" class="h-9 w-9" />
        <span class="text-lg font-semibold tracking-tight text-slate-900">KoboManager</span>
      </div>

      <nav class="flex items-center gap-1 sm:gap-2">
        <a
          href="https://www.kobotoolbox.org"
          target="_blank"
          rel="noopener"
          class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-white/70 hover:text-slate-900"
        >
          {{ $t('landing.navKobo') }}
        </a>
        <span
          class="hidden cursor-not-allowed rounded-lg px-3 py-2 text-sm font-medium text-slate-400 sm:inline"
          :title="$t('landing.soon')"
        >{{ $t('landing.navTutorials') }}</span>
        <span
          class="hidden cursor-not-allowed rounded-lg px-3 py-2 text-sm font-medium text-slate-400 sm:inline"
          :title="$t('landing.soon')"
        >{{ $t('landing.navDonate') }}</span>

        <button
          class="rounded-lg px-2 py-2 text-sm font-medium text-slate-500 hover:text-slate-900"
          :title="'ES / EN'"
          @click="toggleLocale"
        >
          {{ $i18n.locale === 'es' ? 'EN' : 'ES' }}
        </button>

        <button
          v-if="auth.isAuthenticated"
          class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"
          @click="router.push('/dashboard')"
        >
          {{ $t('landing.goDashboard') }}
        </button>
        <button
          v-else
          class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"
          @click="showLogin = true"
        >
          {{ $t('landing.cta') }}
        </button>
      </nav>
    </header>

    <!-- Hero -->
    <main class="mx-auto flex w-full max-w-6xl flex-1 flex-col items-center gap-10 px-6 py-10 lg:flex-row lg:py-16">
      <div class="flex-1 text-center lg:text-left">
        <h1 class="text-3xl font-bold leading-tight tracking-tight text-slate-900 sm:text-4xl lg:text-5xl">
          {{ $t('landing.tagline') }}
        </h1>
        <p class="mx-auto mt-5 max-w-xl text-base text-slate-600 lg:mx-0">
          {{ $t('landing.subtitle') }}
        </p>
        <div class="mt-8 flex flex-wrap items-center justify-center gap-3 lg:justify-start">
          <button
            v-if="!auth.isAuthenticated"
            class="rounded-xl bg-blue-600 px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700"
            @click="showLogin = true"
          >
            {{ $t('landing.cta') }}
          </button>
          <button
            v-else
            class="rounded-xl bg-blue-600 px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700"
            @click="router.push('/dashboard')"
          >
            {{ $t('landing.goDashboard') }}
          </button>
          <a
            href="https://www.kobotoolbox.org"
            target="_blank"
            rel="noopener"
            class="rounded-xl border border-slate-300 bg-white px-6 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
          >
            {{ $t('landing.navKobo') }}
          </a>
        </div>
      </div>

      <div class="flex-1">
        <img :src="banner" alt="KoboManager" class="mx-auto w-full max-w-md drop-shadow-xl" />
      </div>
    </main>

    <!-- Features -->
    <section class="mx-auto grid w-full max-w-6xl gap-4 px-6 pb-12 sm:grid-cols-3">
      <div class="rounded-2xl bg-white/80 p-5 shadow-sm ring-1 ring-slate-200 backdrop-blur">
        <h3 class="font-semibold text-slate-900">{{ $t('landing.feat1Title') }}</h3>
        <p class="mt-1 text-sm text-slate-600">{{ $t('landing.feat1Desc') }}</p>
      </div>
      <div class="rounded-2xl bg-white/80 p-5 shadow-sm ring-1 ring-slate-200 backdrop-blur">
        <h3 class="font-semibold text-slate-900">{{ $t('landing.feat2Title') }}</h3>
        <p class="mt-1 text-sm text-slate-600">{{ $t('landing.feat2Desc') }}</p>
      </div>
      <div class="rounded-2xl bg-white/80 p-5 shadow-sm ring-1 ring-slate-200 backdrop-blur">
        <h3 class="font-semibold text-slate-900">{{ $t('landing.feat3Title') }}</h3>
        <p class="mt-1 text-sm text-slate-600">{{ $t('landing.feat3Desc') }}</p>
      </div>
    </section>

    <!-- Footer -->
    <footer class="border-t border-slate-200/70 px-6 py-5 text-center text-xs text-slate-400">
      {{ $t('landing.footer') }}
    </footer>

    <!-- Modal de login -->
    <Modal v-if="showLogin" :title="$t('landing.loginTitle')" @close="showLogin = false">
      <LoginForm @success="onLoginSuccess" />
    </Modal>
  </div>
</template>
