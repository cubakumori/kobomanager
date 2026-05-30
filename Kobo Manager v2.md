# Plan de Implementación — KoboManager

> Documento de referencia para el desarrollo de la aplicación web de gestión de cuentas KoboToolbox.  
> Fecha de elaboración: mayo 2026  
> Entorno de desarrollo: Claude Code

---

## 1. Visión general

**KoboManager** es una aplicación web propia que actúa como capa intermedia entre las cuentas de KoboToolbox del administrador y un conjunto reducido de usuarios (menos de 10), permitiéndoles consultar, editar y validar envíos de formularios sin necesidad de tener cuenta en KoboToolbox.

### Principios rectores

- Partir siempre de lo **simple y funcional**, ampliar en fases sucesivas.
- **Nada destructivo** en las primeras fases (no eliminación de envíos, no creación de datos).
- El administrador es el único que conoce y gestiona las credenciales de Kobo.
- Los usuarios de la app nunca interactúan directamente con la API de Kobo.
- La **validación es un estado interno de KoboManager**, desacoplado de los mecanismos internos de Kobo.
- **Rendimiento por diseño**: los envíos se sirven desde caché local, no consultando Kobo en cada petición.

---

## 2. Stack tecnológico

| Capa | Tecnología | Justificación |
|---|---|---|
| Frontend | Vue 3 + Vite | Build estático, desplegable en cualquier hosting |
| Backend / API | PHP 8+ (API REST) | Compatible con VPS y hosting compartido estándar |
| Base de datos | MySQL / MariaDB | Disponible en el hosting, robusto y conocido |
| Cifrado de tokens | libSodium (`sodium_crypto_secretbox`) | Nativa en PHP moderno, autenticada, robusta |
| Notificaciones email | Resend | SDK simple, plan gratuito suficiente para la escala |
| Desktop (fase futura) | Tauri + Vue (mismo frontend) | Reutiliza todo el código frontend existente |

### Flujo de datos

```
Kobo (múltiples servidores y cuentas)
        ↕  API REST + token por cuenta
    Backend PHP  ←→  MySQL/MariaDB
        ↕  API REST propia (JSON)
    Frontend Vue
        ↕
    Usuarios de la app  (sin cuenta Kobo)
```

---

## 3. Modelo de datos (MySQL)

### 3.1 Tabla `kobo_accounts`

Almacena las credenciales del administrador en cada servidor de Kobo.  
El campo `api_token` guarda el token **ya cifrado** por `TokenVault` (ver sección 4.1).

```sql
CREATE TABLE kobo_accounts (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    label       VARCHAR(100) NOT NULL,          -- Nombre descriptivo, ej: "Cuenta HQ Europa"
    server_url  VARCHAR(255) NOT NULL,          -- ej: https://eu.kobotoolbox.org
    email       VARCHAR(255) NOT NULL,          -- Email de la cuenta en ese servidor
    api_token   TEXT NOT NULL,                  -- Token cifrado con TokenVault (libSodium)
    active      TINYINT(1) DEFAULT 1,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### 3.2 Tabla `users`

Usuarios de la app (no de Kobo).

```sql
CREATE TABLE users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    email           VARCHAR(255) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,      -- password_hash() de PHP (bcrypt)
    role            ENUM('admin', 'viewer') NOT NULL DEFAULT 'viewer',
    active          TINYINT(1) DEFAULT 1,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### 3.3 Tabla `user_sessions`

Registro de sesiones activas. Permite invalidar usuarios, cerrar sesiones remotamente y preparar el terreno para 2FA.

