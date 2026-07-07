<?php
/**
 * Conexión PDO singleton a MySQL/MariaDB.
 */
class DB {
    private static ?PDO $pdo = null;

    public static function conn(): PDO {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                DB_HOST, DB_PORT, DB_NAME
            );
            self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$pdo;
    }

    /** Ejecuta una consulta preparada y devuelve el statement. */
    public static function run(string $sql, array $params = []): PDOStatement {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Ejecuta una consulta SIN búfer y entrega las filas una a una (generador).
     * Para pasadas grandes (stats, calidad, mapa, export) sobre formularios con
     * decenas de miles de envíos: la memoria queda en O(1 fila) en vez de
     * materializar todo el resultado con fetchAll.
     *
     * LIMITACIÓN de MySQL: mientras el cursor está abierto no puede ejecutarse
     * NINGUNA otra consulta en esta conexión — el bucle consumidor debe ser PHP
     * puro (sin DB::run dentro). El cursor se cierra y el modo con búfer se
     * restaura siempre, incluso si el consumidor abandona el bucle a medias.
     */
    public static function stream(string $sql, array $params = []): \Generator {
        $pdo  = self::conn();
        $attr = self::unbufferedAttr();
        $pdo->setAttribute($attr, false);
        $stmt = null;
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            while (($row = $stmt->fetch()) !== false) {
                yield $row;
            }
        } finally {
            if ($stmt !== null) {
                $stmt->closeCursor();
            }
            $pdo->setAttribute($attr, true);
        }
    }

    /** Atributo «consulta sin búfer»: PDO::MYSQL_ATTR_USE_BUFFERED_QUERY está
     *  deprecado desde PHP 8.5 en favor de Pdo\Mysql::ATTR_USE_BUFFERED_QUERY. */
    private static function unbufferedAttr(): int {
        return class_exists(\Pdo\Mysql::class)
            ? \Pdo\Mysql::ATTR_USE_BUFFERED_QUERY
            : PDO::MYSQL_ATTR_USE_BUFFERED_QUERY;
    }
}
