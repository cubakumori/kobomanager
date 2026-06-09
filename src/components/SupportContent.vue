<script setup>
import { reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { publicApi } from '../services/api'

// Enlaces externos (espejo del README).
const repoUrl = 'https://github.com/cubakumori/kobomanager'
const paypalUrl = 'https://paypal.me/ernestortiz'
const kofiUrl = 'https://ko-fi.com/kumoricuba'

const form = reactive({ name: '', email: '', org: '', topic: 'general', message: '' })
const topics = ['general', 'hire', 'proposal', 'using']

const sending = ref(false)
const sent = ref(false)
const error = ref('') // clave i18n del mensaje de error, o ''

async function submit() {
  if (sending.value) return
  error.value = ''
  sending.value = true
  try {
    await publicApi.post('/public/contact', {
      name: form.name,
      email: form.email,
      org: form.org,
      topic: form.topic,
      message: form.message,
    })
    sent.value = true
  } catch (e) {
    const code = e?.response?.data?.error?.code
    const msg = e?.response?.data?.error?.message || ''
    if (code === 'RATE_LIMITED') error.value = 'support.errRate'
    else if (code === 'VALIDATION_ERROR' && /email/i.test(msg)) error.value = 'support.errEmail'
    else error.value = 'support.errGeneric'
  } finally {
    sending.value = false
  }
}

function reset() {
  sent.value = false
  error.value = ''
  form.name = ''
  form.email = ''
  form.org = ''
  form.topic = 'general'
  form.message = ''
}
</script>

<template>
  <div class="space-y-10">
    <!-- Encabezado -->
    <header class="text-center">
      <h1 class="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">{{ $t('support.title') }}</h1>
      <p class="mx-auto mt-3 max-w-2xl text-slate-600">{{ $t('support.subtitle') }}</p>
    </header>

    <!-- Libre de usar + Donar (dos tarjetas) -->
    <div class="grid gap-5 md:grid-cols-2">
      <section class="rounded-2xl bg-slate-50 p-6 ring-1 ring-slate-200">
        <h2 class="text-lg font-semibold text-slate-900">{{ $t('support.freeTitle') }}</h2>
        <p class="mt-2 text-sm text-slate-600">{{ $t('support.freeBody') }}</p>
        <div class="mt-4 flex flex-wrap gap-3">
          <a
            :href="repoUrl"
            target="_blank"
            rel="noopener"
            class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
          >{{ $t('support.freeRepo') }}</a>
          <RouterLink
            :to="{ name: 'guide' }"
            class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
          >{{ $t('support.freeGuide') }}</RouterLink>
        </div>
      </section>

      <section class="rounded-2xl bg-accent-50 p-6 ring-1 ring-accent-200">
        <h2 class="text-lg font-semibold text-accent-800">{{ $t('support.donateTitle') }}</h2>
        <p class="mt-2 text-sm text-accent-900/70">{{ $t('support.donateBody') }}</p>
        <div class="mt-4 flex flex-wrap gap-3">
          <a
            :href="paypalUrl"
            target="_blank"
            rel="noopener"
            class="rounded-xl bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-700"
          >{{ $t('support.donatePaypal') }}</a>
          <a
            :href="kofiUrl"
            target="_blank"
            rel="noopener"
            class="rounded-xl bg-accent-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-accent-700"
          >{{ $t('support.donateKofi') }}</a>
        </div>
      </section>
    </div>

    <!-- Servicios -->
    <section class="rounded-2xl bg-slate-50 p-6 ring-1 ring-slate-200">
      <h2 class="text-lg font-semibold text-slate-900">{{ $t('support.servicesTitle') }}</h2>
      <p class="mt-2 text-sm text-slate-600">{{ $t('support.servicesBody') }}</p>
      <ul class="mt-4 space-y-2">
        <li v-for="n in 4" :key="n" class="flex gap-2 text-sm text-slate-700">
          <span class="mt-1.5 h-1.5 w-1.5 flex-none rounded-full bg-primary-500"></span>
          {{ $t('support.service' + n) }}
        </li>
      </ul>
    </section>

    <!-- Contacto -->
    <section class="rounded-2xl bg-white p-6 ring-1 ring-slate-200">
      <h2 class="text-lg font-semibold text-slate-900">{{ $t('support.contactTitle') }}</h2>
      <p class="mt-2 text-sm text-slate-600">{{ $t('support.contactBody') }}</p>

      <!-- Estado: enviado -->
      <div v-if="sent" class="mt-5 rounded-xl bg-success-50 p-5 text-center ring-1 ring-success-200">
        <p class="font-semibold text-success-800">{{ $t('support.okTitle') }}</p>
        <p class="mt-1 text-sm text-success-900/70">{{ $t('support.okBody') }}</p>
        <button
          class="mt-4 rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
          @click="reset"
        >{{ $t('support.sendAnother') }}</button>
      </div>

      <!-- Formulario -->
      <form v-else class="mt-5 space-y-4" @submit.prevent="submit">
        <div class="grid gap-4 sm:grid-cols-2">
          <label class="block">
            <span class="text-sm font-medium text-slate-700">{{ $t('support.fName') }}</span>
            <input
              v-model="form.name"
              type="text"
              required
              maxlength="120"
              class="mt-1 block w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500"
            />
          </label>
          <label class="block">
            <span class="text-sm font-medium text-slate-700">{{ $t('support.fEmail') }}</span>
            <input
              v-model="form.email"
              type="email"
              required
              maxlength="255"
              class="mt-1 block w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500"
            />
          </label>
          <label class="block">
            <span class="text-sm font-medium text-slate-700">{{ $t('support.fOrg') }}</span>
            <input
              v-model="form.org"
              type="text"
              maxlength="160"
              class="mt-1 block w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500"
            />
          </label>
          <label class="block">
            <span class="text-sm font-medium text-slate-700">{{ $t('support.fTopic') }}</span>
            <select
              v-model="form.topic"
              class="mt-1 block w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500"
            >
              <option v-for="t in topics" :key="t" :value="t">
                {{ $t('support.topic' + t.charAt(0).toUpperCase() + t.slice(1)) }}
              </option>
            </select>
          </label>
        </div>
        <label class="block">
          <span class="text-sm font-medium text-slate-700">{{ $t('support.fMessage') }}</span>
          <textarea
            v-model="form.message"
            required
            rows="5"
            maxlength="5000"
            class="mt-1 block w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500"
          ></textarea>
        </label>

        <p v-if="error" class="text-sm font-medium text-red-600">{{ $t(error) }}</p>

        <div>
          <button
            type="submit"
            :disabled="sending"
            class="rounded-xl bg-primary-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-700 disabled:opacity-60"
          >{{ sending ? $t('support.sending') : $t('support.send') }}</button>
        </div>
      </form>
    </section>
  </div>
</template>
