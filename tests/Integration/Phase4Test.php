<?php

namespace App\Tests\Integration;

use App\PublicProfile\OgImage;
use App\PublicProfile\PublicProfileService;
use App\Repository\ListRepository;
use App\Repository\SettingsRepository;
use App\Repository\TagRepository;
use InvalidArgumentException;
use MediaManager;

final class Phase4Test extends DatabaseTestCase
{
    private MediaManager $m;

    protected function setUp(): void
    {
        parent::setUp();
        self::$pdo->exec('TRUNCATE TABLE settings');
        self::$pdo->exec("INSERT INTO categories (id, name) VALUES (4, 'Manga')");
        $this->m = new MediaManager(self::$pdo);
    }

    public function testBatchSetStatusAdaptsWordingPerCategory(): void
    {
        $tv = $this->insertRaw(['title' => 'Show', 'category_id' => 1]);
        $manga = $this->insertRaw(['title' => 'Comic', 'category_id' => 4]);
        self::assertSame(2, $this->m->batch([$tv, $manga], 'set_status', 'Watched'));
        self::assertSame('Watched', $this->fetchRow($tv)['status']);
        self::assertSame('Read', $this->fetchRow($manga)['status']);

        $this->m->batch([$tv, $manga], 'set_status', null);
        self::assertNull($this->fetchRow($manga)['status']);
    }

    public function testBatchCategoryRatingDeleteRestore(): void
    {
        $a = $this->insertRaw(['title' => 'A']);
        $b = $this->insertRaw(['title' => 'B']);
        $this->m->batch([$a, $b], 'set_category', 2);
        $this->m->batch([$a, $b], 'set_rating', 2);
        self::assertSame([2, 2], [(int) $this->fetchRow($a)['category_id'], (int) $this->fetchRow($b)['rating_id']]);

        self::assertSame(2, $this->m->batch([$a, $b], 'delete'));
        self::assertSame(0, $this->m->count([]));
        self::assertSame(2, $this->m->batch([$a, $b], 'restore'));
        self::assertSame(2, $this->m->count([]));
    }

    public function testBatchValidation(): void
    {
        $a = $this->insertRaw(['title' => 'A']);
        foreach ([[[], 'delete', null], [[$a], 'explode', null], [[$a], 'set_category', 999], [[$a], 'set_status', 'Bingeing']] as [$ids, $op, $v]) {
            try {
                $this->m->batch($ids, $op, $v);
                self::fail("$op should be rejected");
            } catch (InvalidArgumentException) {
                self::assertSame(1, $this->m->count([]), 'nothing changed');
            }
        }
    }

    public function testListsCrudOrderingAndFilter(): void
    {
        $lists = new ListRepository(self::$pdo);
        $a = $lists->create('Halloween');
        $b = $lists->create('Halloween'); // slug de-duplicated
        self::assertSame(['halloween', 'halloween-2'], [$a['slug'], $b['slug']]);

        $x = $this->insertRaw(['title' => 'X']);
        $y = $this->insertRaw(['title' => 'Y']);
        $z = $this->insertRaw(['title' => 'Z']);
        self::assertSame(3, $lists->addItems($a['id'], [$x, $y, $z]));
        self::assertSame(0, $lists->addItems($a['id'], [$x]), 'already present');
        $lists->reorderItems($a['id'], [$z, $x, $y]);
        self::assertSame(['Z', 'X', 'Y'], array_column($this->m->search(['list_id' => $a['id']], 10, 0, 'list_position'), 'title'));

        $lists->reorder([$b['id'], $a['id']]);
        self::assertSame([$b['id'], $a['id']], array_map(fn ($l) => (int) $l['id'], $lists->all()));

        $lists->update($a['id'], ['name' => 'Spooky', 'is_public' => true]);
        self::assertSame(['Spooky', true, 3], [$lists->find($a['id'])['name'], $lists->find($a['id'])['is_public'], $lists->find($a['id'])['media_count']]);

        $lists->removeItem($a['id'], $x);
        self::assertSame(2, $lists->find($a['id'])['media_count']);
        self::assertTrue($lists->delete($a['id']));
        self::assertSame(3, $this->m->count([]), 'deleting a list keeps its media');
    }

    public function testTagsAutocompleteAndFilter(): void
    {
        $tags = new TagRepository(self::$pdo);
        $a = $this->insertRaw(['title' => 'A']);
        $b = $this->insertRaw(['title' => 'B']);
        $tags->setForMedia($a, ['Comfy', 'rewatch-worthy']);
        $tags->addToMedia([$a, $b], ['comfy']); // same slug → same tag
        self::assertSame('Comfy', $tags->search('com')[0]['name']);
        self::assertSame(2, (int) $tags->search('com')[0]['media_count']);
        $comfyId = $tags->search('com')[0]['id'];
        self::assertSame(2, $this->m->count(['tag_ids' => [$comfyId]]));
        self::assertSame(['Comfy', 'rewatch-worthy'], array_column($tags->setForMedia($a, ['Comfy', 'rewatch-worthy']), 'name'));
        $this->expectException(InvalidArgumentException::class);
        $tags->idFor(str_repeat('x', 65));
    }

