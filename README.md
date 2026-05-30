# KoboManager

Capa intermedia entre cuentas de KoboToolbox y un grupo reducido de usuarios, para
consultar, editar y validar envíos sin necesidad de cuenta en Kobo.

- Historial de cambios: [`CHANGELOG.md`](./CHANGELOG.md)
- Pendientes e ideas futuras: [`ROADMAP.md`](./ROADMAP.md)
- Despliegue en producción: [`DEPLOY.md`](./DEPLOY.md)

## Estructura del repositorio

El frontend vive en la raíz (igual que en despliegue); el backend en `/api`.

```
/            Vue 3 + Vite (SPA): index.html, src/, public/, vite.config.js
/api         Backend PHP 8 (API REST)
/db          Migraciones SQL
```

En despliegue, el build de `dist/` va a la raíz del servidor y `/api` se sube
tal cual (ver [`DEPLOY.md`](./DEPLOY.md)).

## Requisitos

- PHP 8.1+ con extensiones `sodium` y `pdo_mysql`
- MySQL / MariaDB
- Node.js 18+ y npm

## Puesta en marcha (desarrollo)

### 1. Base de datos

```bash
mysql -e "CREATE DATABASE IF NOT EXISTS kobomanager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
# Aplicar todas las migraciones en orden
for f in db/*.sql; do mysql kobomanager < "$f"; done
```

> En este repo `api/config.php` ya está creado con claves de desarrollo. **No** versionar
> ese archivo (está en `.gitignore`). Para un entorno nuevo:
>
> ```bash
> cp api/config.example.php api/config.php   # y rellenar valores
> php -r 'echo sodium_bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));'   # CONFIG_TOKEN_KEY
> php -r 'echo bin2hex(random_bytes(32));'                                         # JWT_SECRET
> ```

### 2. Arrancar la app (un solo comando)

```bash
npm install
npm run dev
```

Esto levanta **a la vez** (con `concurrently`):

- **api** → `php -S 127.0.0.1:8787 api/index.php` (backend)
- **web** → `vite` en http://localhost:5173 (proxy `/api` → backend)

Abrir http://localhost:5173. El dashboard muestra el resultado de `/api/v1/health`.

Scripts sueltos por si se necesitan: `npm run dev:api`, `npm run dev:web`, `npm run build`.

### 3. Crear el primer administrador

La creación de usuarios vía API requiere ya estar autenticado como admin, así que el
primer admin se crea por CLI:

```bash
php api/cli/create_user.php <email> <password> <nombre> admin
```

## Estado

Primera versión funcional completa. El detalle de lo entregado está en
[`CHANGELOG.md`](./CHANGELOG.md) y lo pendiente en [`ROADMAP.md`](./ROADMAP.md).
