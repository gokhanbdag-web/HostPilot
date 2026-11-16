<?php
declare(strict_types=1);
namespace HostPilot\Core;
use PDO;
use RuntimeException;
use Throwable;

final class DatabaseBackup
{
    private const MAX_RESTORE_BYTES = 2147483648;

    /** @return array{path:string,filename:string,size:int,sha256:string} */
    public static function create(PDO $db, string $storageDirectory): array
    {
        $storage = realpath($storageDirectory);
        if ($storage === false || !is_dir($storage) || !is_writable($storage)) throw new RuntimeException('Private storage directory is unavailable or not writable.');
        if (!function_exists('gzopen') || !function_exists('gzwrite')) throw new RuntimeException('PHP zlib support is required to create a compressed database backup.');
        $backupDirectory = $storage . DIRECTORY_SEPARATOR . 'backups';
        if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0750, true) && !is_dir($backupDirectory)) throw new RuntimeException('Unable to create private backup directory.');
        @chmod($backupDirectory, 0750);
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
        @chmod($path, 0640);
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
        if ($size === false || $size < 32 || $hash === false || !self::isValidGzip($path)) { @unlink($path); throw new RuntimeException('Database backup verification failed.'); }
        return ['path' => $path, 'filename' => $filename, 'size' => $size, 'sha256' => $hash];
    }

    /** @return list<array{filename:string,size:int,sha256:string,created_at:string,valid:bool}> */
    public static function listBackups(string $storageDirectory): array
    {
        $storage = realpath($storageDirectory);
        if ($storage === false || !is_dir($storage)) return [];
        $directory = realpath($storage . DIRECTORY_SEPARATOR . 'backups');
        if ($directory === false || !is_dir($directory)) return [];
        $items = [];
        foreach (glob($directory . DIRECTORY_SEPARATOR . 'hostpilot-db-*.sql.gz') ?: [] as $path) {
            $filename = basename($path);
            if (!preg_match('/^hostpilot-db-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}\.sql\.gz$/D', $filename)) continue;
            $size = filesize($path);
            $hash = hash_file('sha256', $path);
            $items[] = [
                'filename' => $filename,
                'size' => $size === false ? 0 : (int)$size,
                'sha256' => $hash === false ? '' : $hash,
                'created_at' => gmdate(DATE_ATOM, (int)(filemtime($path) ?: 0)),
                'valid' => is_file($path) && is_readable($path) && $size !== false && $size >= 32 && $hash !== false && self::isValidGzip($path),
            ];
        }
        usort($items, static fn(array $a,array $b):int => strcmp($b['filename'],$a['filename']));
        return $items;
    }

    /**
     * Restore only a HostPilot-generated compressed SQL backup from the private backup directory.
     * The caller must create an emergency backup and enable maintenance mode before invoking this method.
     * @return array{filename:string,sha256:string,statements:int}
     */
    public static function restore(PDO $db,string $storageDirectory,string $filename,string $expectedSha256):array
    {
        if (!preg_match('/^hostpilot-db-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}\.sql\.gz$/D',$filename)) throw new RuntimeException('Invalid backup filename.');
        if (!preg_match('/^[a-f0-9]{64}$/iD',$expectedSha256)) throw new RuntimeException('Invalid backup integrity hash.');
        $storage=realpath($storageDirectory);
        $directory=$storage===false?false:realpath($storage.DIRECTORY_SEPARATOR.'backups');
        $path=$directory===false?false:realpath($directory.DIRECTORY_SEPARATOR.$filename);
        if ($directory===false||$path===false||dirname($path)!==$directory||!is_file($path)||!is_readable($path)) throw new RuntimeException('Selected backup is not available in private storage.');
        $actualHash=hash_file('sha256',$path);
        if ($actualHash===false||!hash_equals(strtolower($expectedSha256),strtolower($actualHash))) throw new RuntimeException('Backup integrity hash does not match.');
        $temporary=tmpfile();
        if (!is_resource($temporary)) throw new RuntimeException('Unable to allocate a temporary restore stream.');
        $compressed=gzopen($path,'rb');
        if ($compressed===false) { fclose($temporary); throw new RuntimeException('Backup is not a valid gzip stream.'); }
        $expandedBytes=0;
        try {
            while (!gzeof($compressed)) {
                $chunk=gzread($compressed,65536);
                if ($chunk===false) throw new RuntimeException('Compressed backup could not be read.');
                if ($chunk==='') break;
                $expandedBytes+=strlen($chunk);
                if ($expandedBytes>self::MAX_RESTORE_BYTES) throw new RuntimeException('Backup exceeds the 2 GiB restore safety limit.');
                $offset=0;$length=strlen($chunk);
                while($offset<$length){$written=fwrite($temporary,substr($chunk,$offset));if($written===false||$written===0)throw new RuntimeException('Unable to stage decompressed backup.');$offset+=$written;}
            }
            if (!gzclose($compressed)) throw new RuntimeException('Compressed backup integrity check failed.');
            $compressed=null;
            rewind($temporary);
            $db->exec('SET FOREIGN_KEY_CHECKS=0');
            $statementCount=self::executeDump($db,$temporary);
            return ['filename'=>$filename,'sha256'=>$actualHash,'statements'=>$statementCount];
        } finally {
            if (is_resource($compressed)) @gzclose($compressed);
            try { $db->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable) {}
            fclose($temporary);
        }
    }

    private static function executeDump(PDO $db,$stream):int
    {
        $statement='';$quote=null;$escaped=false;$count=0;
        while(!feof($stream)){
            $chunk=fread($stream,65536);
            if($chunk===false)throw new RuntimeException('Unable to read staged backup SQL.');
            if($chunk==='')break;
            $length=strlen($chunk);
            for($i=0;$i<$length;$i++){
                $char=$chunk[$i];
                if($quote!==null){
                    $statement.=$char;
                    if($escaped){$escaped=false;continue;}
                    if($char==='\\'&&$quote!=='`'){$escaped=true;continue;}
                    if($char===$quote){
                        if(isset($chunk[$i+1])&&$chunk[$i+1]===$quote){$statement.=$chunk[++$i];continue;}
                        $quote=null;
                    }
                    continue;
                }
                if(trim($statement)===''&&$char==='-'&&isset($chunk[$i+1])&&$chunk[$i+1]==='-'){
                    while($i<$length&&$chunk[$i]!=="\n")$i++;
                    continue;
                }
                if($char==="'"||$char==='"'||$char==='`'){$quote=$char;$statement.=$char;continue;}
                if($char===';'){
                    $sql=trim($statement);$statement='';
                    if($sql==='')continue;
                    self::executeAllowedStatement($db,$sql);$count++;
                    continue;
                }
                $statement.=$char;
            }
        }
        if($quote!==null||trim($statement)!=='')throw new RuntimeException('Backup SQL ended with an incomplete statement.');
        if($count<3)throw new RuntimeException('Backup SQL contains too few statements.');
        return $count;
    }

    private static function executeAllowedStatement(PDO $db,string $sql):void
    {
        $allowed=preg_match('/^SET\s+NAMES\s+utf8mb4$/iD',$sql)
            ||preg_match('/^SET\s+FOREIGN_KEY_CHECKS\s*=\s*[01]$/iD',$sql)
            ||preg_match('/^DROP\s+TABLE\s+IF\s+EXISTS\s+`(?:``|[^`])+`$/iD',$sql)
            ||preg_match('/^CREATE\s+TABLE\s+`(?:``|[^`])+`\s*\(/is',$sql)
            ||preg_match('/^INSERT\s+INTO\s+`(?:``|[^`])+`\s*\([^;]+\)\s+VALUES\s*\(/is',$sql);
        if(!$allowed)throw new RuntimeException('Backup contains a SQL statement outside the supported restore format.');
        $db->exec($sql);
    }

    private static function isValidGzip(string $path):bool
    {
        $handle=@gzopen($path,'rb');if($handle===false)return false;
        $valid=true;
        try{
            while(!gzeof($handle)){
                $chunk=gzread($handle,65536);
                if($chunk===false){$valid=false;break;}
                if($chunk==='')break;
            }
        }catch(Throwable){$valid=false;}
        if(!@gzclose($handle))$valid=false;
        return $valid;
    }

    private static function write($handle,string $content):void
    {
        if(gzwrite($handle,$content)!==strlen($content))throw new RuntimeException('Unable to write the complete database backup.');
    }

    private static function quoteIdentifier(string $identifier):string
    {
        $tick=chr(96);
        return $tick.str_replace($tick,$tick.$tick,$identifier).$tick;
    }
}
