<script setup>
import Modal from './Modal.vue'
import { confirmState, answerConfirm } from '../composables/confirm'
</script>

<template>
  <Modal v-if="confirmState.open" :title="confirmState.title" @close="answerConfirm(false)">
    <p class="text-sm text-slate-600">{{ confirmState.message }}</p>
    <div class="mt-6 flex justify-end gap-3">
      <button
        class="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100"
        @click="answerConfirm(false)"
      >
        {{ confirmState.cancelText }}
      </button>
      <button
        class="rounded-lg px-4 py-2 text-sm font-semibold text-white"
        :class="confirmState.danger ? 'bg-red-600 hover:bg-red-700' : 'bg-primary-600 hover:bg-primary-700'"
        @click="answerConfirm(true)"
      >
        {{ confirmState.confirmText }}
      </button>
    </div>
  </Modal>
</template>
