<script setup>
import { computed } from 'vue'
import Modal from './Modal.vue'
import { useDemoMode } from '../composables/appConfig'

/**
 * Modal informativo del modo demo (qué está limitado, ciclo de reset y
 * credenciales de acceso). Lo abren la portada (en cada carga) y el badge
 * «DEMO». Teleport a <body>: algún badge vive dentro de islas oscuras con
 * `.km-pin-neutrals` (sidebar, drawer) y el modal debe quedar fuera de ese
 * subárbol para que el modo oscuro le aplique con normalidad.
 */
const emit = defineEmits(['close'])
const { demoResetMinutes, demoLoginHint } = useDemoMode()

// DEMO_LOGIN_HINT admite VARIOS accesos separados por «|» (p. ej. un admin y
// un viewer con permisos limitados): uno → frase «Entra con …»; varios →
// lista con su intro. Cada segmento es texto libre del operador de la demo.
const hintLines = computed(() =>
  String(demoLoginHint.value || '').split('|').map((s) => s.trim()).filter(Boolean),
)
</script>

<template>
  <Teleport to="body">
    <Modal :title="$t('common.demoModalTitle')" @close="emit('close')">
      <div class="space-y-4">
        <p class="text-sm text-slate-600">
          {{ $t('common.demoModalBody', { minutes: demoResetMinutes }) }}
        </p>
        <div
          v-if="hintLines.length"
          class="rounded-lg bg-primary-50 px-3 py-2 text-sm font-medium text-primary-800 ring-1 ring-primary-200 dark:bg-primary-900/30 dark:text-primary-300 dark:ring-primary-800"
        >
          <p v-if="hintLines.length === 1">{{ $t('common.demoModalLogin', { hint: hintLines[0] }) }}</p>
          <template v-else>
            <p>{{ $t('common.demoModalLoginIntro') }}</p>
            <ul class="mt-1 list-inside list-disc space-y-0.5">
              <li v-for="(line, i) in hintLines" :key="i">{{ line }}</li>
            </ul>
          </template>
        </div>
        <button
          class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700"
          @click="emit('close')"
        >
          {{ $t('common.demoModalOk') }}
        </button>
      </div>
    </Modal>
  </Teleport>
</template>
