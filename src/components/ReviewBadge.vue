<script setup>
import { computed } from 'vue'

const props = defineProps({
  status: { type: String, default: 'pending' },
})

const cls = {
  pending: 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
  approved: 'bg-success-100 text-success-700 dark:bg-success-900/40 dark:text-success-300',
  on_hold: 'bg-sky-100 text-sky-700 dark:bg-sky-950/50 dark:text-sky-300',
  rejected: 'bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300',
}

const info = computed(() => ({
  cls: cls[props.status] ?? cls.pending,
  key: `review.${props.status in cls ? props.status : 'pending'}`,
}))
</script>

<template>
  <span class="inline-block rounded-full px-2 py-0.5 text-xs font-medium" :class="info.cls">
    {{ $t(info.key) }}
  </span>
</template>
