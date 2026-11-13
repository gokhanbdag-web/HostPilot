<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Core/MigrationRunner.php';

use HostPilot\Core\MigrationRunner;

function expectSame(array $expected, array $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL . 'Expected: ' . var_export($expected, true) . PHP_EOL . 'Actual: ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

$statements = MigrationRunner::splitStatements(<<<'SQL'
-- A comment with a semicolon; it must not split.
CREATE TABLE sample (id INT NOT NULL, label VARCHAR(40) DEFAULT 'a;b');
INSERT INTO sample (label) VALUES ("x;y"), ('it''s ok'); # trailing comment;
SQL);

expectSame([
    "CREATE TABLE sample (id INT NOT NULL, label VARCHAR(40) DEFAULT 'a;b')",
    "INSERT INTO sample (label) VALUES (\"x;y\"), ('it''s ok')",
], $statements, 'SQL statement splitting failed.');

try {
    MigrationRunner::splitStatements("SELECT 'unterminated;");
    fwrite(STDERR, "Unterminated SQL string was accepted." . PHP_EOL);
    exit(1);
} catch (RuntimeException) {
    // Expected.
}

echo "MigrationRunner parser tests passed." . PHP_EOL;
