<?php
/**
 * Modo demo (`DEMO_MODE`): instancia pública de demostración.
 *
 * Con el flag activo, la instancia muestra un banner global y BLOQUEA las
 * acciones que romperían la demo o filtrarían secretos:
 *   - CRUD de cuentas Kobo (protege el token de la cuenta demo).
 *   - CRUD de usuarios, cambios de contraseña (propio y ajeno) y revocación de
 *     sesiones (las propias también: el usuario demo es compartido entre
 *     visitantes y cerrarlas echaría a los demás).
 *   - Ajustes globales.
 *   - Edición de envíos (escribe en la cuenta Kobo real; el reset periódico de
 *     la BD local no lo desharía).
 *   - Sincronización manual contra Kobo (ahorra cuota; los cron del servidor
 *     siguen sincronizando solos).
 *
 * Todo lo demás (revisión individual y en lote, filtros, export, enlaces
 * compartidos, estadísticas, mapa, idioma…) queda permitido: es local y el
 * reset periódico lo restaura.
 *
 * Las constantes son OPCIONALES (guard `defined()`): una config sin ellas
 * equivale a demo desactivada. Ver config.example.php y DEMO.md.
 */
class Demo {

    /** ¿Está activo el modo demo? */
    public static function enabled(): bool {
        return defined('DEMO_MODE') && DEMO_MODE;
    }

    /** Minutos del ciclo de reset (informativo, para el banner del frontend). */
    public static function resetMinutes(): int {
        return defined('DEMO_RESET_MINUTES') ? max(1, (int) DEMO_RESET_MINUTES) : 60;
    }

    /**
     * Credenciales de la demo a mostrar al visitante, POR ROL ('' = no se
     * muestra esa línea). Texto libre tipo 'email / contraseña'; la etiqueta
     * del rol la pone el frontend traducida al idioma del visitante. Los
     * usuarios deben EXISTIR en la instancia (el texto no crea nada).
     */
    public static function loginAdmin(): string {
        return defined('DEMO_LOGIN_ADMIN') ? (string) DEMO_LOGIN_ADMIN : '';
    }

    public static function loginViewer(): string {
        return defined('DEMO_LOGIN_VIEWER') ? (string) DEMO_LOGIN_VIEWER : '';
    }

    /**
     * Denylist: patrón de ruta (el MISMO de la tabla del front controller)
     * => métodos HTTP bloqueados en demo.
     */
    private const BLOCKED = [
        // Cuentas Kobo: nadie crea/edita/borra (el token de la demo no se toca).
        'admin/accounts'           => ['POST'],
        'admin/accounts/:id'       => ['PUT', 'DELETE'],
        // Usuarios: CRUD, contraseñas y sesiones ajenas.
        'admin/users'              => ['POST'],
        'admin/users/:id'          => ['PUT', 'DELETE'],
        // Formularios: borrar uno purga su caché local (cascade) y degrada la
        // demo hasta el siguiente reset. (PUT no tiene handler hoy; se bloquea
        // por si se añade.)
        'admin/forms/:id'          => ['DELETE', 'PUT'],
        'admin/users/:id/sessions' => ['DELETE'],
        // Contraseña y sesiones propias (el usuario demo es compartido).
        'profile/password'         => ['POST'],
        'profile/sessions'         => ['DELETE'],
        // Recuperación de contraseña (otra vía de cambiarla).
        'auth/forgot-password'     => ['POST'],
        'auth/reset-password'      => ['POST'],
        // Ajustes globales.
        'admin/settings'           => ['PUT'],
        // Edición de envíos: escribe en la cuenta Kobo real.
        'submissions/:id'          => ['PUT'],
        // Sync manual contra Kobo (los cron del servidor siguen activos).
        'admin/forms/sync'         => ['POST'],
        'admin/forms/:id/sync'     => ['POST'],
        'forms/:id/sync'           => ['POST'],
    ];

    /** ¿Bloquea el modo demo este patrón de ruta + método? */
    public static function blocks(string $pattern, string $method): bool {
        if (!self::enabled()) return false;
        $methods = self::BLOCKED[$pattern] ?? null;
        return $methods !== null && in_array(strtoupper($method), $methods, true);
    }
}
