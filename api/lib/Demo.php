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
 *   - Gestión de la bandeja de mensajes de contacto (pueden llegar mensajes
 *     reales a la demo y un visitante no debe poder borrarlos).
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

    /** Minutos del ciclo de reset: gobierna cron/demo_reset.php (auto-regulado)
     *  y se muestra en el diálogo de bienvenida del frontend. */
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
        // por si se añade.) PATCH = ajustes de estadísticas por formulario.
        'admin/forms/:id'          => ['DELETE', 'PUT', 'PATCH'],
        'admin/users/:id/sessions' => ['DELETE'],
        // Contraseña y sesiones propias (el usuario demo es compartido).
        'profile/password'         => ['POST'],
        'profile/sessions'         => ['DELETE'],
        // Recuperación de contraseña (otra vía de cambiarla).
        'auth/forgot-password'     => ['POST'],
        'auth/reset-password'      => ['POST'],
        // Ajustes globales.
        'admin/settings'           => ['PUT'],
        // Generar la semilla de la demo: solo tiene sentido con la demo APAGADA
        // (bucle de mantenimiento de DEMO.md); encendida, exportaría el estado
        // sucio que los visitantes van dejando.
        'admin/demo/seed'          => ['POST'],
        // Copia de seguridad de la BD: el export dejaría a cualquier visitante
        // (que en la demo es «admin») descargarse hashes de contraseña y el
        // token Kobo cifrado; el import machacaría la demo entera.
        'admin/db/export'          => ['GET'],
        'admin/db/import'          => ['POST'],
        // Edición de envíos: escribe en la cuenta Kobo real.
        'submissions/:id'          => ['PUT'],
        // Sync manual contra Kobo (los cron del servidor siguen activos).
        'admin/forms/sync'         => ['POST'],
        'admin/forms/:id/sync'     => ['POST'],
        'forms/:id/sync'           => ['POST'],
        // Bandeja de mensajes de contacto: la demo pública puede recibir mensajes
        // REALES (alguien interesado en el proyecto escribe desde /apoyar); un
        // visitante anónimo no debe poder marcarlos ni borrarlos antes de que el
        // operador los vea. (El reset periódico también los borraría, pero al menos
        // no se pierden a manos de terceros dentro del ciclo.)
        'admin/messages/:id'       => ['PUT', 'DELETE'],
    ];

    /** ¿Bloquea el modo demo este patrón de ruta + método? */
    public static function blocks(string $pattern, string $method): bool {
        if (!self::enabled()) return false;
        $methods = self::BLOCKED[$pattern] ?? null;
        return $methods !== null && in_array(strtoupper($method), $methods, true);
    }
}
