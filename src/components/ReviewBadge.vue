<script setup>
import { computed } from 'vue'

const props = defineProps({
  status: { type: String, default: 'pending' },
})

const cls = {
  pending: 'bg-amber-100 text-amber-700',
  approved: 'bg-green-100 text-green-700',
  rejected: 'bg-red-100 text-red-700',
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
