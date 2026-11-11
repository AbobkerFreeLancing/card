<?php
/**
 * إعدادات واتصال قاعدة البيانات لنظام البطاقات الجامعية (cerd)
 * يستخدم PDO مع ترميز utf8mb4_unicode_ci المتوافق مع MySQL 8.0+ / Laragon
 */

declare(strict_types=1);

if (!function_exists('cerd_db_config')) {
    /**
     * إرجاع مصفوفة إعدادات الاتصال بقاعدة البيانات مع دعم المتغيرات البيئية
     * أو ملف الإعداد المحلي الاختياري config/database.local.php
     */
    function cerd_db_config(): array
    {
        $env = static function (string $primaryKey, string $fallbackKey, string $default): string {
            $val = getenv($primaryKey);
            if ($val !== false && $val !== '') {
                return $val;
            }
            $fallback = getenv($fallbackKey);
            if ($fallback !== false && $fallback !== '') {
                return $fallback;
            }
            return $default;
        };

        $config = [
            'host'      => $env('CERD_DB_HOST', 'DB_HOST', '127.0.0.1'),
            'port'      => (int) $env('CERD_DB_PORT', 'DB_PORT', '3306'),
            'database'  => $env('CERD_DB_NAME', 'DB_NAME', 'cerd_cards'),
            'username'  => $env('CERD_DB_USER', 'DB_USER', 'root'),
            'password'  => $env('CERD_DB_PASS', 'DB_PASS', ''),
            'charset'   => $env('CERD_DB_CHARSET', 'DB_CHARSET', 'utf8mb4'),
            'collation' => 'utf8mb4_unicode_ci',
        ];

        $localOverrideFile = __DIR__ . '/database.local.php';
        if (is_file($localOverrideFile)) {
            $localConfig = require $localOverrideFile;
            if (is_array($localConfig)) {
                $config = array_merge($config, $localConfig);
            }
        }

        return $config;
    }
}

if (!function_exists('cerd_get_pdo')) {
    /**
     * إنشاء أو استرجاع اتصال PDO مشترك.
     *
     * @param bool $withDatabase عند التمرير بـ false يتصل بالخادم مباشرة (مفيد لإنشاء قاعدة البيانات عند الترحيل)
     */
    function cerd_get_pdo(bool $withDatabase = true): PDO
    {
        static $connections = [];
        $cacheKey = $withDatabase ? 'with_db' : 'server_only';

        if (isset($connections[$cacheKey]) && $connections[$cacheKey] instanceof PDO) {
            return $connections[$cacheKey];
        }

        $cfg = cerd_db_config();
        $dsnParts = [
            'host=' . $cfg['host'],
            'port=' . (int) $cfg['port'],
            'charset=' . $cfg['charset'],
        ];
        if ($withDatabase && $cfg['database'] !== '') {
            $dsnParts[] = 'dbname=' . $cfg['database'];
        }

        $dsn = 'mysql:' . implode(';', $dsnParts);
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], $options);
            $pdo->exec("SET NAMES '{$cfg['charset']}' COLLATE '{$cfg['collation']}'");
            $connections[$cacheKey] = $pdo;
            return $pdo;
        } catch (PDOException $e) {
            throw new RuntimeException(
                'تعذر الاتصال بقاعدة البيانات MySQL (' . $cfg['host'] . ':' . $cfg['port'] . '): ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }
}

if (!function_exists('cerd_db_health_check')) {
    /**
     * فحص حالة الاتصال بقاعدة البيانات وإرجاع بيانات تشخيصية مختصرة.
     */
    function cerd_db_health_check(): array
    {
        $cfg = cerd_db_config();
        $pdo = cerd_get_pdo(true);
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

        return [
            'connected' => true,
            'database'  => $cfg['database'],
            'version'   => $version,
            'tables'    => $tables,
        ];
    }
}

