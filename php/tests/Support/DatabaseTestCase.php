<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    private string $dbPath;
    protected PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $tmp = tempnam(sys_get_temp_dir(), 'parser-inomarker-test-');
        if ($tmp === false) {
            throw new \RuntimeException('Unable to create temporary file for SQLite test database');
        }
        if (file_exists($tmp)) {
            unlink($tmp);
        }

        $this->dbPath = $tmp;
        $this->pdo = ConnectionFactory::createSqlite($this->dbPath);
        (new Migrator($this->pdo))->migrate();
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (isset($this->dbPath) && file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }

        parent::tearDown();
    }
}
