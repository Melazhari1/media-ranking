<?php

namespace App\Tests\Integration;

/**
 * End-to-end tests of api.php through PHP's built-in web server, against the _test database.
 */
final class ApiTest extends DatabaseTestCase
{
    private static $server;
    private static int $port;

    public static function setUpBeforeClass(): void
    {
        self::$port = random_int(18000, 18999);
        $root = dirname(__DIR__, 2);
        $env  = getenv() + ['DB_NAME' => getenv('DB_NAME')];
        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, '-t', $root],
            [1 => ['file', sys_get_temp_dir() . '/mmr-test-server.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/mmr-test-server.log', 'a']],
            $pipes,
            $root,
            $env
        );
        // Wait for the server to accept connections.
        for ($i = 0; $i < 50; $i++) {
            if (@fsockopen('127.0.0.1', self::$port)) {
                return;
            }
            usleep(100_000);
        }
        self::fail('Built-in server did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    private function request(string $method, string $query, ?array $json = null): array
    {
        $ctx = stream_context_create(['http' => [
            'method'        => $method,
            'header'        => $json !== null ? "Content-Type: application/json\r\n" : '',
            'content'       => $json !== null ? json_encode($json) : '',
            'ignore_errors' => true,
        ]]);
        $body = file_get_contents('http://127.0.0.1:' . self::$port . '/api.php' . $query, false, $ctx);
        preg_match('#HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $m);
        return [(int) ($m[1] ?? 0), json_decode((string) $body, true)];
    }

    public function testCreateWithPublicScoreAndList(): void
    {
        [$code, $res] = $this->request('POST', '', [
            'title' => '𝐓𝐡𝐞 𝐁𝐥𝐚𝐜𝐤 𝐏𝐡𝐨𝐧𝐞', 'category_id' => 2, 'public_score' => '7.4', 'score_source' => 'tmdb',
        ]);
        self::assertSame(200, $code);
        $id = (int) $res['id'];

        [, $item] = $this->request('GET', "?id=$id");
        self::assertSame('The Black Phone', $item['data']['title']);
        self::assertEqualsWithDelta(7.4, (float) $item['data']['public_score'], 0.001);
        self::assertSame('tmdb', $item['data']['score_source']);
    }

    public function testLegacyScoreMalFieldStillAccepted(): void
    {
        [, $res] = $this->request('POST', '', ['title' => 'Legacy client', 'score_mal' => 6.7]);
        [, $item] = $this->request('GET', '?id=' . $res['id']);
        self::assertEqualsWithDelta(6.7, (float) $item['data']['public_score'], 0.001);
        self::assertSame('mal', $item['data']['score_source']);
    }

    public function testEmptyPublicScoreIsNull(): void
    {
        [, $res] = $this->request('POST', '', ['title' => 'No score', 'public_score' => '', 'score_source' => 'mal']);
        [, $item] = $this->request('GET', '?id=' . $res['id']);
        self::assertNull($item['data']['public_score']);
    }

    public function testDeleteAndRestore(): void
    {
        $id = $this->insertRaw(['title' => 'Undo me']);

        [$code, $res] = $this->request('DELETE', "?id=$id");
        self::assertSame(200, $code);
        self::assertSame('restore', $res['undo']);

        [$code] = $this->request('GET', "?id=$id");
        self::assertSame(404, $code);
        [, $list] = $this->request('GET', '?page=1');
        self::assertSame(0, $list['pagination']['total']);

        [$code] = $this->request('DELETE', "?id=$id");
        self::assertSame(404, $code, 'deleting twice reports not found');

        [$code] = $this->request('POST', "?action=restore&id=$id");
        self::assertSame(200, $code);
        [$code, $item] = $this->request('GET', "?id=$id");
        self::assertSame(200, $code);
        self::assertSame('Undo me', $item['data']['title']);

        [$code] = $this->request('POST', "?action=restore&id=$id");
        self::assertSame(404, $code);
    }

    public function testUpdateTracking(): void
    {
        $id = $this->insertRaw(['title' => 'Tracked', 'status' => 'Watching']);

        [$code, $res] = $this->request('POST', "?action=update_tracking&id=$id", ['progress_current' => 7, 'progress_total' => 24, 'progress_unit' => 'episode']);
        self::assertSame(200, $code);
        self::assertSame(7, (int) $res['data']['progress_current']);
        self::assertSame('episode', $res['data']['progress_unit']);

        [$code, $res] = $this->request('POST', "?action=update_tracking&id=$id", ['progress_current' => 30]);
        self::assertSame(400, $code);
        self::assertStringContainsString('exceed', $res['message']);

        [$code] = $this->request('POST', '?action=update_tracking&id=999999', ['progress_current' => 1]);
        self::assertSame(404, $code);
    }

    public function testUnknownStatusIs400NotSaved(): void
    {
        $id = $this->insertRaw(['title' => 'Strict']);
        [$code] = $this->request('POST', "?action=update_status&id=$id", ['status' => 'Binge']);
        self::assertSame(400, $code);
        self::assertNull($this->fetchRow($id)['status']);

        [$code] = $this->request('POST', "?action=update_status&id=$id", ['status' => 'On Hold']);
        self::assertSame(200, $code);
        self::assertSame('On Hold', $this->fetchRow($id)['status']);
    }

    public function testStatusesEndpoint(): void
    {
        [, $res] = $this->request('GET', '?action=statuses');
        self::assertContains('Rewatching', $res['data']);
        self::assertContains('Plan to Watch', $res['data']);
    }

    public function testUnknownFilterAndPublicScoreSort(): void
    {
        $this->insertRaw(['title' => 'Uncategorized', 'category_id' => null, 'score_mal' => 3]);
        $this->insertRaw(['title' => 'TV', 'category_id' => 1, 'score_mal' => 9]);

        [, $res] = $this->request('GET', '?category_ids=3');
        self::assertSame(['Uncategorized'], array_column($res['data'], 'title'));

        [, $res] = $this->request('GET', '?order_by=' . urlencode('m.public_score DESC'));
        self::assertSame(['TV', 'Uncategorized'], array_column($res['data'], 'title'));
    }
}
