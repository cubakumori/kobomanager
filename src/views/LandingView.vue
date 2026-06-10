<script setup>
import { ref, watch } from 'vue'
import PublicLayout from '../components/PublicLayout.vue'
import Modal from '../components/Modal.vue'
import { useDarkMode } from '../composables/darkMode'
import { useDemoMode } from '../composables/appConfig'
import bannerDay from '../assets/kobomanager.webp'
import bannerNight from '../assets/kobomanager_night.webp'

// Banner del hero: variante diurna o nocturna según el tema activo.
const { isDark } = useDarkMode()

// Modal de bienvenida de la demo: solo en la portada y una vez por visita
// (sessionStorage). demoMode llega async de /config → watch con immediate.
const { demoMode, demoResetMinutes, demoLoginHint } = useDemoMode()
const DEMO_SEEN_KEY = 'km.demo.modalSeen'
const showDemoModal = ref(false)
watch(demoMode, (v) => {
  if (v && !sessionStorage.getItem(DEMO_SEEN_KEY)) showDemoModal.value = true
}, { immediate: true })
function dismissDemoModal() {
  sessionStorage.setItem(DEMO_SEEN_KEY, '1')
  showDemoModal.value = false
}

// Tarjetas de características (estilo "pill" verde). La 4ª destaca el control de
// acceso granular (permisos por filas), añadido recientemente.
const features = [1, 2, 3, 4]

// Sección «Y mucho más»: enlaces públicos como tarjeta destacada (feat5) y el
// resto de capacidades vendibles como chips, mismo lenguaje visual verde.
const chips = ['chipColumns', 'chipStats', 'chipEmail', 'chipLabels', 'chipMap', 'chipCsv', 'chipEdit']
</script>

<template>
  <PublicLayout v-slot="{ openLogin, authenticated, goDashboard }">
    <!-- Aviso de demo (cerrarlo lo marca como visto para esta visita) -->
    <Modal v-if="showDemoModal" :title="$t('common.demoModalTitle')" @close="dismissDemoModal">
      <div class="space-y-4">
        <p class="text-sm text-slate-600">
          {{ $t('common.demoModalBody', { minutes: demoResetMinutes }) }}
        </p>
        <p
          v-if="demoLoginHint"
          class="rounded-lg bg-primary-50 px-3 py-2 text-sm font-medium text-primary-800 ring-1 ring-primary-200 dark:bg-primary-900/30 dark:text-primary-300 dark:ring-primary-800"
        >
          {{ $t('common.demoModalLogin', { hint: demoLoginHint }) }}
        </p>
        <button
          class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700"
          @click="dismissDemoModal"
        >
          {{ $t('common.demoModalOk') }}
        </button>
      </div>
    </Modal>

    <!-- Hero -->
    <main class="mx-auto flex w-full max-w-6xl flex-1 flex-col items-center gap-10 px-6 py-12 lg:flex-row lg:py-20">
      <div class="flex-1 text-center lg:text-left">
        <span
          class="inline-flex items-center gap-2 rounded-full bg-accent-50 px-3 py-1 text-xs font-semibold text-accent-700 ring-1 ring-accent-200 dark:bg-accent-900/25 dark:text-accent-300 dark:ring-accent-800"
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
            v-if="!authenticated"
            class="rounded-xl bg-primary-600 px-7 py-3 text-sm font-semibold text-white shadow-md shadow-primary-600/20 transition hover:bg-primary-700"
            @click="openLogin"
          >
            {{ $t('landing.cta') }}
          </button>
          <button
            v-else
            class="rounded-xl bg-primary-600 px-7 py-3 text-sm font-semibold text-white shadow-md shadow-primary-600/20 transition hover:bg-primary-700"
            @click="goDashboard"
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
        <img :src="isDark ? bannerNight : bannerDay" alt="KoboManager" class="mx-auto w-full max-w-md drop-shadow-2xl" />
      </div>
    </main>

    <!-- Features (estilo "pill" verde, sin iconos) -->
    <section class="mx-auto grid w-full max-w-6xl gap-4 px-6 pb-6 sm:grid-cols-2 lg:grid-cols-4">
      <div
        v-for="n in features"
        :key="n"
        class="rounded-2xl bg-accent-50 p-6 ring-1 ring-accent-200 dark:bg-accent-900/25 dark:ring-accent-800"
      >
        <h3 class="flex items-center gap-2 font-semibold text-accent-800 dark:text-accent-300">
          <span class="h-1.5 w-1.5 rounded-full bg-accent-500"></span>
          {{ $t('landing.feat' + n + 'Title') }}
        </h3>
        <p class="mt-2 text-sm text-accent-900/70 dark:text-accent-200/70">{{ $t('landing.feat' + n + 'Desc') }}</p>
      </div>
    </section>

    <!-- Y mucho más: enlace público destacado + chips de capacidades -->
    <section class="mx-auto w-full max-w-6xl px-6 py-12">
      <h2 class="text-center text-2xl font-bold tracking-tight text-slate-900">{{ $t('landing.moreTitle') }}</h2>
      <p class="mx-auto mt-2 max-w-2xl text-center text-sm text-slate-600">{{ $t('landing.moreSubtitle') }}</p>

      <!-- Tarjeta destacada: enlaces públicos de solo lectura -->
      <div class="mx-auto mt-8 max-w-3xl rounded-2xl bg-accent-50 p-6 ring-1 ring-accent-200 dark:bg-accent-900/25 dark:ring-accent-800">
        <h3 class="flex items-center gap-2 font-semibold text-accent-800 dark:text-accent-300">
          <span class="h-1.5 w-1.5 rounded-full bg-accent-500"></span>
          {{ $t('landing.feat5Title') }}
        </h3>
        <p class="mt-2 text-sm text-accent-900/70 dark:text-accent-200/70">{{ $t('landing.feat5Desc') }}</p>
      </div>

      <!-- Resto de capacidades como chips -->
      <ul class="mx-auto mt-5 flex max-w-3xl flex-wrap justify-center gap-2">
        <li
          v-for="c in chips"
          :key="c"
          class="inline-flex items-center gap-2 rounded-full bg-accent-50 px-4 py-2 text-sm font-medium text-accent-800 ring-1 ring-accent-200 dark:bg-accent-900/25 dark:text-accent-300 dark:ring-accent-800"
        >
          <span class="h-1.5 w-1.5 rounded-full bg-accent-500"></span>
          {{ $t('landing.' + c) }}
        </li>
      </ul>
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

    <!-- Disclaimer de no afiliación (solo en la portada) -->
    <div class="mx-auto w-full max-w-6xl px-6 pb-10">
      <p class="mx-auto max-w-3xl rounded-xl bg-sky-50 px-4 py-3 text-center text-xs text-sky-800 ring-1 ring-sky-200 dark:bg-sky-950/40 dark:text-sky-300 dark:ring-sky-900">
        {{ $t('landing.disclaimer') }}
      </p>
    </div>
  </PublicLayout>
</template>