    public function testDiaryAndFinishedYearFilter(): void
    {
        $a = $this->insertRaw(['title' => 'Old', 'status' => 'Watched', 'finished_at' => '2025-03-01']);
        $b = $this->insertRaw(['title' => 'New', 'status' => 'Watched', 'finished_at' => '2026-02-10']);
        $this->m->logRewatch($a, null, '2026-05-05', 'again!');

        $d = $this->m->diary(2026);
        self::assertSame([['rewatch', 'Old', '2026-05-05', 'again!'], ['finish', 'New', '2026-02-10', null]],
            array_map(fn ($e) => [$e['kind'], $e['title'], $e['entry_date'], $e['notes']], $d['entries']));
        self::assertSame([2026, 2025], $d['years']);
        self::assertCount(3, $this->m->diary('all')['entries']);
        self::assertSame(2, $this->m->count(['finished_year' => 2026]), 'rewatch counts as finished that year');
        self::assertSame(1, (int) $this->fetchRow($a)['rewatch_count']);
    }

    public function testSettingsValidation(): void
    {
        $s = new SettingsRepository(self::$pdo);
        self::assertFalse($s->all()['public_enabled'], 'off by default');
        try {
            $s->save(['public_enabled' => true]);
            self::fail('username required');
        } catch (InvalidArgumentException) {
        }
        try {
            $s->save(['username' => 'bad name!']);
            self::fail('invalid username');
        } catch (InvalidArgumentException) {
        }
        $saved = $s->save(['public_enabled' => 'true', 'username' => 'me_99']);
        self::assertTrue($saved['public_enabled']);
        self::assertSame('me_99', $s->all()['username']);
    }

    private function profileService(): PublicProfileService
    {
        return new PublicProfileService(self::$pdo, $this->m, new SettingsRepository(self::$pdo), new ListRepository(self::$pdo));
    }

    public function testPublicProfileIsOptInAndHidesNotesAndAdult(): void
    {
        $safe  = $this->insertRaw(['title' => 'Safe favourite', 'status' => 'Watched', 'score' => 9, 'infos' => 'secret note']);
        $adult = $this->insertRaw(['title' => 'Adult favourite', 'status' => 'Watched', 'score' => 10, 'rating_id' => 2]);
        $priv  = $this->insertRaw(['title' => 'In private list', 'status' => 'Plan to Watch']);
        $lists = new ListRepository(self::$pdo);
        $public = $lists->create('Public picks', null, true);
        $private = $lists->create('Private picks');
        $lists->addItems($public['id'], [$safe, $adult]);
        $lists->addItems($private['id'], [$priv]);

        self::assertNull($this->profileService()->build('me'), 'disabled by default');
        (new SettingsRepository(self::$pdo))->save(['public_enabled' => true, 'username' => 'Me_1']);
        self::assertNull($this->profileService()->build('someone'), 'wrong username');

        $p = $this->profileService()->build('me_1'); // case-insensitive
        $json = json_encode($p);
        self::assertSame(['Safe favourite'], array_column($p['top'], 'title'));
        self::assertStringNotContainsString('secret note', $json);
        self::assertStringNotContainsString('Adult favourite', $json);
        self::assertStringNotContainsString('Private picks', $json);
        self::assertStringNotContainsString('In private list', $json);
        self::assertSame(['Public picks'], array_column($p['lists'], 'name'));
        self::assertSame(2, $p['stats']['in_list'], 'adult item excluded from counts too');
        self::assertSame([$safe], $this->profileService()->posterIds($p));

        (new SettingsRepository(self::$pdo))->save(['public_show_notes' => true, 'public_show_adult' => true]);
        $p = $this->profileService()->build('Me_1');
        self::assertSame(['Adult favourite', 'Safe favourite'], array_column($p['top'], 'title'));
        self::assertSame('secret note', $p['top'][1]['notes']);
    }

    public function testOgImageRendersPng(): void
    {
        $poster = $this->varDir . '/p.jpg';
        file_put_contents($poster, \App\Tests\Support\FakeHttpClient::jpeg());
        $png = (new OgImage())->render('Tester', ['in_list' => 12, 'by_group' => ['done' => 5], 'my_avg' => 7.25], [$poster, $poster]);
        $info = getimagesizefromstring($png);
        self::assertSame([1200, 630, IMAGETYPE_PNG], [$info[0], $info[1], $info[2]]);

        // Fallback path (no TTF font available) must work too
        $png = (new OgImage('/nonexistent.ttf'))->render('Tester', ['in_list' => 0, 'by_group' => [], 'my_avg' => null], []);
        self::assertSame(IMAGETYPE_PNG, getimagesizefromstring($png)[2]);
    }
}