```sql
CREATE TABLE user_sessions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    token_id        VARCHAR(64) NOT NULL UNIQUE,  -- jti del JWT
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME NOT NULL,
    last_activity   DATETIME,
    ip              VARCHAR(45),
    user_agent      TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

### 3.4 Tabla `forms`

Caché local de los formularios obtenidos desde Kobo.  
Incluye estado de sincronización para detectar y diagnosticar fallos.

```sql
CREATE TABLE forms (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kobo_account_id     INT UNSIGNED NOT NULL,
    kobo_asset_uid      VARCHAR(50) NOT NULL,       -- UID único del asset en Kobo
    name                VARCHAR(255) NOT NULL,
    server_url          VARCHAR(255) NOT NULL,
    last_synced_at      DATETIME,
    sync_status         ENUM('pending', 'success', 'error') DEFAULT 'pending',
    last_sync_error     TEXT,                       -- Mensaje del último error, si existe
    active              TINYINT(1) DEFAULT 1,
    created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (kobo_account_id) REFERENCES kobo_accounts(id)
);
```

### 3.5 Tabla `submissions_cache`

Caché local de los envíos. Los usuarios consultan esta tabla; Kobo solo se consulta en los cron jobs de sincronización.

```sql
CREATE TABLE submissions_cache (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id         INT UNSIGNED NOT NULL,
    submission_uid  VARCHAR(100) NOT NULL UNIQUE,   -- UID del envío en Kobo
    json_payload    JSON NOT NULL,                  -- Datos completos del envío
    submitted_at    DATETIME,                       -- Fecha de envío original en Kobo
    last_synced_at  DATETIME,
    FOREIGN KEY (form_id) REFERENCES forms(id),
    INDEX idx_form_submitted (form_id, submitted_at)
);
```

### 3.6 Tabla `submission_reviews`

Estado de revisión/validación **interno de KoboManager**, desacoplado de los estados de Kobo.  
Permite que la lógica de negocio sea propia, independientemente de cambios en la API de Kobo.

```sql
CREATE TABLE submission_reviews (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_uid  VARCHAR(100) NOT NULL,
    user_id         INT UNSIGNED NOT NULL,          -- Quién hizo la revisión
    status          ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    comment         TEXT,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_submission (submission_uid)
);
```

### 3.7 Tabla `user_form_permissions`

Relación entre usuarios y formularios, con permisos granulares.  
`can_delete` se omite del MVP; se añadirá mediante migración cuando exista la funcionalidad.

```sql
CREATE TABLE user_form_permissions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    form_id         INT UNSIGNED NOT NULL,
    can_view        TINYINT(1) DEFAULT 1,   -- Ver envíos
    can_edit        TINYINT(1) DEFAULT 0,   -- Editar envíos
    can_validate    TINYINT(1) DEFAULT 0,   -- Validar/aprobar envíos (estado interno)
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (form_id) REFERENCES forms(id),
    UNIQUE KEY unique_user_form (user_id, form_id)
);
```

### 3.8 Tabla `notification_config`

Configuración de notificaciones diarias por usuario y formulario.

```sql
CREATE TABLE notification_config (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    form_id         INT UNSIGNED NOT NULL,
    daily_summary   TINYINT(1) DEFAULT 1,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (form_id) REFERENCES forms(id)
);
```

### 3.9 Tabla `audit_log`

Registro de auditoría: quién hizo qué y cuándo.

```sql
CREATE TABLE audit_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    form_id         INT UNSIGNED,
    submission_uid  VARCHAR(100),
    action          VARCHAR(50) NOT NULL,    -- 'edit', 'validate', 'view', 'approve', 'reject'
    detail          JSON,                    -- Datos del cambio (antes/después)
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

---

## 4. Arquitectura del backend (PHP)

### 4.1 TokenVault — cifrado de tokens Kobo

Los tokens de la API de Kobo se cifran con **libSodium** (`sodium_crypto_secretbox`), nativa en PHP 7.2+.  
Ventajas frente a AES manual: cifrado autenticado (detecta manipulaciones), menos superficie de error, API moderna.

```php
class TokenVault {
    // La clave maestra vive en config.php, nunca en la base de datos
    // Generarla una vez con: sodium_bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES))

    public static function encrypt(string $plaintext): string {
        $key   = sodium_hex2bin(CONFIG_TOKEN_KEY);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
        return sodium_bin2hex($nonce . $cipher);
    }

    public static function decrypt(string $encoded): string {
        $key  = sodium_hex2bin(CONFIG_TOKEN_KEY);
        $raw  = sodium_hex2bin($encoded);
        $nonce  = mb_substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit');
        $cipher = mb_substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit');
        return sodium_crypto_secretbox_open($cipher, $nonce, $key);
    }
}
```

