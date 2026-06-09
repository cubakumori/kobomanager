#!/usr/bin/env node
// Verifica que los catálogos i18n (src/i18n/locales/{es,en}/*.json) tengan
// EXACTAMENTE las mismas claves (paridad es/en), que ambos locales tengan los
// mismos ficheros y que ningún namespace esté definido en dos ficheros a la vez.
// Sale con código 1 si hay desajustes. Usado por CI y en local (`npm run i18n:check`).

import { readFileSync, readdirSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const localesDir = join(root, 'src/i18n/locales')
const LOCALES = ['es', 'en']

const listFiles = (locale) =>
  readdirSync(join(localesDir, locale)).filter((f) => f.endsWith('.json')).sort()

const flatten = (obj) => {
  const out = []
  const walk = (x, prefix) => {
    for (const k of Object.keys(x)) {
      const key = prefix ? `${prefix}.${k}` : k
      if (x[k] && typeof x[k] === 'object') walk(x[k], key)
      else out.push(key)
    }
  }
  walk(obj, '')
  return out.sort()
}

let failed = false
const fail = (...msg) => {
  console.error(...msg)
  failed = true
}

// 1. Mismos ficheros en ambos locales.
const [filesEs, filesEn] = LOCALES.map(listFiles)
if (filesEs.join() !== filesEn.join()) {
  fail('Ficheros distintos entre locales:', { es: filesEs, en: filesEn })
}

// 2. Cargar catálogos, detectando namespaces duplicados entre ficheros.
const catalogs = {}
for (const locale of LOCALES) {
  const merged = {}
  const owner = {}
  for (const file of listFiles(locale)) {
    const data = JSON.parse(readFileSync(join(localesDir, locale, file), 'utf8'))
    for (const ns of Object.keys(data)) {
      if (owner[ns]) fail(`Namespace "${ns}" duplicado en ${locale}: ${owner[ns]} y ${file}`)
      owner[ns] = file
      merged[ns] = data[ns]
    }
  }
  catalogs[locale] = merged
}

// 3. Paridad de claves es/en.
const es = flatten(catalogs.es)
const en = flatten(catalogs.en)
const missingInEn = es.filter((k) => !en.includes(k))
const missingInEs = en.filter((k) => !es.includes(k))
if (missingInEn.length) fail('  Faltan en en/*.json:', missingInEn)
if (missingInEs.length) fail('  Faltan en es/*.json:', missingInEs)

if (failed) {
  console.error('i18n desincronizado')
  process.exit(1)
}
console.log(`i18n OK: ${es.length} claves en paridad (es/en) en ${filesEs.length} ficheros`)
