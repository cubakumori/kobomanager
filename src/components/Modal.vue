<script setup>
defineProps({ title: { type: String, default: '' } })
defineEmits(['close'])
</script>

<template>
  <div
    class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4"
    @click.self="$emit('close')"
  >
    <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
      <div class="mb-4 flex items-center justify-between">
        <h3 class="text-lg font-semibold text-slate-900">{{ title }}</h3>
        <button class="text-xl leading-none text-slate-400 hover:text-slate-600" @click="$emit('close')">
          &times;
        </button>
      </div>
      <slot />
    </div>
  </div>
</template>