### 4.2 Estructura de directorios

```
/api
  /v1
    auth/
      login.php
      logout.php
      me.php
    admin/
      accounts.php        (CRUD cuentas Kobo)
      users.php           (CRUD usuarios de la app)
      forms.php           (sync formularios desde Kobo)
      permissions.php     (asignar permisos usuario-formulario)
    forms/
      index.php           (listar formularios del usuario autenticado)
      [id]/
        submissions.php   (listar envíos desde caché)
        stats.php         (estadísticas desde caché)
    submissions/
      [id].php            (ver / editar / revisar un envío)
  /lib
    TokenVault.php        (cifrado libSodium de tokens Kobo)
    KoboClient.php        (wrapper de la API de Kobo)
    Auth.php              (gestión de sesión/JWT + user_sessions)
    DB.php                (conexión PDO a MySQL)
    Mailer.php            (integración con Resend)
    ErrorResponse.php     (respuestas de error estándar)
  /cron
    sync_submissions.php  (sincroniza envíos desde Kobo → submissions_cache)
    daily_summary.php     (envía resúmenes diarios por email)
  config.php
  .htaccess
```

### 4.3 Endpoints principales

| Método | Endpoint | Descripción | Rol requerido |
|---|---|---|---|
| POST | `/api/v1/auth/login` | Autenticación | — |
| POST | `/api/v1/auth/logout` | Cerrar sesión | cualquiera |
| GET | `/api/v1/auth/me` | Usuario actual | cualquiera |
| GET | `/api/v1/admin/accounts` | Listar cuentas Kobo | admin |
| POST | `/api/v1/admin/accounts` | Añadir cuenta Kobo | admin |
| GET | `/api/v1/admin/users` | Listar usuarios de la app | admin |
| POST | `/api/v1/admin/users` | Crear usuario | admin |
| POST | `/api/v1/admin/forms/sync` | Sincronizar formularios desde Kobo | admin |
| PUT | `/api/v1/admin/permissions/{userId}` | Asignar permisos a usuario | admin |
| GET | `/api/v1/forms` | Formularios asignados al usuario | viewer |
| GET | `/api/v1/forms/{id}/submissions` | Envíos (desde caché local) | viewer (can_view) |
| GET | `/api/v1/forms/{id}/stats` | Estadísticas (desde caché local) | viewer (can_view) |
| GET | `/api/v1/submissions/{id}` | Detalle de un envío | viewer (can_view) |
| PUT | `/api/v1/submissions/{id}` | Editar un envío (→ Kobo) | viewer (can_edit) |
| POST | `/api/v1/submissions/{id}/review` | Crear revisión interna (aprobar/rechazar) | viewer (can_validate) |

### 4.4 KoboClient.php — métodos esenciales

```php
class KoboClient {
    public function __construct(string $serverUrl, string $apiToken) {}

    public function getAssets(): array {}
    public function getSubmissions(string $assetUid, array $filters = []): array {}
    public function getSubmission(string $assetUid, string $submissionId): array {}
    public function editSubmission(string $assetUid, string $submissionId, array $data): bool {}
    // validateSubmission() ya no se usa para estados internos;
    // se mantiene solo si se necesita escribir el estado en Kobo adicionalmente
}
```

### 4.5 Formato estándar de errores

Todos los endpoints devuelven errores en este formato, para que el frontend pueda manejarlos de forma homogénea.

```json
{
  "success": false,
  "error": {
    "code": "KOBO_TIMEOUT",
    "message": "No se pudo contactar con el servidor de Kobo"
  }
}
```

#### Códigos de error previstos

