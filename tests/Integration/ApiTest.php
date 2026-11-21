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
        $env  = ['MMR_VAR_DIR' => sys_get_temp_dir() . '/mmr-api-var-' . getmypid(), 'MMR_MEDIA_DIR' => sys_get_temp_dir() . '/mmr-api-media-' . getmypid()] + getenv();
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

    protected function setUp(): void
    {
        parent::setUp();
        // The DB is reset directly between tests, so drop the server's cached aggregates too.
        foreach (glob(sys_get_temp_dir() . '/mmr-api-var-' . getmypid() . '/cache/*/*.json') ?: [] as $f) {
            unlink($f);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    /** multipart/form-data POST with one file field. */
    private function upload(string $query, string $field, string $filename, string $content, array $fields = []): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, $content);
        $ch = curl_init('http://127.0.0.1:' . self::$port . '/api.php' . $query);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => $fields + [$field => new \CURLFile($tmp, 'application/octet-stream', $filename)]]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        unlink($tmp);
        return [$code, json_decode((string) $body, true), $body];
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

    public function testProgressAndComplete(): void
    {
        $id = $this->insertRaw(['title' => 'Tracked', 'status' => 'Plan to Watch', 'progress_total' => 2]);
        [$code, $res] = $this->request('POST', "?action=progress&id=$id", []);
        self::assertSame(200, $code);
        self::assertSame('Watching', $res['data']['status']);
        self::assertFalse($res['reached_total']);
        [, $res] = $this->request('POST', "?action=progress&id=$id", ['by' => 1]);
        self::assertTrue($res['reached_total']);
        [$code, $res] = $this->request('POST', "?action=complete&id=$id", []);
        self::assertSame(200, $code);
        self::assertSame('Watched', $res['data']['status']);
        self::assertSame(date('Y-m-d'), $res['data']['finished_at']);
        [$code] = $this->request('POST', "?action=progress&id=$id", ['by' => 0]);
        self::assertSame(400, $code);
    }

    public function testDetailStatsHomeRowsAndRecommendations(): void
    {
        $id = $this->insertRaw(['title' => 'Detail me', 'status' => 'Watched', 'score' => 9]);
        [$code, $res] = $this->request('GET', "?action=detail&id=$id");
        self::assertSame(200, $code);
        foreach (['genres', 'tags', 'lists', 'rewatch_log', 'synopsis'] as $k) {
            self::assertArrayHasKey($k, $res['data']);
        }
        self::assertArrayHasKey('related', $res);

        [$code, $res] = $this->request('GET', '?action=stats');
        self::assertSame(200, $code);
        self::assertSame(1, $res['data']['totals']['in_list']);

        [$code, $res] = $this->request('GET', '?action=home_rows');
        self::assertSame(200, $code);
        self::assertSame(['continue', 'recent', 'top_rated', 'random'], array_keys($res['data']));
        self::assertSame('Detail me', $res['data']['top_rated'][0]['title']);

        [$code] = $this->request('GET', '?action=recommendations');
        self::assertSame(200, $code);
    }

    public function testStatsCacheIsInvalidatedByWrites(): void
    {
        [, $res] = $this->request('GET', '?action=stats');
        self::assertSame(0, $res['data']['totals']['in_list']);
        $id = $this->insertRaw(['title' => 'Later']);
        $this->request('POST', "?action=update_status&id=$id", ['status' => 'Watched']);
        [, $res] = $this->request('GET', '?action=stats');
        self::assertSame(1, $res['data']['totals']['in_list']);
    }

    public function testRandomWithFilters(): void
    {
        $this->insertRaw(['title' => 'Only match', 'status' => 'Dropped']);
        $this->insertRaw(['title' => 'Other']);
        [, $res] = $this->request('GET', '?action=random&limit=1&statuses=Dropped');
        self::assertSame('Only match', $res['data'][0]['title']);
    }

    public function testExportCsvDownload(): void
    {
        $this->insertRaw(['title' => 'Exported', 'status' => 'Watched']);
        $this->insertRaw(['title' => 'Filtered out']);
        $ctx = stream_context_create(['http' => ['ignore_errors' => true]]);
        $body = file_get_contents('http://127.0.0.1:' . self::$port . '/api.php?action=export&format=csv&scope=filtered&statuses=Watched', false, $ctx);
        self::assertStringContainsString('attachment;', implode("\n", $http_response_header));
        self::assertStringContainsString('Exported', $body);
        self::assertStringNotContainsString('Filtered out', $body);
    }

    public function testImportPreviewThenCommit(): void
    {
        $csv = "Date,Name,Year,Letterboxd URI,Rating\n2024-01-01,Alien,1979,https://boxd.it/a,4.5\n";
        [$code, $res] = $this->upload('?action=import_preview', 'file', 'ratings.csv', $csv);
        self::assertSame(200, $code, json_encode($res));
        self::assertSame('letterboxd', $res['data']['format']);
        self::assertSame(1, $res['data']['summary']['create']);
        self::assertSame(0, (int) self::$pdo->query('SELECT COUNT(*) FROM media')->fetchColumn(), 'preview writes nothing');

        [$code, $res] = $this->request('POST', '?action=import_commit', ['token' => $res['data']['token']]);
        self::assertSame(200, $code);
        self::assertSame(1, $res['data']['created']);
        self::assertSame('Alien', self::$pdo->query('SELECT title FROM media')->fetchColumn());

        [$code] = $this->request('POST', '?action=import_commit', ['token' => str_repeat('a', 32)]);
        self::assertSame(400, $code, 'expired / unknown token');
    }

    public function testUploadRejectsNonImages(): void
    {
        [$code, $res] = $this->upload('', 'image_file', 'shell.php', '<?php echo "pwned";', ['title' => 'Evil']);
        self::assertSame(400, $code);
        self::assertStringContainsString('Only JPEG', $res['message']);
        self::assertSame(0, (int) self::$pdo->query('SELECT COUNT(*) FROM media')->fetchColumn());
    }

    public function testUnknownEndpointIs404(): void
    {
        [$code] = $this->request('GET', '?action=nope');
        self::assertSame(404, $code);
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
