# KoboManager

Capa intermedia entre cuentas de KoboToolbox y un grupo reducido de usuarios, para
consultar, editar y validar envíos sin necesidad de cuenta en Kobo. Ver el plan
completo en [`Kobo Manager v2.md`](./Kobo%20Manager%20v2.md).

## Estructura del repositorio (monorepo de desarrollo)

```
/frontend   Vue 3 + Vite (SPA)
/api        Backend PHP 8 (API REST)
/db         Migraciones SQL
```

En despliegue, el build de `frontend/dist/` va a la raíz del servidor y `/api` se sube
tal cual (ver sección 12 del plan).

## Requisitos

- PHP 8.1+ con extensiones `sodium` y `pdo_mysql`
- MySQL / MariaDB
- Node.js 18+ y npm

## Puesta en marcha (desarrollo)

### 1. Base de datos

```bash
mysql -e "CREATE DATABASE IF NOT EXISTS kobomanager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql kobomanager < db/001_schema.sql
```

### 2. Backend

```bash
cp api/config.example.php api/config.php   # y rellenar valores
# Generar claves:
php -r 'echo sodium_bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));'   # CONFIG_TOKEN_KEY
php -r 'echo bin2hex(random_bytes(32));'                                         # JWT_SECRET

# Servidor de desarrollo (front controller):
php -S 127.0.0.1:8787 api/index.php
# Comprobar: curl http://127.0.0.1:8787/api/v1/health
```

> En este repo `api/config.php` ya está creado con claves de desarrollo. **No** versionar
> ese archivo (está en `.gitignore`).

### 3. Frontend

```bash
cd frontend
npm install
npm run dev    # http://localhost:5173 (proxy /api -> 127.0.0.1:8787)
```

## Estado

- [x] **Fase 0** — Scaffolding, esquema SQL, config, `.htaccess`, health-check end-to-end.
- [ ] Fase 1 — Autenticación (JWT + cookie HttpOnly) y panel admin.
- [ ] Fase 2 — Sincronización de formularios y permisos.
- [ ] Fase 3 — Caché de envíos y vistas de datos.
- [ ] Fase 4 — Edición y revisión interna.
- [ ] Fase 5 — Estadísticas.
- [ ] Fase 6 — Notificaciones por email.
- [ ] Fase 7 — Pulido y seguridad.