| Código | Causa |
|---|---|
| `KOBO_TIMEOUT` | El servidor de Kobo no respondió en tiempo |
| `KOBO_UNAUTHORIZED` | Token de Kobo expirado o inválido |
| `KOBO_ACCOUNT_DISABLED` | La cuenta Kobo fue deshabilitada |
| `KOBO_FORM_NOT_FOUND` | El formulario fue eliminado en Kobo |
| `KOBO_SUBMISSION_NOT_FOUND` | El envío fue eliminado en Kobo |
| `KOBO_RATE_LIMIT` | Se alcanzó el límite de peticiones de la API |
| `AUTH_INVALID_TOKEN` | JWT inválido o expirado |
| `AUTH_INSUFFICIENT_PERMISSIONS` | El usuario no tiene el permiso requerido |
| `VALIDATION_ERROR` | Datos de entrada inválidos |
| `INTERNAL_ERROR` | Error interno del servidor |

---

## 5. Arquitectura del frontend (Vue 3)

### Estructura de directorios

```
/src
  /components
    AppSidebar.vue
    FormCard.vue
    SubmissionsTable.vue
    SubmissionDetail.vue
    StatsChart.vue
    UserPermissionsForm.vue
    ReviewBadge.vue         (muestra estado pending/approved/rejected)
  /views
    LoginView.vue
    DashboardView.vue
    FormsView.vue
    SubmissionsView.vue
    SubmissionDetailView.vue
    StatsView.vue
    /admin
      AccountsView.vue
      UsersView.vue
      PermissionsView.vue
  /stores
    auth.js           (Pinia: usuario autenticado)
    forms.js          (Pinia: formularios del usuario)
    submissions.js    (Pinia: envíos del formulario activo)
  /services
    api.js            (cliente Axios con interceptores de auth)
  router/
    index.js          (Vue Router con guards por rol)
  App.vue
  main.js
```

### Rutas

| Ruta | Vista | Rol |
|---|---|---|
| `/login` | LoginView | — |
| `/dashboard` | DashboardView | cualquiera |
| `/forms` | FormsView | viewer |
| `/forms/:id/submissions` | SubmissionsView | viewer |
| `/forms/:id/submissions/:subId` | SubmissionDetailView | viewer |
| `/forms/:id/stats` | StatsView | viewer |
| `/admin/accounts` | AccountsView | admin |
| `/admin/users` | UsersView | admin |
| `/admin/permissions` | PermissionsView | admin |

---

## 6. Autenticación y sesiones

- **Mecanismo**: Email + contraseña → el backend devuelve un **JWT** con `jti` (ID único de sesión).
- El JWT se almacena en una **cookie HttpOnly + Secure + SameSite=Lax**, no en `localStorage`.  
  Esto elimina el riesgo de robo de token por XSS.
- El backend registra cada sesión en `user_sessions` (con `token_id = jti`).
- En cada request, el backend valida el JWT **y** verifica que el `jti` exista en `user_sessions` (permite invalidación activa).
- **Expiración**: 8 horas (configurable). Sin refresh token en MVP.
- Las contraseñas se almacenan con `password_hash()` de PHP (bcrypt).
- Al hacer logout, se elimina la fila de `user_sessions` correspondiente.

---

## 7. Sincronización de envíos (cron)

La app **no consulta Kobo al abrir un formulario**. Los envíos se sirven siempre desde `submissions_cache`.

### Cron jobs

| Job | Frecuencia sugerida | Script |
|---|---|---|
| Sync de envíos | Cada 15 minutos | `cron/sync_submissions.php` |
| Resumen diario email | Diario a las 07:00 | `cron/daily_summary.php` |

### Flujo del sync

```
sync_submissions.php
  Para cada kobo_account activa:
    Para cada form activo de esa cuenta:
      → Consulta Kobo: envíos nuevos/modificados desde last_synced_at
      → Upsert en submissions_cache
      → Actualiza forms.last_synced_at, sync_status = 'success'
      Si falla:
      → forms.sync_status = 'error'
      → forms.last_sync_error = mensaje del error
```

### Beneficios

