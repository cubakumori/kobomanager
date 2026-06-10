<script setup>
import { ref } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { i18n, setLocale } from '../i18n'
import Modal from './Modal.vue'
import LoginForm from './LoginForm.vue'
import ThemeToggle from './ThemeToggle.vue'
import { useDialogA11y } from '../composables/dialogA11y'

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

    <!-- Barra superior (idéntica en portada y páginas públicas) -->
    <header class="sticky top-0 z-30 border-b border-slate-200/60 bg-white/70 backdrop-blur">
      <div class="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-6 py-3">
        <RouterLink to="/" class="text-lg font-semibold tracking-tight text-slate-900 hover:text-primary-700">
          KoboManager
        </RouterLink>

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
          <RouterLink
            :to="{ name: 'support' }"
            class="whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900"
          >{{ $t('landing.navSupport') }}</RouterLink>
          <button
            class="rounded-lg px-2 py-2 text-sm font-semibold text-slate-500 hover:text-slate-900"
            title="ES / EN"
            @click="toggleLocale"
          >
            {{ $i18n.locale === 'es' ? 'EN' : 'ES' }}
          </button>
          <ThemeToggle />
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
        <div class="flex items-center gap-2 md:hidden">
        <ThemeToggle />
        <button
          class="km-hamburger"
          aria-label="Menu"
          @click="showMenu = true"
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5">
            <path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16" />
          </svg>
        </button>
        </div>
      </div>
    </header>

    <!-- Contenido de la página -->
    <slot :open-login="openLogin" :authenticated="auth.isAuthenticated" :go-dashboard="() => router.push('/dashboard')" />

    <!-- Footer -->
    <footer class="mt-auto space-y-1.5 border-t border-slate-200/70 px-6 py-6 text-center text-xs text-slate-400">
      <p>{{ $t('landing.footer') }}</p>
      <!-- Disclaimer de no afiliación (mitiga el riesgo de marca de llevar «Kobo» en el nombre) -->
      <p class="mx-auto max-w-2xl">{{ $t('landing.disclaimer') }}</p>
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
      <!-- km-pin-neutrals: el drawer es oscuro por diseño también en modo claro -->
      <aside
        v-if="showMenu"
        ref="drawer"
        class="km-pin-neutrals fixed right-0 top-0 z-50 flex h-screen w-64 flex-col bg-slate-900 p-3 text-white md:hidden"
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
          <RouterLink :to="{ name: 'support' }" :class="drawerLink" @click="showMenu = false">
            {{ $t('landing.navSupport') }}
          </RouterLink>
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
