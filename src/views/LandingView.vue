<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { i18n, setLocale } from '../i18n'
import Modal from '../components/Modal.vue'
import LoginForm from '../components/LoginForm.vue'
import { useDialogA11y } from '../composables/dialogA11y'
import banner from '../assets/kobomanager.png'

const router = useRouter()
const auth = useAuthStore()

const showLogin = ref(false)
const showMenu = ref(false) // drawer móvil

// Accesibilidad del drawer móvil (Escape, focus trap y foco).
const drawer = ref(null)
useDialogA11y(drawer, () => { showMenu.value = false }, showMenu)

function onLoginSuccess() {
  showLogin.value = false
  router.push('/dashboard')
}

function openLogin() {
  showMenu.value = false
  showLogin.value = true
}

function toggleLocale() {
  setLocale(i18n.global.locale.value === 'es' ? 'en' : 'es')
}

const drawerLink =
  'block rounded-lg px-3 py-2 text-sm font-medium text-slate-300 hover:bg-slate-700/60 hover:text-white'
</script>

<template>
  <div class="relative flex min-h-screen flex-col overflow-hidden bg-white text-slate-800">
    <!-- Fondos decorativos -->
    <div class="pointer-events-none absolute inset-0 -z-10">
      <div class="absolute -right-32 -top-32 h-96 w-96 rounded-full bg-primary-400/20 blur-3xl"></div>
      <div class="absolute -bottom-40 -left-32 h-96 w-96 rounded-full bg-accent-400/20 blur-3xl"></div>
    </div>

    <!-- Barra superior -->
    <header class="sticky top-0 z-30 border-b border-slate-200/60 bg-white/70 backdrop-blur">
      <div class="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-6 py-3">
        <span class="text-lg font-semibold tracking-tight text-slate-900">KoboManager</span>

        <!-- Nav escritorio -->
        <nav class="hidden items-center gap-2 md:flex">
          <a
            href="https://www.kobotoolbox.org"
            target="_blank"
            rel="noopener"
            class="whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900"
          >
            {{ $t('landing.navKobo') }}
          </a>
          <RouterLink
            :to="{ name: 'guide' }"
            class="whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900"
          >{{ $t('landing.navTutorials') }}</RouterLink>
          <span
            class="cursor-not-allowed whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium text-slate-400"
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
            class="whitespace-nowrap rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-700"
            @click="router.push('/dashboard')"
          >
            {{ $t('landing.goDashboard') }}
          </button>
          <button
            v-else
            class="whitespace-nowrap rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-700"
            @click="showLogin = true"
          >
            {{ $t('landing.cta') }}
          </button>
        </nav>

        <!-- Botón hamburguesa (móvil) — neutro; el azul se reserva para las acciones -->
        <button
          class="km-hamburger md:hidden"
          aria-label="Menu"
          @click="showMenu = true"
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5">
            <path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16" />
          </svg>
        </button>
      </div>
    </header>

    <!-- Hero -->
    <main class="mx-auto flex w-full max-w-6xl flex-1 flex-col items-center gap-10 px-6 py-12 lg:flex-row lg:py-20">
      <div class="flex-1 text-center lg:text-left">
        <span
          class="inline-flex items-center gap-2 rounded-full bg-accent-50 px-3 py-1 text-xs font-semibold text-accent-700 ring-1 ring-accent-200"
        >
          <span class="h-1.5 w-1.5 rounded-full bg-accent-500"></span>
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
            class="rounded-xl bg-primary-600 px-7 py-3 text-sm font-semibold text-white shadow-md shadow-primary-600/20 transition hover:bg-primary-700"
            @click="showLogin = true"
          >
            {{ $t('landing.cta') }}
          </button>
          <button
            v-else
            class="rounded-xl bg-primary-600 px-7 py-3 text-sm font-semibold text-white shadow-md shadow-primary-600/20 transition hover:bg-primary-700"
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
        <div class="absolute inset-0 -z-10 mx-auto h-72 w-72 self-center rounded-full bg-gradient-to-tr from-primary-400/30 to-accent-400/30 blur-3xl"></div>
        <img :src="banner" alt="KoboManager" class="mx-auto w-full max-w-md drop-shadow-2xl" />
      </div>
    </main>

    <!-- Features (estilo "pill" verde, sin iconos) -->
    <section class="mx-auto grid w-full max-w-6xl gap-4 px-6 pb-6 sm:grid-cols-3">
      <div
        v-for="n in 3"
        :key="n"
        class="rounded-2xl bg-accent-50 p-6 ring-1 ring-accent-200"
      >
        <h3 class="flex items-center gap-2 font-semibold text-accent-800">
          <span class="h-1.5 w-1.5 rounded-full bg-accent-500"></span>
          {{ $t('landing.feat' + n + 'Title') }}
        </h3>
        <p class="mt-2 text-sm text-accent-900/70">{{ $t('landing.feat' + n + 'Desc') }}</p>
      </div>
    </section>

    <!-- Cómo funciona -->
    <section class="mx-auto w-full max-w-6xl px-6 py-12">
      <h2 class="text-center text-2xl font-bold tracking-tight text-slate-900">{{ $t('landing.how') }}</h2>
      <div class="mt-8 grid gap-6 sm:grid-cols-3">
        <div v-for="n in 3" :key="n" class="relative rounded-2xl bg-slate-50 p-6 ring-1 ring-slate-200">
          <div class="mb-3 flex h-9 w-9 items-center justify-center rounded-full bg-primary-600 text-sm font-bold text-white">
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

    <!-- Drawer móvil (desde la derecha), estilo coherente con el sidebar del backend -->
    <Transition
      enter-active-class="transition-opacity duration-200"
      enter-from-class="opacity-0" leave-active-class="transition-opacity duration-200" leave-to-class="opacity-0"
    >
      <div v-if="showMenu" class="fixed inset-0 z-40 bg-black/40 md:hidden" @click="showMenu = false"></div>
    </Transition>
    <Transition
      enter-active-class="transition-transform duration-200" enter-from-class="translate-x-full"
      leave-active-class="transition-transform duration-200" leave-to-class="translate-x-full"
    >
      <aside
        v-if="showMenu"
        ref="drawer"
        class="fixed right-0 top-0 z-50 flex h-screen w-64 flex-col bg-slate-900 p-3 text-white md:hidden"
        role="dialog"
        aria-modal="true"
      >
        <div class="flex items-center justify-between px-2 py-2">
          <span class="text-lg font-semibold tracking-tight">KoboManager</span>
          <button class="rounded-lg p-1 text-slate-300 hover:bg-slate-700/60 hover:text-white" aria-label="Cerrar" @click="showMenu = false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5">
              <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
            </svg>
          </button>
        </div>

        <nav class="mt-2 flex-1 space-y-1">
          <a href="https://www.kobotoolbox.org" target="_blank" rel="noopener" :class="drawerLink" @click="showMenu = false">
            {{ $t('landing.navKobo') }}
          </a>
          <RouterLink :to="{ name: 'guide' }" :class="drawerLink" @click="showMenu = false">
            {{ $t('landing.navTutorials') }}
          </RouterLink>
          <span :class="[drawerLink, 'cursor-not-allowed text-slate-500 hover:bg-transparent hover:text-slate-500']">
            {{ $t('landing.navDonate') }}
          </span>
          <button :class="[drawerLink, 'w-full text-left']" @click="toggleLocale">
            {{ $i18n.locale === 'es' ? 'English' : 'Español' }}
          </button>
        </nav>

        <button
          v-if="auth.isAuthenticated"
          class="rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-primary-700"
          @click="router.push('/dashboard')"
        >
          {{ $t('landing.goDashboard') }}
        </button>
        <button
          v-else
          class="rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-primary-700"
          @click="openLogin"
        >
          {{ $t('landing.cta') }}
        </button>
      </aside>
    </Transition>

    <!-- Modal de login -->
    <Modal v-if="showLogin" :title="$t('landing.loginTitle')" @close="showLogin = false">
      <LoginForm @success="onLoginSuccess" />
    </Modal>
  </div>
</template>
