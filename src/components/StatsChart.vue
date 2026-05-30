<script setup>
import { computed } from 'vue'
import { Bar, Doughnut } from 'vue-chartjs'
import {
  Chart as ChartJS,
  Title,
  Tooltip,
  Legend,
  BarElement,
  CategoryScale,
  LinearScale,
  ArcElement,
} from 'chart.js'

ChartJS.register(Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale, ArcElement)

const props = defineProps({
  type: { type: String, default: 'bar' }, // 'bar' | 'doughnut'
  data: { type: Object, required: true },
  options: { type: Object, default: () => ({}) },
})

const comp = computed(() => (props.type === 'doughnut' ? Doughnut : Bar))
</script>

<template>
  <component :is="comp" :data="data" :options="options" />
</template>
