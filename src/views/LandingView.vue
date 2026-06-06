<script setup>
import PublicLayout from '../components/PublicLayout.vue'
import banner from '../assets/kobomanager.png'

// Tarjetas de características (estilo "pill" verde). La 4ª destaca el control de
// acceso granular (permisos por filas), añadido recientemente.
const features = [1, 2, 3, 4]
</script>

<template>
  <PublicLayout v-slot="{ openLogin, authenticated, goDashboard }">
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
        <img :src="banner" alt="KoboManager" class="mx-auto w-full max-w-md drop-shadow-2xl" />
      </div>
    </main>

    <!-- Features (estilo "pill" verde, sin iconos) -->
    <section class="mx-auto grid w-full max-w-6xl gap-4 px-6 pb-6 sm:grid-cols-2 lg:grid-cols-4">
      <div
        v-for="n in features"
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
  </PublicLayout>
</template>
