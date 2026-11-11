<?php
/**
 * أداة تشغيل ترحيلات قاعدة البيانات عبر ملف الاتصال config/database.php
 * الاستخدام عبر CLI فقط: php database/migrate.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('هذا السكربت مخصص للتشغيل من سطر الأوامر (CLI) فقط.');
}

require_once __DIR__ . '/../config/database.php';

/**
 * تقسيم نص SQL إلى عبارات مستقلة مع احترام النصوص المحصورة والتعليقات.
 *
 * @return string[]
 */
function cerd_split_sql_statements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $len = strlen($sql);
    $inSingle = false;
    $inDouble = false;
    $inBacktick = false;
    $inLineComment = false;
    $inBlockComment = false;

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $next = ($i + 1 < $len) ? $sql[$i + 1] : '';

        if ($inLineComment) {
            if ($ch === "\n") {
                $inLineComment = false;
                $buffer .= $ch;
            }
            continue;
        }

        if ($inBlockComment) {
            if ($ch === '*' && $next === '/') {
                $inBlockComment = false;
                $i++;
            }
            continue;
        }

        if (!$inSingle && !$inDouble && !$inBacktick) {
            if (($ch === '-' && $next === '-' && ($i + 2 >= $len || ctype_space($sql[$i + 2]))) || $ch === '#') {
                $inLineComment = true;
                if ($ch === '-') {
                    $i++;
                }
                continue;
            }
            if ($ch === '/' && $next === '*') {
                $inBlockComment = true;
                $i++;
                continue;
            }
        }

        if ($ch === "'" && !$inDouble && !$inBacktick) {
            $escaped = ($i > 0 && $sql[$i - 1] === '\\');
            if (!$escaped) {
                $inSingle = !$inSingle;
            }
        } elseif ($ch === '"' && !$inSingle && !$inBacktick) {
            $escaped = ($i > 0 && $sql[$i - 1] === '\\');
            if (!$escaped) {
                $inDouble = !$inDouble;
            }
        } elseif ($ch === '`' && !$inSingle && !$inDouble) {
            $inBacktick = !$inBacktick;
        }

        if ($ch === ';' && !$inSingle && !$inDouble && !$inBacktick) {
            $trimmed = trim($buffer);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $ch;
    }

    $tail = trim($buffer);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

$migrationsDir = __DIR__ . '/migrations';
$files = glob($migrationsDir . '/*.sql');
if ($files === false || count($files) === 0) {
    fwrite(STDERR, "لم يتم العثور على ملفات ترحيل في: {$migrationsDir}\n");
    exit(1);
}
sort($files, SORT_STRING);

$serverPdo = cerd_get_pdo(false);
$cfg = cerd_db_config();

foreach ($files as $filePath) {
    $fileName = basename($filePath);
    $sql = file_get_contents($filePath);
    if ($sql === false) {
        fwrite(STDERR, "تعذر قراءة الملف: {$filePath}\n");
        exit(1);
    }

    $statements = cerd_split_sql_statements($sql);
    echo "Applying migration: {$fileName} (" . count($statements) . " statements)...\n";

    foreach ($statements as $stmt) {
        $serverPdo->exec($stmt);
    }
}

$health = cerd_db_health_check();
echo "Migration completed successfully.\n";
echo "Database: {$health['database']} (MySQL {$health['version']})\n";
echo "Tables (" . count($health['tables']) . "): " . implode(', ', $health['tables']) . "\n";

