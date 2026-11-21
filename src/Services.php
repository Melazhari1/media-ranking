<?php

namespace App;

use App\Cache\FileCache;
use App\Http\CurlHttpClient;
use App\Http\HttpClient;
use App\Http\RateLimiter;
use App\Media\PosterStore;
use App\Metadata\JikanClient;
use App\Metadata\MetadataApplier;
use App\Metadata\MetadataService;
use App\Metadata\TmdbClient;
use App\Repository\GenreRepository;
use App\Repository\ListRepository;
use App\Repository\LookupRepository;
use App\Repository\SettingsRepository;
use App\Repository\TagRepository;
use Database;
use MediaManager;
use PDO;

/** Minimal lazy service container shared by api.php, bin/console and tests. */
final class Services
{
    private array $instances = [];

    public function __construct(
        private ?PDO $pdo = null,
        public readonly string $rootDir = __DIR__ . '/..',
        private ?HttpClient $http = null,
        /** @var callable(float):void|null */
        private $sleep = null,
    ) {
    }

    public static function env(string $key, ?string $default = null): ?string
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        return ($v === false || $v === null || $v === '') ? $default : (string) $v;
    }

    public function varDir(): string
    {
        return self::env('MMR_VAR_DIR', $this->rootDir . '/var');
    }

    public function mediaDir(): string
    {
        return self::env('MMR_MEDIA_DIR', $this->rootDir . '/medias');
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= Database::connect();
    }

    public function http(): HttpClient
    {
        return $this->http ??= new CurlHttpClient();
    }

    private function once(string $id, callable $make)
    {
        return $this->instances[$id] ??= $make();
    }

    public function media(): MediaManager
    {
        return $this->once('media', fn () => new MediaManager($this->pdo()));
    }

    public function lookups(): LookupRepository
    {
        return $this->once('lookups', fn () => new LookupRepository($this->pdo()));
    }

    public function genres(): GenreRepository
    {
        return $this->once('genres', fn () => new GenreRepository($this->pdo()));
    }

    public function tags(): TagRepository
    {
        return $this->once('tags', fn () => new TagRepository($this->pdo()));
    }

    public function lists(): ListRepository
    {
        return $this->once('lists', fn () => new ListRepository($this->pdo()));
    }

    public function settings(): SettingsRepository
    {
        return $this->once('settings', fn () => new SettingsRepository($this->pdo()));
    }

    public function cache(string $namespace): FileCache
    {
        return $this->once("cache:$namespace", fn () => new FileCache($this->varDir(), $namespace));
    }

    public function posters(): PosterStore
    {
        return $this->once('posters', fn () => new PosterStore($this->http(), $this->mediaDir()));
    }

    public function metadata(): MetadataService
    {
        return $this->once('metadata', fn () => new MetadataService(
            new JikanClient($this->http(), new RateLimiter($this->varDir(), 'jikan', [1 => 3, 60 => 60], $this->sleep)),
            new TmdbClient($this->http(), self::env('TMDB_API_KEY')),
            $this->cache('metadata'),
        ));
    }

    public function applier(): MetadataApplier
    {
        return $this->once('applier', fn () => new MetadataApplier($this->pdo(), $this->lookups(), $this->genres(), $this->posters()));
    }

    /** Call after any write that affects aggregated views (stats, home rows, recommendations). */
    public function invalidateAggregates(): void
    {
        $this->cache('stats')->clear();
        $this->cache('reco')->clear();
    }
}
