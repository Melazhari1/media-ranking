<?php

namespace App\Tests\Integration;

use App\Console\Application;
use PDO;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** Every migration must be reversible: migrate down to 0 and back up without losing rows. */
final class MigrationsTest extends DatabaseTestCase
{
    private function migrate(string $version): void
    {
        $app = new Application();
        $app->setAutoExit(false);
        $out = new BufferedOutput();
        $code = $app->run(new ArrayInput(['command' => 'migrations:migrate', 'version' => $version, '--no-interaction' => true]), $out);
        self::assertSame(0, $code, $out->fetch());
    }

    private function columns(): array
    {
        return self::$pdo->query('SHOW COLUMNS FROM media')->fetchAll(PDO::FETCH_COLUMN);
    }

    public function testDownAndUpRoundTripKeepsData(): void
    {
        $this->insertRaw(['title' => 'Survivor', 'score_mal' => 6.5, 'status' => 'Watched']);
        $before = $this->columns();

        $this->migrate('first');
        self::assertNotContains('sort_title', $this->columns());
        self::assertSame('Survivor', self::$pdo->query('SELECT title FROM media')->fetchColumn());

        $this->migrate('latest');
        self::assertSame($before, $this->columns());
        self::assertSame(1, (int) self::$pdo->query('SELECT COUNT(*) FROM media')->fetchColumn());
    }
}
