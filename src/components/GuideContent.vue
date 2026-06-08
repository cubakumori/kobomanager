<script setup>
// Cuerpo de la «Guía de uso», sin cromo de página: se reutiliza tanto dentro del
// shell autenticado como en la página pública (envuelto por PublicLayout).
const flowSteps = [1, 2, 3, 4]
</script>

<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $t('guide.title') }}</h1>
      <p class="mt-1 text-sm text-slate-500">{{ $t('guide.subtitle') }}</p>
    </div>

    <!-- Roles -->
    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-3">
      <h2 class="font-semibold text-slate-900">{{ $t('guide.rolesTitle') }}</h2>
      <p class="text-sm text-slate-600"><strong>{{ $t('guide.roleAdmin') }}:</strong> {{ $t('guide.roleAdminBody') }}</p>
      <p class="text-sm text-slate-600"><strong>{{ $t('guide.roleViewer') }}:</strong> {{ $t('guide.roleViewerBody') }}</p>
    </section>

    <!-- Flujo -->
    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-4">
      <h2 class="font-semibold text-slate-900">{{ $t('guide.flowTitle') }}</h2>
      <ol class="space-y-3">
        <li v-for="n in flowSteps" :key="n" class="flex gap-3">
          <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary-600 text-xs font-semibold text-white">{{ n }}</span>
          <div>
            <p class="text-sm font-medium text-slate-800">{{ $t('guide.step' + n) }}</p>
            <p class="text-sm text-slate-500">{{ $t('guide.step' + n + 'Body') }}</p>
          </div>
        </li>
      </ol>
    </section>

    <!-- Acciones sobre un formulario -->
    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-2">
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

    <!-- Actualizar vs Resync -->
    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-3">
      <h2 class="font-semibold text-slate-900">{{ $t('guide.syncTitle') }}</h2>
      <p class="text-sm text-slate-600">{{ $t('guide.syncIntro') }}</p>
      <div class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-lg border border-slate-200 p-4">
          <h3 class="text-sm font-semibold text-slate-800">{{ $t('guide.syncUpdate') }}</h3>
          <p class="mt-1 text-sm text-slate-500">{{ $t('guide.syncUpdateBody') }}</p>
        </div>
        <div class="rounded-lg border border-slate-200 p-4">
          <h3 class="text-sm font-semibold text-slate-800">{{ $t('guide.syncResync') }}</h3>
          <p class="mt-1 text-sm text-slate-500">{{ $t('guide.syncResyncBody') }}</p>
        </div>
      </div>
    </section>

    <!-- Explorar la tabla de envíos -->
    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-2">
      <h2 class="font-semibold text-slate-900">{{ $t('guide.exploreTitle') }}</h2>
      <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600">
        <li><strong>{{ $t('guide.exploreSearch') }}:</strong> {{ $t('guide.exploreSearchBody') }}</li>
        <li><strong>{{ $t('guide.exploreColumns') }}:</strong> {{ $t('guide.exploreColumnsBody') }}</li>
        <li><strong>{{ $t('guide.exploreCalculated') }}:</strong> {{ $t('guide.exploreCalculatedBody') }}</li>
        <li><strong>{{ $t('guide.exploreStats') }}:</strong> {{ $t('guide.exploreStatsBody') }}</li>
      </ul>
    </section>

    <!-- Funciones de datos -->
    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-2">
      <h2 class="font-semibold text-slate-900">{{ $t('guide.dataTitle') }}</h2>
      <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600">
        <li><strong>{{ $t('guide.dataLabels') }}:</strong> {{ $t('guide.dataLabelsBody') }}</li>
        <li><strong>{{ $t('guide.dataAttachments') }}:</strong> {{ $t('guide.dataAttachmentsBody') }}</li>
        <li><strong>{{ $t('guide.dataScope') }}:</strong> {{ $t('guide.dataScopeBody') }}</li>
        <li><strong>{{ $t('guide.dataColumns') }}:</strong> {{ $t('guide.dataColumnsBody') }}</li>
        <li><strong>{{ $t('guide.dataMap') }}:</strong> {{ $t('guide.dataMapBody') }}</li>
      </ul>
    </section>

    <!-- Revisión y productividad -->
    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-2">
      <h2 class="font-semibold text-slate-900">{{ $t('guide.reviewTitle') }}</h2>
      <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600">
        <li><strong>{{ $t('guide.reviewSingle') }}:</strong> {{ $t('guide.reviewSingleBody') }}</li>
        <li><strong>{{ $t('guide.reviewBatch') }}:</strong> {{ $t('guide.reviewBatchBody') }}</li>
        <li><strong>{{ $t('guide.reviewExport') }}:</strong> {{ $t('guide.reviewExportBody') }}</li>
      </ul>
    </section>

    <!-- Compartir: enlaces de solo lectura -->
    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-2">
      <h2 class="font-semibold text-slate-900">{{ $t('guide.shareTitle') }}</h2>
      <p class="text-sm text-slate-600">{{ $t('guide.shareIntro') }}</p>
      <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600">
        <li><strong>{{ $t('guide.shareExposes') }}:</strong> {{ $t('guide.shareExposesBody') }}</li>
        <li><strong>{{ $t('guide.shareAccess') }}:</strong> {{ $t('guide.shareAccessBody') }}</li>
        <li><strong>{{ $t('guide.shareLifecycle') }}:</strong> {{ $t('guide.shareLifecycleBody') }}</li>
      </ul>
    </section>

    <!-- Notificaciones -->
    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-2">
      <h2 class="font-semibold text-slate-900">{{ $t('guide.notifTitle') }}</h2>
      <p class="text-sm text-slate-600">{{ $t('guide.notifBody') }}</p>
    </section>

    <!-- Contraseñas -->
    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-2">
      <h2 class="font-semibold text-slate-900">{{ $t('guide.pwTitle') }}</h2>
      <p class="text-sm text-slate-600">{{ $t('guide.pwChangeBody') }}</p>
      <p class="text-sm text-slate-600">{{ $t('guide.pwForgotBody') }}</p>
    </section>

    <!-- Auditoría y estado del sistema (admin) -->
    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-2">
      <h2 class="font-semibold text-slate-900">{{ $t('guide.auditTitle') }}</h2>
      <p class="text-sm text-slate-600">{{ $t('guide.auditBody') }}</p>
      <p class="text-sm text-slate-600">{{ $t('guide.auditSelfBody') }}</p>
    </section>

    <!-- Seguridad y privacidad -->
    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 space-y-2">
      <h2 class="font-semibold text-slate-900">{{ $t('guide.secTitle') }}</h2>
      <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600">
        <li><strong>{{ $t('guide.secToken') }}:</strong> {{ $t('guide.secTokenBody') }}</li>
        <li><strong>{{ $t('guide.secSessions') }}:</strong> {{ $t('guide.secSessionsBody') }}</li>
        <li><strong>{{ $t('guide.secScope') }}:</strong> {{ $t('guide.secScopeBody') }}</li>
      </ul>
    </section>

    <p class="pb-4 text-center text-xs text-slate-400">{{ $t('guide.footer') }}</p>
  </div>
</template>