- Interfaz rápida: las consultas van contra MySQL, no contra Kobo.
- Menos llamadas a la API de Kobo (respeta rate limits).
- Estadísticas calculadas sobre datos locales, al instante.
- El estado de sincronización es visible en el panel de administración.

---

## 8. Validación / revisión de envíos

La validación es un **concepto interno de KoboManager**, no depende de los estados de Kobo.

- Cada envío puede tener una o más revisiones en `submission_reviews`.
- El estado activo de un envío es el de su revisión más reciente.
- Estados posibles: `pending` / `approved` / `rejected`.
- Al crear una revisión, se registra también en `audit_log`.
- Si en el futuro se quiere escribir el estado en Kobo adicionalmente, se hace desde `KoboClient` sin afectar esta lógica.

---

## 9. Notificaciones por email (Resend)

### Funcionamiento

1. El cron job diario (07:00) ejecuta `cron/daily_summary.php`.
2. Consulta usuarios con `daily_summary = 1` en `notification_config`.
3. Para cada usuario, obtiene los envíos nuevos en `submissions_cache` desde el día anterior.
4. Si hay envíos nuevos, envía un email de resumen con Resend.

### Estructura del email de resumen

```
Asunto: [KoboManager] Resumen diario — {fecha}

Hola {nombre},

Nuevos envíos recibidos ayer:

  • Formulario "Encuesta de satisfacción": 12 nuevos envíos
  • Formulario "Registro de beneficiarios": 3 nuevos envíos

Accede a la app para revisarlos: https://tudominio.com/forms

---
Para desactivar estos avisos, ve a tu perfil en la app.
```

---

## 10. Fases de desarrollo

### Fase 0 — Scaffolding y configuración (1–2 días)

- [ ] Crear estructura de directorios del proyecto
- [ ] Configurar Vite + Vue 3 + Vue Router + Pinia
- [ ] Crear base de datos MySQL con todas las tablas del modelo
- [ ] Crear `config.php` con variables de entorno (DB, JWT secret, Resend API key, TOKEN_KEY para libSodium)
- [ ] Generar clave maestra para `TokenVault`: `sodium_bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES))`
- [ ] Configurar `.htaccess` para ruteo de la API PHP
- [ ] Primer deploy de prueba en VPS

### Fase 1 — Autenticación y panel admin (3–4 días)

- [ ] Backend: `TokenVault.php` con `encrypt()`/`decrypt()` usando libSodium
- [ ] Backend: endpoints de login/logout/me con JWT + cookie HttpOnly
- [ ] Backend: registro y validación de sesiones en `user_sessions`
- [ ] Backend: CRUD de usuarios (`/admin/users`)
- [ ] Backend: CRUD de cuentas Kobo (`/admin/accounts`) — tokens cifrados con `TokenVault`
- [ ] Frontend: LoginView con validación
- [ ] Frontend: Panel admin — gestión de usuarios y cuentas Kobo
- [ ] Guards de ruta por rol en Vue Router

### Fase 2 — Sincronización y permisos (2–3 días)

- [ ] Backend: `KoboClient.php` — `getAssets()` para cada cuenta
- [ ] Backend: endpoint de sincronización de formularios (`/admin/forms/sync`)
  - Actualiza `sync_status` y `last_sync_error` en `forms`
- [ ] Backend: endpoint de asignación de permisos (`/admin/permissions`)
- [ ] Frontend: Vista de sincronización con indicador de estado por formulario
- [ ] Frontend: Vista de asignación de permisos por usuario

### Fase 3 — Caché de envíos y vista de datos (3–4 días)

- [ ] Backend: `cron/sync_submissions.php` — upsert en `submissions_cache`
- [ ] Backend: endpoints de listado y detalle de envíos (desde caché)
- [ ] Frontend: SubmissionsView — tabla paginada con filtros
- [ ] Frontend: SubmissionDetailView — vista de detalle
- [ ] Registro en `audit_log` al visualizar

### Fase 4 — Edición y revisión interna (2–3 días)

