<script setup>
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
</script>

<template>
  <Teleport to="body">
    <Modal :title="$t('common.demoModalTitle')" @close="emit('close')">
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
          @click="emit('close')"
        >
          {{ $t('common.demoModalOk') }}
        </button>
      </div>
    </Modal>
  </Teleport>
</template>
