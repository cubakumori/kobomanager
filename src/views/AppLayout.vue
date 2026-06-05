<script setup>
import AppSidebar from '../components/AppSidebar.vue'
import ConfirmDialog from '../components/ConfirmDialog.vue'
</script>

<template>
  <div class="flex min-h-screen bg-slate-100 text-slate-800">
    <AppSidebar />
    <main class="flex-1 overflow-y-auto">
      <div class="mx-auto max-w-5xl px-8 py-8">
        <slot />
      </div>
    </main>
    <ConfirmDialog />
  </div>
</template>
