#!/usr/bin/env node
// Verifica que los catálogos i18n es.json y en.json tengan EXACTAMENTE las mismas
// claves (paridad). Sale con código 1 si hay desajustes. Usado por CI y en local
// (`npm run i18n:check`).

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const load = (f) => JSON.parse(readFileSync(join(root, 'src/i18n', f), 'utf8'))

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

const es = flatten(load('es.json'))
const en = flatten(load('en.json'))
const missingInEn = es.filter((k) => !en.includes(k))
const missingInEs = en.filter((k) => !es.includes(k))

if (missingInEn.length || missingInEs.length) {
  console.error('i18n desincronizado:')
  if (missingInEn.length) console.error('  Faltan en en.json:', missingInEn)
  if (missingInEs.length) console.error('  Faltan en es.json:', missingInEs)
  process.exit(1)
}

console.log(`i18n OK: ${es.length} claves en paridad (es/en)`)