- [ ] Backend: `editSubmission()` — escribe cambio en Kobo y actualiza caché
- [ ] Backend: `POST /submissions/{id}/review` — crea registro en `submission_reviews`
- [ ] Frontend: formulario de edición en SubmissionDetailView (si `can_edit`)
- [ ] Frontend: panel de revisión con estados pending/approved/rejected (si `can_validate`)
- [ ] `ReviewBadge.vue` para mostrar estado de revisión en tablas y detalle

### Fase 5 — Estadísticas (2–3 días)

- [ ] Backend: endpoint `/forms/{id}/stats` calculado sobre `submissions_cache`
- [ ] Frontend: StatsView con gráficos (Chart.js o ApexCharts)
- [ ] Estadísticas: total envíos, envíos por día, distribución por estado de revisión

### Fase 6 — Notificaciones por email (1–2 días)

- [ ] Integrar Resend SDK en PHP
- [ ] Crear `cron/daily_summary.php` (usa `submissions_cache`, no Kobo)
- [ ] Frontend: configuración de notificaciones en perfil de usuario
- [ ] Configurar cron jobs en VPS

### Fase 7 — Pulido y seguridad (2 días)

- [ ] Verificar que ningún endpoint exponga tokens de Kobo al frontend
- [ ] Rate limiting básico en login (ej. 5 intentos por IP por minuto)
- [ ] `ErrorResponse.php` — todos los errores en formato estándar con códigos definidos
- [ ] Manejo de errores de Kobo en frontend (mensajes claros por código de error)
- [ ] Pruebas con múltiples cuentas y servidores Kobo

---

## 11. Consideraciones de seguridad

- Los **tokens de la API de Kobo** nunca salen del backend ni son visibles en el frontend.
- Los tokens se cifran con **libSodium** (`TokenVault`) antes de almacenarse. La clave maestra vive en `config.php`, nunca en la base de datos.
- El JWT viaja en **cookie HttpOnly**, eliminando el riesgo de XSS.
- Las sesiones se registran en `user_sessions`; el admin puede invalidarlas activamente.
- Los permisos se verifican **siempre en el backend**, nunca solo en el frontend.
- Usar **HTTPS** en producción (Let's Encrypt).
- Los cron jobs no son accesibles vía web (protegidos por CLI o IP).
- Rate limiting en el endpoint de login.

---

## 12. Consideraciones de despliegue

### Estructura en el VPS

```
/public_html  (o /var/www/html)
  /              ← build estático de Vue (dist/)
  /api           ← PHP backend
  .htaccess      ← rewrite rules
```

### `.htaccess` raíz

```apache
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteRule ^api/ - [L]
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteRule ^ index.html [L]
</IfModule>
```

### Flujo de deploy

1. `npm run build` en local → genera `dist/`
2. Subir `dist/*` al root del servidor
3. Subir `/api` al servidor
4. Configurar `config.php` (DB, claves, Resend)
5. Ejecutar migraciones SQL
6. Configurar cron jobs en VPS:
   ```
   */15 * * * *  php /ruta/api/cron/sync_submissions.php
   0    7 * * *  php /ruta/api/cron/daily_summary.php
   ```

---

## 13. Posibles ampliaciones futuras

- **Versión de escritorio** con Tauri (envuelve el mismo frontend Vue)
- **Webhooks de Kobo** para sincronización en cuasi-tiempo-real
- **Notificaciones por otros canales** (Telegram, Slack, WhatsApp)
- **Exportación de datos** (CSV, Excel) desde la app
- **Permiso `can_delete`** — añadir mediante migración SQL cuando exista la funcionalidad
- **Permisos más granulares** (por grupo de formularios, por período de tiempo)
- **Múltiples idiomas** (i18n con Vue I18n)
- **Dashboard de administración global** con estado de todas las cuentas y sincronizaciones
- **2FA** — la tabla `user_sessions` ya está preparada para soportarlo

---

*Fin del documento. Usar este plan como contexto de inicio en Claude Code.*
