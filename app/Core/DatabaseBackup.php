<?php
declare(strict_types=1);
namespace HostPilot\Core;
use PDO;
use RuntimeException;
use Throwable;

final class DatabaseBackup
{
    /** @return array{path:string,filename:string,size:int,sha256:string} */
    public static function create(PDO $db, string $storageDirectory): array
    {
        $storage = realpath($storageDirectory);
        if ($storage === false || !is_dir($storage) || !is_writable($storage)) throw new RuntimeException('Private storage directory is unavailable or not writable.');
        if (!function_exists('gzopen') || !function_exists('gzwrite')) throw new RuntimeException('PHP zlib support is required to create a compressed database backup.');
        $backupDirectory = $storage . DIRECTORY_SEPARATOR . 'backups';
        if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0750, true) && !is_dir($backupDirectory)) throw new RuntimeException('Unable to create private backup directory.');
        $denyFile = $backupDirectory . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($denyFile)) {
            $rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
            if (file_put_contents($denyFile, $rules, LOCK_EX) === false) throw new RuntimeException('Unable to protect backup directory from direct HTTP access.');
            @chmod($denyFile, 0640);
        }
        $filename = 'hostpilot-db-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sql.gz';
        $path = $backupDirectory . DIRECTORY_SEPARATOR . $filename;
        $handle = gzopen($path, 'wb9');
        if ($handle === false) throw new RuntimeException('Unable to open compressed database backup file.');
        try {
            self::write($handle, "-- HostPilot database backup\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
            $tablesStatement = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");
            $tables = $tablesStatement->fetchAll(PDO::FETCH_COLUMN);
            $tablesStatement->closeCursor();
            foreach ($tables as $tableName) {
                $table = (string)$tableName;
                $quotedTable = self::quoteIdentifier($table);
                $createStatement = $db->query('SHOW CREATE TABLE ' . $quotedTable);
                $createRow = $createStatement->fetch(PDO::FETCH_ASSOC);
                $createStatement->closeCursor();
                if (!is_array($createRow)) throw new RuntimeException('Unable to read table definition for ' . $table . '.');
                $createSql = (string)($createRow['Create Table'] ?? '');
                if ($createSql === '') throw new RuntimeException('Table definition is empty for ' . $table . '.');
                self::write($handle, "\nDROP TABLE IF EXISTS " . $quotedTable . ";\n" . $createSql . ";\n");
                $columnStatement = $db->query('SHOW COLUMNS FROM ' . $quotedTable);
                $columnRows = $columnStatement->fetchAll(PDO::FETCH_ASSOC);
                $columnStatement->closeCursor();
                $columns = array_map(static fn(array $column): string => self::quoteIdentifier((string)$column['Field']), $columnRows);
                if ($columns === []) continue;
                $dataStatement = $db->query('SELECT * FROM ' . $quotedTable);
                while (($row = $dataStatement->fetch(PDO::FETCH_NUM)) !== false) {
                    $values = [];
                    foreach ($row as $value) {
                        if ($value === null) $values[] = 'NULL';
                        else {
                            $quoted = $db->quote((string)$value);
                            if ($quoted === false) throw new RuntimeException('Unable to quote a database value while creating backup.');
                            $values[] = $quoted;
                        }
                    }
                    self::write($handle, 'INSERT INTO ' . $quotedTable . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ");\n");
                }
                $dataStatement->closeCursor();
            }
            self::write($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n");
            if (!gzclose($handle)) throw new RuntimeException('Unable to finalize compressed database backup.');
            $handle = null;
        } catch (Throwable $exception) {
            if (is_resource($handle)) @gzclose($handle);
            @unlink($path);
            throw $exception;
        }
        clearstatcache(true, $path);
        $size = filesize($path);
        $hash = hash_file('sha256', $path);
        if ($size === false || $size < 32 || $hash === false) { @unlink($path); throw new RuntimeException('Database backup verification failed.'); }
        return ['path' => $path, 'filename' => $filename, 'size' => $size, 'sha256' => $hash];
    }

    private static function write($handle, string $content): void
    {
        if (gzwrite($handle, $content) !== strlen($content)) throw new RuntimeException('Unable to write the complete database backup.');
    }

    private static function quoteIdentifier(string $identifier): string
    {
        $tick = chr(96);
        return $tick . str_replace($tick, $tick . $tick, $identifier) . $tick;
    }
}
