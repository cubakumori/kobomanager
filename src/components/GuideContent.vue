<script setup>
// Cuerpo de la «Guía de uso», sin cromo de página: se reutiliza tanto dentro del
// shell autenticado como en la página pública (envuelto por PublicLayout).
//
// Diseño (alineado con la página «Apoyar»): titular centrado, tarjetas GRISES
// (bg-slate-50, flipan solas en oscuro) y tintes solo en secciones clave —
// Flujo y Seguridad en azul, Datos en celeste, Actualizar/Resync y Compartir
// en verde (este último, único con icono). En escritorio, las secciones cortas
// se emparejan a dos columnas.
const flowSteps = [1, 2, 3, 4]
</script>

<template>
  <div class="space-y-6">
    <!-- Encabezado (estilo «Apoyar») -->
    <header class="pb-2 text-center">
      <h1 class="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">{{ $t('guide.title') }}</h1>
      <p class="mx-auto mt-3 max-w-2xl text-slate-600">{{ $t('guide.subtitle') }}</p>
    </header>

    <!-- Roles -->
    <section class="space-y-3 rounded-2xl bg-slate-50 p-6 ring-1 ring-slate-200">
      <h2 class="font-semibold text-slate-900">{{ $t('guide.rolesTitle') }}</h2>
      <p class="text-sm text-slate-600"><strong>{{ $t('guide.roleAdmin') }}:</strong> {{ $t('guide.roleAdminBody') }}</p>
      <p class="text-sm text-slate-600"><strong>{{ $t('guide.roleViewer') }}:</strong> {{ $t('guide.roleViewerBody') }}</p>
    </section>

    <!-- Flujo (azul) -->
    <section class="space-y-4 rounded-2xl bg-primary-50 p-6 ring-1 ring-primary-200 dark:bg-primary-900/25 dark:ring-primary-800">
      <h2 class="font-semibold text-primary-900 dark:text-primary-300">{{ $t('guide.flowTitle') }}</h2>
      <ol class="space-y-3">
        <li v-for="n in flowSteps" :key="n" class="flex gap-3">
          <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary-600 text-xs font-semibold text-white">{{ n }}</span>
          <div>
            <p class="text-sm font-medium text-slate-800">{{ $t('guide.step' + n) }}</p>
            <p class="text-sm text-slate-600">{{ $t('guide.step' + n + 'Body') }}</p>
          </div>
        </li>
      </ol>
    </section>

    <!-- Acciones sobre un formulario -->
    <section class="space-y-2 rounded-2xl bg-slate-50 p-6 ring-1 ring-slate-200">
      <h2 class="font-semibold text-slate-900">{{ $t('guide.formActionsTitle') }}</h2>
      <p class="text-sm text-slate-600">{{ $t('guide.formActionsIntro') }}</p>
      <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600">
        <li><strong>{{ $t('guide.faEnketo') }}:</strong> {{ $t('guide.faEnketoBody') }}</li>
        <li><strong>{{ $t('guide.faUpdate') }}:</strong> {{ $t('guide.faUpdateBody') }}</li>
        <li><strong>{{ $t('guide.faResync') }}:</strong> {{ $t('guide.faResyncBody') }}</li>
        <li><strong>{{ $t('guide.faLogin') }}:</strong> {{ $t('guide.faLoginBody') }}</li>
      </ul>
      <p class="text-sm text-slate-500">{{ $t('guide.formActionsNote') }}</p>
    </section>

    <!-- Actualizar vs Resync (verde) -->
    <section class="space-y-3 rounded-2xl bg-accent-50 p-6 ring-1 ring-accent-200 dark:bg-accent-900/25 dark:ring-accent-800">
      <h2 class="font-semibold text-accent-900 dark:text-accent-300">{{ $t('guide.syncTitle') }}</h2>
      <p class="text-sm text-slate-600">{{ $t('guide.syncIntro') }}</p>
      <div class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-lg bg-white/70 p-4 ring-1 ring-white/60 dark:ring-white/10">
          <h3 class="text-sm font-semibold text-slate-800">{{ $t('guide.syncUpdate') }}</h3>
          <p class="mt-1 text-sm text-slate-600">{{ $t('guide.syncUpdateBody') }}</p>
        </div>
        <div class="rounded-lg bg-white/70 p-4 ring-1 ring-white/60 dark:ring-white/10">
          <h3 class="text-sm font-semibold text-slate-800">{{ $t('guide.syncResync') }}</h3>
          <p class="mt-1 text-sm text-slate-600">{{ $t('guide.syncResyncBody') }}</p>
        </div>
      </div>
    </section>

    <!-- Explorar la tabla de envíos -->
    <section class="space-y-2 rounded-2xl bg-slate-50 p-6 ring-1 ring-slate-200">
      <h2 class="font-semibold text-slate-900">{{ $t('guide.exploreTitle') }}</h2>
      <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600">
        <li><strong>{{ $t('guide.exploreSearch') }}:</strong> {{ $t('guide.exploreSearchBody') }}</li>
        <li><strong>{{ $t('guide.exploreColumns') }}:</strong> {{ $t('guide.exploreColumnsBody') }}</li>
        <li><strong>{{ $t('guide.exploreCalculated') }}:</strong> {{ $t('guide.exploreCalculatedBody') }}</li>
        <li><strong>{{ $t('guide.exploreStats') }}:</strong> {{ $t('guide.exploreStatsBody') }}</li>
      </ul>
    </section>

    <!-- Funciones de datos (celeste) -->
    <section class="space-y-2 rounded-2xl bg-sky-50 p-6 ring-1 ring-sky-200 dark:bg-sky-950/30 dark:ring-sky-900">
      <h2 class="font-semibold text-sky-900 dark:text-sky-300">{{ $t('guide.dataTitle') }}</h2>
      <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600">
        <li><strong>{{ $t('guide.dataLabels') }}:</strong> {{ $t('guide.dataLabelsBody') }}</li>
        <li><strong>{{ $t('guide.dataAttachments') }}:</strong> {{ $t('guide.dataAttachmentsBody') }}</li>
        <li><strong>{{ $t('guide.dataScope') }}:</strong> {{ $t('guide.dataScopeBody') }}</li>
        <li><strong>{{ $t('guide.dataColumns') }}:</strong> {{ $t('guide.dataColumnsBody') }}</li>
        <li><strong>{{ $t('guide.dataMap') }}:</strong> {{ $t('guide.dataMapBody') }}</li>
      </ul>
    </section>

    <!-- Revisión -->
    <section class="space-y-2 rounded-2xl bg-slate-50 p-6 ring-1 ring-slate-200">
      <h2 class="font-semibold text-slate-900">{{ $t('guide.reviewTitle') }}</h2>
      <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600">
        <li><strong>{{ $t('guide.reviewSingle') }}:</strong> {{ $t('guide.reviewSingleBody') }}</li>
        <li><strong>{{ $t('guide.reviewBatch') }}:</strong> {{ $t('guide.reviewBatchBody') }}</li>
        <li><strong>{{ $t('guide.reviewExport') }}:</strong> {{ $t('guide.reviewExportBody') }}</li>
      </ul>
    </section>

    <!-- Compartir (verde; única con icono) -->
    <section class="space-y-2 rounded-2xl bg-accent-50 p-6 ring-1 ring-accent-200 dark:bg-accent-900/25 dark:ring-accent-800">
      <h2 class="flex items-center gap-2.5 font-semibold text-accent-900 dark:text-accent-300">
        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-accent-100 text-base dark:bg-accent-900/40">🔗</span>
        {{ $t('guide.shareTitle') }}
      </h2>
      <p class="text-sm text-slate-700">{{ $t('guide.shareIntro') }}</p>
      <ul class="list-disc space-y-1 pl-5 text-sm text-slate-700">
        <li><strong>{{ $t('guide.shareExposes') }}:</strong> {{ $t('guide.shareExposesBody') }}</li>
        <li><strong>{{ $t('guide.shareAccess') }}:</strong> {{ $t('guide.shareAccessBody') }}</li>
        <li><strong>{{ $t('guide.shareLifecycle') }}:</strong> {{ $t('guide.shareLifecycleBody') }}</li>
      </ul>
    </section>

    <!-- Pareja corta: Notificaciones + PWA -->
    <div class="grid gap-6 lg:grid-cols-2">
      <section class="space-y-2 rounded-2xl bg-slate-50 p-6 ring-1 ring-slate-200">
        <h2 class="font-semibold text-slate-900">{{ $t('guide.notifTitle') }}</h2>
        <p class="text-sm text-slate-600">{{ $t('guide.notifBody') }}</p>
      </section>

      <section class="space-y-2 rounded-2xl bg-slate-50 p-6 ring-1 ring-slate-200">
        <h2 class="font-semibold text-slate-900">{{ $t('guide.offlineTitle') }}</h2>
        <p class="text-sm text-slate-600">{{ $t('guide.offlineBody') }}</p>
      </section>
    </div>

    <!-- Pareja corta: Contraseñas + Auditoría -->
    <div class="grid gap-6 lg:grid-cols-2">
      <section class="space-y-2 rounded-2xl bg-slate-50 p-6 ring-1 ring-slate-200">
        <h2 class="font-semibold text-slate-900">{{ $t('guide.pwTitle') }}</h2>
        <p class="text-sm text-slate-600">{{ $t('guide.pwChangeBody') }}</p>
        <p class="text-sm text-slate-600">{{ $t('guide.pwForgotBody') }}</p>
      </section>

      <section class="space-y-2 rounded-2xl bg-slate-50 p-6 ring-1 ring-slate-200">
        <h2 class="font-semibold text-slate-900">{{ $t('guide.auditTitle') }}</h2>
        <p class="text-sm text-slate-600">{{ $t('guide.auditBody') }}</p>
        <p class="text-sm text-slate-600">{{ $t('guide.auditSelfBody') }}</p>
      </section>
    </div>

    <!-- Seguridad y privacidad (azul) -->
    <section class="space-y-2 rounded-2xl bg-primary-50 p-6 ring-1 ring-primary-200 dark:bg-primary-900/25 dark:ring-primary-800">
      <h2 class="font-semibold text-primary-900 dark:text-primary-300">{{ $t('guide.secTitle') }}</h2>
      <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600">
        <li><strong>{{ $t('guide.secToken') }}:</strong> {{ $t('guide.secTokenBody') }}</li>
        <li><strong>{{ $t('guide.secSessions') }}:</strong> {{ $t('guide.secSessionsBody') }}</li>
        <li><strong>{{ $t('guide.secScope') }}:</strong> {{ $t('guide.secScopeBody') }}</li>
      </ul>
    </section>

    <p class="pb-4 text-center text-xs text-slate-400">{{ $t('guide.footer') }}</p>
  </div>
</template>
