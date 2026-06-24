#!/usr/bin/env node
/**
 * Empaqueta una release «deploy-ready» de KoboManager: exactamente lo que se sube
 * al servidor, con el layout de DEPLOY.md §2 ya armado.
 *
 *   npm run package
 *
 * Produce `release/kobomanager-<versión>.zip` con un único directorio raíz
 * `kobomanager-<versión>/` que contiene:
 *   - el contenido de `dist/` (build del frontend, incluido el `.htaccess` raíz)
 *   - `api/` PODADO: sin `vendor/`, `tests/`, `phpunit.xml`, `composer.*` ni el
 *     `config.php` con secretos (sí va `config.example.php` y `api/.htaccess`).
 *     El runtime no tiene dependencias PHP (autoload por `require`, no Composer).
 *   - `db/*.sql` (esquema + defaults)
 *
 * Instalar = descomprimir, subir el CONTENIDO de esa carpeta al webroot, crear
 * `api/config.php` desde el ejemplo y aplicar `db/*.sql` (o usar el instalador CLI).
 *
 * Sin dependencias npm: usa solo Node y el binario `zip` del sistema (vía
 * execFileSync con argumentos en array, sin shell). La misma lógica la puede
 * invocar un workflow de CI (ver .github/workflows/release.yml).
 */
import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const pkg = JSON.parse(fs.readFileSync(path.join(root, 'package.json'), 'utf8'))
const version = pkg.version
const name = `kobomanager-${version}`

const releaseDir = path.join(root, 'release')
const stageDir = path.join(releaseDir, name)
const zipPath = path.join(releaseDir, `${name}.zip`)

const log = (m) => console.log(m)

// Comprobar que `zip` está disponible (no añadimos dependencias npm para esto).
try {
  execFileSync('zip', ['-v'], { stdio: 'ignore' })
} catch {
  console.error('✗ Falta el binario `zip`. Instálalo (p. ej. `apt-get install zip`) y reintenta.')
  process.exit(1)
}

// 1) Limpiar el espacio de trabajo de releases.
fs.rmSync(releaseDir, { recursive: true, force: true })
fs.mkdirSync(stageDir, { recursive: true })

// 2) Build del frontend.
log('▶ Build del frontend (npm run build)…')
execFileSync('npm', ['run', 'build'], { cwd: root, stdio: 'inherit' })

// 3) Contenido de dist/ → raíz de la carpeta de la release (incluidos los dotfiles
//    como .htaccess; fs.cpSync copia todo).
const distDir = path.join(root, 'dist')
if (!fs.existsSync(path.join(distDir, 'index.html'))) {
  console.error('✗ No se encontró dist/index.html tras el build. Aborto.')
  process.exit(1)
}
// Excluye basura de macOS (.DS_Store) en cualquier nivel.
const isJunk = (src) => path.basename(src) === '.DS_Store'
fs.cpSync(distDir, stageDir, { recursive: true, filter: (src) => !isJunk(src) })

// 4) api/ PODADO → release/<name>/api
//    Se excluye la grasa de desarrollo y, crucialmente, el config.php con secretos.
const API_EXCLUDE = new Set([
  'config.php',        // secretos: NUNCA se empaqueta (sí va config.example.php)
  'vendor',            // solo dev (Composer/PHPUnit); el runtime no lo usa
  'tests',             // solo dev
  'phpunit.xml',       // solo dev
  'composer.json',     // solo dev
  'composer.lock',     // solo dev
  '.phpunit.cache',    // solo dev
])
const apiDir = path.join(root, 'api')
fs.cpSync(apiDir, path.join(stageDir, 'api'), {
  recursive: true,
  filter: (src) => {
    if (isJunk(src)) return false
    const rel = path.relative(apiDir, src)
    if (rel === '') return true
    const top = rel.split(path.sep)[0]
    return !API_EXCLUDE.has(top)
  },
})

// 5) db/*.sql → release/<name>/db
const dbDir = path.join(root, 'db')
fs.cpSync(dbDir, path.join(stageDir, 'db'), {
  recursive: true,
  filter: (src) => fs.statSync(src).isDirectory() || src.endsWith('.sql'),
})

// Salvaguarda: que el config.php con secretos NO se haya colado.
if (fs.existsSync(path.join(stageDir, 'api', 'config.php'))) {
  console.error('✗ ABORTO: api/config.php (secretos) acabó en el paquete.')
  process.exit(1)
}

// 6) Comprimir. `-X` omite atributos extra del sistema de archivos para un zip limpio.
log('▶ Comprimiendo…')
execFileSync('zip', ['-r', '-X', zipPath, name], { cwd: releaseDir, stdio: 'inherit' })

// 7) Resumen.
const sizeMB = (fs.statSync(zipPath).size / (1024 * 1024)).toFixed(2)
log('')
log(`✓ Release lista: ${path.relative(root, zipPath)} (${sizeMB} MB)`)
log(`  Contenido en: ${path.relative(root, stageDir)}/`)
log('  Instalar: descomprimir → subir el contenido de la carpeta al webroot →')
log('  crear api/config.php desde config.example.php → aplicar db/*.sql (o usar el instalador CLI).')
