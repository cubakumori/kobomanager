# Despliegue — KoboManager

Guía para desplegar en un VPS o hosting con **Apache + PHP 8.1+ + MySQL/MariaDB**.

## 1. Requisitos del servidor

- PHP 8.1 o superior con extensiones `sodium`, `pdo_mysql` y `curl`.
- MySQL 5.7+ / MariaDB 10.4+.
- Apache con `mod_rewrite` activado (o Nginx con reglas equivalentes).
- HTTPS (Let's Encrypt). **Imprescindible**: la cookie de sesión usa `Secure`.

## 2. Estructura en el servidor

El frontend compilado vive en la raíz pública y el backend en `/api`:

```
/public_html            (o /var/www/html)
  index.html            ← build de Vue (dist/)
  assets/               ← build de Vue (dist/assets)
  .htaccess             ← rewrite del SPA (ver §6)
  /api                  ← backend PHP (subir tal cual)
    config.php          ← crear en el servidor, NO se versiona
    .htaccess
    index.php, lib/, v1/, cron/, cli/
```

## 3. Construir y subir

En local:

```bash
npm install
npm run build          # genera dist/
```

Subir al servidor:

1. El **contenido** de `dist/` → a la raíz pública (`index.html`, `assets/`, …).
2. La carpeta `api/` completa → bajo la raíz pública (`/api`).
3. La carpeta `db/` (migraciones) si vas a aplicarlas desde el servidor.

> No subas `node_modules/`, `src/`, ni `api/config.php` de desarrollo.

## 4. Base de datos

```bash
mysql -e "CREATE DATABASE kobomanager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
for f in db/*.sql; do mysql kobomanager < "$f"; done
```

Crea un usuario MySQL dedicado con privilegios solo sobre `kobomanager`.

## 5. Configuración (`api/config.php`)

Copia `api/config.example.php` a `api/config.php` y ajusta para **producción**:

```php
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'kobomanager');
define('DB_USER', 'kobomanager');
define('DB_PASS', '••••••');

// Generar una vez y NO cambiar después (invalidaría tokens/sesiones):
define('CONFIG_TOKEN_KEY', '<64 hex>'); // php -r 'echo sodium_bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));'
define('JWT_SECRET',       '<64 hex>'); // php -r 'echo bin2hex(random_bytes(32));'

define('COOKIE_SECURE', true);          // ← true en producción (HTTPS)
define('APP_ENV', 'prod');              // oculta detalles de error
define('APP_URL', 'https://tudominio.com');
define('CORS_ALLOWED_ORIGINS', ['https://tudominio.com']);

define('RESEND_API_KEY', 're_••••••');  // para el resumen diario
define('MAIL_FROM', 'KoboManager <noreply@tudominio.com>');
```

> **Importante:** guarda `CONFIG_TOKEN_KEY` en un lugar seguro. Si se pierde o cambia,
> los tokens de Kobo cifrados dejan de poder descifrarse.

Crea el primer administrador (la creación vía API exige ya ser admin):

```bash
php api/cli/create_user.php admin@tudominio.com 'ContraseñaFuerte' 'Nombre Admin' admin
```

## 6. Rewrite del SPA (`.htaccess` de la raíz)

Sirve la API y, para todo lo demás, devuelve `index.html` (rutas del SPA):

```apache
<IfModule mod_rewrite.c>
  RewriteEngine On
  # No tocar /api (tiene su propio .htaccess)
  RewriteRule ^api/ - [L]
  # Si el fichero no existe, servir el SPA
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule ^ index.html [L]
</IfModule>
```

El `.htaccess` de `/api` ya viene en el repo (enruta por `index.php` y bloquea
`lib/`, `cron/`, `cli/` y `config.php`).

## 7. Cron jobs

```cron
*/15 * * * *  php /ruta/api/cron/sync_submissions.php   # envíos Kobo → caché
0    7 * * *  php /ruta/api/cron/daily_summary.php       # resumen diario por email
```

Ambos scripts solo se ejecutan por CLI (rechazan peticiones web). El resumen diario
requiere `RESEND_API_KEY` y `MAIL_FROM` configurados.

## 8. Comprobaciones tras desplegar

- `https://tudominio.com/api/v1/health` → `status: ok` (php, sodium, pdo_mysql, database).
- Login con el admin creado; la cookie debe viajar como `Secure` + `HttpOnly`.
- Añadir una cuenta Kobo real y pulsar **Sincronizar** en *Formularios*.
- Lanzar `sync_submissions.php` manualmente una vez y revisar `submissions_cache`.

## 9. Actualizaciones posteriores

1. `npm run build` en local.
2. Reemplazar `index.html` + `assets/` en la raíz y la carpeta `api/` (sin tocar `config.php`).
3. Aplicar nuevas migraciones de `db/` si las hay.
