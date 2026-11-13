<?php
declare(strict_types=1);

namespace HostPilot\Core;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Explicit, browser-safe runner for ordered SQL migration files.
 * It is intentionally not called by the front controller.
 */
final class MigrationRunner
{
    private const FILE_PATTERN = '/^([0-9]{8}_[0-9]{4}_[a-z0-9][a-z0-9_-]*)\.sql$/D';

    /** @return list<array{name:string,path:string,version:string}> */
    public static function discover(string $directory): array
    {
        $root = realpath($directory);
        if ($root === false || !is_dir($root) || !is_readable($root)) {
            throw new RuntimeException('Migration directory is missing or unreadable.');
        }

        $files = [];
        foreach (new \DirectoryIterator($root) as $entry) {
            if ($entry->isDot() || !$entry->isFile() || $entry->isLink()) continue;
            $name = $entry->getFilename();
            if (!preg_match(self::FILE_PATTERN, $name, $matches)) {
                if (str_ends_with(strtolower($name), '.sql')) {
                    throw new RuntimeException('Invalid migration filename: ' . $name);
                }
                continue;
            }
            $path = realpath($entry->getPathname());
            if ($path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Migration file resolves outside its directory.');
            }
            $files[] = ['name' => substr($name, 0, -4), 'path' => $path, 'version' => $matches[1]];
        }

        usort($files, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
        $seen = [];
        foreach ($files as $file) {
            if (isset($seen[$file['version']])) throw new RuntimeException('Duplicate migration version: ' . $file['version']);
            $seen[$file['version']] = true;
        }
        return $files;
    }

    /** @return list<string> */
    public static function pending(PDO $db, string $directory): array
    {
        self::assertTrackingTable($db);
        $applied = $db->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        $appliedSet = array_fill_keys(array_map('strval', $applied), true);
        $pending = [];
        foreach (self::discover($directory) as $file) {
            if (!isset($appliedSet[$file['name']])) $pending[] = $file['name'];
        }
        return $pending;
    }

    /**
     * Call only after authenticated admin confirmation and backup/maintenance checks.
     * SQL files must be idempotent: MySQL DDL may implicitly commit.
     *
     * @return list<string>
     */
    public static function runPending(PDO $db, string $directory, bool $confirmed = false): array
    {
        if (!$confirmed) throw new RuntimeException('Explicit administrator confirmation is required.');
        self::assertTrackingTable($db);
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('SQL migrations currently require MySQL or MariaDB.');
        }

        $database = (string)$db->query('SELECT DATABASE()')->fetchColumn();
        $lockName = 'hostpilot_migrations_' . substr(hash('sha256', $database), 0, 32);
        $lock = $db->prepare('SELECT GET_LOCK(:lock_name, 10)');
        $lock->execute([':lock_name' => $lockName]);
        if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Another database migration is already running.');

        $appliedNow = [];
        try {
            $applied = $db->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
            $appliedSet = array_fill_keys(array_map('strval', $applied), true);
            $batch = (int)$db->query('SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations')->fetchColumn();

            foreach (self::discover($directory) as $file) {
                if (isset($appliedSet[$file['name']])) continue;
                $sql = file_get_contents($file['path']);
                if ($sql === false || trim($sql) === '') throw new RuntimeException('Migration file is empty or unreadable: ' . $file['name']);
                foreach (self::splitStatements($sql) as $statement) $db->exec($statement);

                $record = $db->prepare('INSERT INTO migrations (migration, batch, applied_at) VALUES (:name, :batch, :applied_at)');
                $record->execute([
                    ':name' => $file['name'],
                    ':batch' => $batch,
                    ':applied_at' => gmdate('Y-m-d H:i:s'),
                ]);
                $appliedNow[] = $file['name'];
            }
        } finally {
            $release = $db->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute([':lock_name' => $lockName]);
        }
        return $appliedNow;
    }

    /** @return list<string> */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $quote = null;
        $lineComment = false;
        $blockComment = false;
        $length = strlen($sql);
        $backtick = chr(96);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($lineComment) {
                if ($char === "\n") { $lineComment = false; $buffer .= "\n"; }
                continue;
            }
            if ($blockComment) {
                if ($char === '*' && $next === '/') { $blockComment = false; $i++; $buffer .= ' '; }
                continue;
            }
            if ($quote !== null) {
                $buffer .= $char;
                if ($char === '\\' && $quote !== $backtick && $i + 1 < $length) {
                    $buffer .= $sql[++$i];
                    continue;
                }
                if ($char === $quote) {
                    if ($next === $quote) $buffer .= $sql[++$i];
                    else $quote = null;
                }
                continue;
            }

            if ($char === '/' && $next === '*') { $blockComment = true; $i++; continue; }
            if ($char === '#' || ($char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2])))) {
                $lineComment = true;
                if ($char === '-') $i++;
                continue;
            }
            if ($char === "'" || $char === '"' || $char === $backtick) {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === ';') {
                if (trim($buffer) !== '') $statements[] = trim($buffer);
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }

        if ($blockComment || $quote !== null) throw new RuntimeException('Unterminated SQL comment or quoted value in migration.');
        if (trim($buffer) !== '') $statements[] = trim($buffer);
        return $statements;
    }

    private static function assertTrackingTable(PDO $db): void
    {
        try {
            $db->query('SELECT migration, batch, applied_at FROM migrations LIMIT 0');
        } catch (Throwable $exception) {
            throw new RuntimeException('The base migrations tracking table is not installed.', 0, $exception);
        }
    }
}
