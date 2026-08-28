<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    private const SQL = <<<'SQL'
        -- A comment before anything.

        -- @up
        CREATE TABLE `books` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            PRIMARY KEY (`id`)
        );

        INSERT INTO `settings` (`key`, `value`) VALUES ('site.name', '"Library"');

        -- @down
        DROP TABLE IF EXISTS `books`;
        SQL;

    public function testSplitsTheUpSection(): void
    {
        $statements = Migrator::parse(self::SQL, 'up');

        $this->assertCount(2, $statements);
        $this->assertStringStartsWith('CREATE TABLE', trim($statements[0]));
        $this->assertStringContainsString('site.name', $statements[1]);
    }

    public function testReadsTheDownSection(): void
    {
        $statements = Migrator::parse(self::SQL, 'down');

        $this->assertCount(1, $statements);
        $this->assertStringContainsString('DROP TABLE', $statements[0]);
    }

    public function testMissingSectionIsEmpty(): void
    {
        $this->assertSame([], Migrator::parse("-- @up\nSELECT 1;", 'down'));
    }

    public function testCommentOnlyFragmentsAreDropped(): void
    {
        $this->assertSame([], Migrator::parse("-- @up\n-- just a note\n", 'up'));
    }

    public function testShippedMigrationsParseAndHaveARollback(): void
    {
        $files = glob(BASE_PATH . '/database/migrations/*.sql') ?: [];

        $this->assertNotEmpty($files, 'There should be at least one migration.');

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            $name = basename($file);

            $this->assertNotEmpty(Migrator::parse($contents, 'up'), "{$name} has no @up statements.");
            $this->assertNotEmpty(Migrator::parse($contents, 'down'), "{$name} has no @down statements.");
        }
    }
}
