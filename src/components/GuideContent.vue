<script setup>
// Cuerpo de la «Guía de uso», sin cromo de página: se reutiliza tanto dentro del
// shell autenticado como en la página pública (envuelto por PublicLayout).
//
// Diseño: tarjetas TINTADAS estilo «Apoyar» — cada sección rota por una paleta
// suave (accent/primary/ámbar/sky) con cabecera de punto tipo pill; el cuerpo
// queda en slate para no cansar con tanto texto. Tintes con variante dark.
const flowSteps = [1, 2, 3, 4]

const TINTS = [
  { card: 'bg-accent-50 ring-accent-200 dark:bg-accent-900/25 dark:ring-accent-800', head: 'text-accent-800 dark:text-accent-300', dot: 'bg-accent-500' },
  { card: 'bg-primary-50 ring-primary-200 dark:bg-primary-900/25 dark:ring-primary-800', head: 'text-primary-800 dark:text-primary-300', dot: 'bg-primary-500' },
  { card: 'bg-amber-50 ring-amber-200 dark:bg-amber-950/30 dark:ring-amber-900', head: 'text-amber-800 dark:text-amber-300', dot: 'bg-amber-500' },
  { card: 'bg-sky-50 ring-sky-200 dark:bg-sky-950/30 dark:ring-sky-900', head: 'text-sky-800 dark:text-sky-300', dot: 'bg-sky-500' },
]
const tint = (i) => TINTS[i % TINTS.length]
</script>

