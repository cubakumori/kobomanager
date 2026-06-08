<script setup>
import { computed } from 'vue'
import { useReviewStatusesStore } from '../stores/reviewStatuses'
import { badgeClass } from '../composables/reviewColors'

const props = defineProps({
  status: { type: String, default: 'pending' },
})

const store = useReviewStatusesStore()

const info = computed(() => ({
  cls: badgeClass(store.color(props.status)),
  label: store.label(props.status),
}))
</script>

<template>
  <span class="inline-block rounded-full px-2 py-0.5 text-xs font-medium" :class="info.cls">
    {{ info.label }}
  </span>
</template>
