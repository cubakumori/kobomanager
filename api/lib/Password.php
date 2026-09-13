<?php
/**
 * Política de contraseñas de los usuarios de la app (fuente única: la usan el
 * alta/edición de usuarios por admin, el cambio propio, la recuperación por
 * email y los CLI create_user/install).
 *
 * Criterio deliberadamente sencillo y sin dependencias:
 *   - longitud mínima (MIN_LENGTH);
 *   - no estar en la lista corta de contraseñas más comunes (comparación
 *     normalizada: minúsculas y sin los dígitos/símbolos de relleno finales, así
 *     «Password123!» cae como «password»);
 *   - no ser trivial (un solo carácter repetido o una secuencia de teclado/dígitos);
 *   - no contener datos personales (la parte local del email o el nombre, si
 *     tienen al menos 4 caracteres).
 *
 * `weakness()` devuelve null si la contraseña es aceptable o un código de motivo
 * ('short'|'common'|'trivial'|'personal') que ErrorResponse traduce a un mensaje.
 */
class Password {
    public const MIN_LENGTH = 8;

    /** Las contraseñas más usadas según los volcados públicos (forma normalizada). */
    private const COMMON = [
        'password', 'passwort', 'contrasena', 'contraseña', 'senha', 'qwerty', 'qwertyuiop', 'asdfgh',
        'asdfghjkl', 'zxcvbnm', 'letmein', 'welcome', 'admin', 'administrator', 'root', 'login', 'master',
        'monkey', 'dragon', 'football', 'baseball', 'soccer', 'iloveyou', 'sunshine', 'princess', 'shadow',
        'superman', 'batman', 'trustno', 'whatever', 'starwars', 'freedom', 'hello', 'charlie', 'michael',
        'jennifer', 'jordan', 'hunter', 'thomas', 'ranger', 'buster', 'killer', 'george', 'andrew', 'daniel',
        'harley', 'summer', 'ashley', 'nicole', 'chelsea', 'biteme', 'matthew', 'access', 'mustang', 'flower',
        'passw0rd', 'p@ssword', 'p@ssw0rd', 'secret', 'changeme', 'default', 'guest', 'test', 'testing',
        'temp', 'temporal', 'usuario', 'user', 'abc', 'abcd', 'abcdef', 'querty', 'kobo', 'kobotoolbox',
        'kobomanager', 'encuesta', 'survey',
    ];

    /** Secuencias de teclado/dígitos que, solas o repetidas, no protegen nada. */
    private const SEQUENCES = ['0123456789', '1234567890', '9876543210', 'abcdefghijklmnopqrstuvwxyz',
        'qwertyuiop', 'asdfghjkl', 'zxcvbnm'];

    /**
     * Motivo de debilidad de la contraseña, o null si es aceptable.
     *
     * @param string[] $personal Datos del usuario que no deben aparecer en la
     *                           contraseña (email, nombre); pueden ir vacíos.
     */
    public static function weakness(string $password, array $personal = []): ?string {
        if (strlen($password) < self::MIN_LENGTH) {
            return 'short';
        }
        $lower = self::fold($password);
        $core  = rtrim($lower, "0123456789!@#\$%^&*()_+-=.,;:?¡¿");
        if (in_array($lower, self::COMMON, true) || ($core !== '' && in_array($core, self::COMMON, true))) {
            return 'common';
        }
        if (self::isTrivial($lower)) {
            return 'trivial';
        }
        foreach (self::personalTokens($personal) as $tok) {
            if (str_contains($lower, $tok)) {
                return 'personal';
            }
        }
        return null;
    }

    /** Mensaje (en español, como el resto de la API) para un motivo de weakness(). */
    public static function message(string $reason): string {
        return match ($reason) {
            'short'    => 'La contraseña debe tener al menos ' . self::MIN_LENGTH . ' caracteres',
            'common'   => 'Esa contraseña está entre las más usadas; elige otra',
            'trivial'  => 'La contraseña es una secuencia o repetición trivial; elige otra',
            'personal' => 'La contraseña no debe contener tu email ni tu nombre',
            default    => 'La contraseña no es suficientemente segura',
        };
    }

    /** Comprueba la política y corta la petición con PASSWORD_WEAK si no la cumple. */
    public static function enforce(string $password, array $personal = []): void {
        $reason = self::weakness($password, $personal);
        if ($reason !== null) {
            ErrorResponse::send('PASSWORD_WEAK', self::message($reason));
        }
    }

    /** Un solo carácter repetido, o una secuencia (o tramo de secuencia) posiblemente repetida. */
    private static function isTrivial(string $lower): bool {
        if (preg_match('/^(.)\1+$/u', $lower)) {
            return true;
        }
        // Reducir repeticiones «12341234» → «1234» y comprobar si es tramo de una secuencia.
        $unit = $lower;
        for ($len = 1; $len <= intdiv(strlen($lower), 2); $len++) {
            if (strlen($lower) % $len === 0 && str_repeat(substr($lower, 0, $len), intdiv(strlen($lower), $len)) === $lower) {
                $unit = substr($lower, 0, $len);
                break;
            }
        }
        if (strlen($unit) < 3) {
            return true; // «ababab», «121212»
        }
        foreach (self::SEQUENCES as $seq) {
            if (str_contains($seq, $unit) || str_contains(strrev($seq), $unit)) {
                return true;
            }
        }
        return false;
    }

    /** Minúsculas sin diacríticos latinos comunes (para comparar «Pérez» con «perez»). */
    private static function fold(string $s): string {
        $s = mb_strtolower($s, 'UTF-8');
        return strtr($s, ['á'=>'a','à'=>'a','ä'=>'a','â'=>'a','ã'=>'a','é'=>'e','è'=>'e','ë'=>'e','ê'=>'e',
            'í'=>'i','ì'=>'i','ï'=>'i','î'=>'i','ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o','õ'=>'o',
            'ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u','ñ'=>'n','ç'=>'c']);
    }

    /** Fragmentos personales comparables (≥ 4 caracteres, minúsculas): parte local del email y palabras del nombre. */
    private static function personalTokens(array $personal): array {
        $out = [];
        foreach ($personal as $p) {
            $p = self::fold(trim((string) $p));
            if ($p === '') continue;
            if (str_contains($p, '@')) {
                $p = substr($p, 0, strpos($p, '@'));
            }
            foreach (preg_split('/[\s._\-]+/u', $p, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
                if (mb_strlen($w, 'UTF-8') >= 4) $out[] = $w;
            }
            if (mb_strlen($p, 'UTF-8') >= 4) $out[] = $p;
        }
        return array_values(array_unique($out));
    }
}