<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $t('guide.title') }}</h1>
      <p class="mt-1 text-sm text-slate-500">{{ $t('guide.subtitle') }}</p>
    </div>

    <!-- Roles -->
    <section class="space-y-3 rounded-2xl p-6 ring-1" :class="tint(0).card">
      <h2 class="flex items-center gap-2 font-semibold" :class="tint(0).head">
        <span class="h-1.5 w-1.5 rounded-full" :class="tint(0).dot"></span>
        {{ $t('guide.rolesTitle') }}
      </h2>
      <p class="text-sm text-slate-700"><strong>{{ $t('guide.roleAdmin') }}:</strong> {{ $t('guide.roleAdminBody') }}</p>
      <p class="text-sm text-slate-700"><strong>{{ $t('guide.roleViewer') }}:</strong> {{ $t('guide.roleViewerBody') }}</p>
    </section>

    <!-- Flujo -->
    <section class="space-y-4 rounded-2xl p-6 ring-1" :class="tint(1).card">
      <h2 class="flex items-center gap-2 font-semibold" :class="tint(1).head">
        <span class="h-1.5 w-1.5 rounded-full" :class="tint(1).dot"></span>
        {{ $t('guide.flowTitle') }}
      </h2>
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
    <section class="space-y-2 rounded-2xl p-6 ring-1" :class="tint(2).card">
      <h2 class="flex items-center gap-2 font-semibold" :class="tint(2).head">
        <span class="h-1.5 w-1.5 rounded-full" :class="tint(2).dot"></span>
        {{ $t('guide.formActionsTitle') }}
      </h2>
      <p class="text-sm text-slate-700">{{ $t('guide.formActionsIntro') }}</p>
      <ul class="list-disc space-y-1 pl-5 text-sm text-slate-700">
        <li><strong>{{ $t('guide.faEnketo') }}:</strong> {{ $t('guide.faEnketoBody') }}</li>
        <li><strong>{{ $t('guide.faUpdate') }}:</strong> {{ $t('guide.faUpdateBody') }}</li>
        <li><strong>{{ $t('guide.faResync') }}:</strong> {{ $t('guide.faResyncBody') }}</li>
        <li><strong>{{ $t('guide.faLogin') }}:</strong> {{ $t('guide.faLoginBody') }}</li>
      </ul>
      <p class="text-sm text-slate-600">{{ $t('guide.formActionsNote') }}</p>
    </section>

    <!-- Actualizar vs Resync -->
    <section class="space-y-3 rounded-2xl p-6 ring-1" :class="tint(3).card">
      <h2 class="flex items-center gap-2 font-semibold" :class="tint(3).head">
        <span class="h-1.5 w-1.5 rounded-full" :class="tint(3).dot"></span>
        {{ $t('guide.syncTitle') }}
      </h2>
      <p class="text-sm text-slate-700">{{ $t('guide.syncIntro') }}</p>
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
    <section class="space-y-2 rounded-2xl p-6 ring-1" :class="tint(0).card">
      <h2 class="flex items-center gap-2 font-semibold" :class="tint(0).head">
        <span class="h-1.5 w-1.5 rounded-full" :class="tint(0).dot"></span>
        {{ $t('guide.exploreTitle') }}
      </h2>
      <ul class="list-disc space-y-1 pl-5 text-sm text-slate-700">
        <li><strong>{{ $t('guide.exploreSearch') }}:</strong> {{ $t('guide.exploreSearchBody') }}</li>
        <li><strong>{{ $t('guide.exploreColumns') }}:</strong> {{ $t('guide.exploreColumnsBody') }}</li>
        <li><strong>{{ $t('guide.exploreCalculated') }}:</strong> {{ $t('guide.exploreCalculatedBody') }}</li>
        <li><strong>{{ $t('guide.exploreStats') }}:</strong> {{ $t('guide.exploreStatsBody') }}</li>
      </ul>
    </section>

    <!-- Funciones de datos -->
    <section class="space-y-2 rounded-2xl p-6 ring-1" :class="tint(1).card">
      <h2 class="flex items-center gap-2 font-semibold" :class="tint(1).head">
        <span class="h-1.5 w-1.5 rounded-full" :class="tint(1).dot"></span>
        {{ $t('guide.dataTitle') }}
      </h2>
      <ul class="list-disc space-y-1 pl-5 text-sm text-slate-700">
        <li><strong>{{ $t('guide.dataLabels') }}:</strong> {{ $t('guide.dataLabelsBody') }}</li>
        <li><strong>{{ $t('guide.dataAttachments') }}:</strong> {{ $t('guide.dataAttachmentsBody') }}</li>
        <li><strong>{{ $t('guide.dataScope') }}:</strong> {{ $t('guide.dataScopeBody') }}</li>
        <li><strong>{{ $t('guide.dataColumns') }}:</strong> {{ $t('guide.dataColumnsBody') }}</li>
        <li><strong>{{ $t('guide.dataMap') }}:</strong> {{ $t('guide.dataMapBody') }}</li>
      </ul>
    </section>

    <!-- Revisión y productividad -->
    <section class="space-y-2 rounded-2xl p-6 ring-1" :class="tint(2).card">
      <h2 class="flex items-center gap-2 font-semibold" :class="tint(2).head">
        <span class="h-1.5 w-1.5 rounded-full" :class="tint(2).dot"></span>
        {{ $t('guide.reviewTitle') }}
      </h2>
      <ul class="list-disc space-y-1 pl-5 text-sm text-slate-700">
        <li><strong>{{ $t('guide.reviewSingle') }}:</strong> {{ $t('guide.reviewSingleBody') }}</li>
        <li><strong>{{ $t('guide.reviewBatch') }}:</strong> {{ $t('guide.reviewBatchBody') }}</li>
        <li><strong>{{ $t('guide.reviewExport') }}:</strong> {{ $t('guide.reviewExportBody') }}</li>
      </ul>
    </section>

    <!-- Compartir: enlaces de solo lectura -->
    <section class="space-y-2 rounded-2xl p-6 ring-1" :class="tint(3).card">
      <h2 class="flex items-center gap-2 font-semibold" :class="tint(3).head">
        <span class="h-1.5 w-1.5 rounded-full" :class="tint(3).dot"></span>
        {{ $t('guide.shareTitle') }}
      </h2>
      <p class="text-sm text-slate-700">{{ $t('guide.shareIntro') }}</p>
      <ul class="list-disc space-y-1 pl-5 text-sm text-slate-700">
        <li><strong>{{ $t('guide.shareExposes') }}:</strong> {{ $t('guide.shareExposesBody') }}</li>
        <li><strong>{{ $t('guide.shareAccess') }}:</strong> {{ $t('guide.shareAccessBody') }}</li>
        <li><strong>{{ $t('guide.shareLifecycle') }}:</strong> {{ $t('guide.shareLifecycleBody') }}</li>
      </ul>
    </section>

    <!-- Notificaciones -->
    <section class="space-y-2 rounded-2xl p-6 ring-1" :class="tint(0).card">
      <h2 class="flex items-center gap-2 font-semibold" :class="tint(0).head">
        <span class="h-1.5 w-1.5 rounded-full" :class="tint(0).dot"></span>
        {{ $t('guide.notifTitle') }}
      </h2>
      <p class="text-sm text-slate-700">{{ $t('guide.notifBody') }}</p>
    </section>

    <!-- PWA / sin conexión -->
    <section class="space-y-2 rounded-2xl p-6 ring-1" :class="tint(1).card">
      <h2 class="flex items-center gap-2 font-semibold" :class="tint(1).head">
        <span class="h-1.5 w-1.5 rounded-full" :class="tint(1).dot"></span>
        {{ $t('guide.offlineTitle') }}
      </h2>
      <p class="text-sm text-slate-700">{{ $t('guide.offlineBody') }}</p>
    </section>

    <!-- Contraseñas -->
    <section class="space-y-2 rounded-2xl p-6 ring-1" :class="tint(2).card">
      <h2 class="flex items-center gap-2 font-semibold" :class="tint(2).head">
        <span class="h-1.5 w-1.5 rounded-full" :class="tint(2).dot"></span>
        {{ $t('guide.pwTitle') }}
      </h2>
      <p class="text-sm text-slate-700">{{ $t('guide.pwChangeBody') }}</p>
      <p class="text-sm text-slate-700">{{ $t('guide.pwForgotBody') }}</p>
    </section>

    <!-- Auditoría y estado del sistema (admin) -->
    <section class="space-y-2 rounded-2xl p-6 ring-1" :class="tint(3).card">
      <h2 class="flex items-center gap-2 font-semibold" :class="tint(3).head">
        <span class="h-1.5 w-1.5 rounded-full" :class="tint(3).dot"></span>
        {{ $t('guide.auditTitle') }}
      </h2>
      <p class="text-sm text-slate-700">{{ $t('guide.auditBody') }}</p>
      <p class="text-sm text-slate-700">{{ $t('guide.auditSelfBody') }}</p>
    </section>

    <!-- Seguridad y privacidad -->
    <section class="space-y-2 rounded-2xl p-6 ring-1" :class="tint(0).card">
      <h2 class="flex items-center gap-2 font-semibold" :class="tint(0).head">
        <span class="h-1.5 w-1.5 rounded-full" :class="tint(0).dot"></span>
        {{ $t('guide.secTitle') }}
      </h2>
      <ul class="list-disc space-y-1 pl-5 text-sm text-slate-700">
        <li><strong>{{ $t('guide.secToken') }}:</strong> {{ $t('guide.secTokenBody') }}</li>
        <li><strong>{{ $t('guide.secSessions') }}:</strong> {{ $t('guide.secSessionsBody') }}</li>
        <li><strong>{{ $t('guide.secScope') }}:</strong> {{ $t('guide.secScopeBody') }}</li>
      </ul>
    </section>

    <p class="pb-4 text-center text-xs text-slate-400">{{ $t('guide.footer') }}</p>
  </div>
</template>
