<?php

namespace App\Http;

/**
 * Cross-process sliding-window limiter backed by a locked file in var/.
 * Jikan allows ~3 requests/second and 60/minute: new RateLimiter($dir, 'jikan', [1 => 3, 60 => 60]).
 */
final class RateLimiter
{
    /** @var callable(float):void */
    private $sleep;
    /** @var callable():float */
    private $clock;

    /**
     * @param array<int,int> $limits window seconds => max requests
     * @param callable(float):void|null $sleep injectable for tests
     * @param callable():float|null $clock injectable for tests
     */
    public function __construct(private string $varDir, private string $name, private array $limits, ?callable $sleep = null, ?callable $clock = null)
    {
        $this->sleep = $sleep ?? static fn (float $s) => usleep((int) ($s * 1_000_000));
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /** Blocks until a request is allowed, then records it. */
    public function acquire(): void
    {
        $dir = $this->varDir . '/ratelimit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $h = fopen("$dir/{$this->name}.json", 'c+');
        try {
            while (true) {
                flock($h, LOCK_EX);
                rewind($h);
                $stamps = json_decode((string) stream_get_contents($h), true) ?: [];
                $now = ($this->clock)();
                $maxWindow = max(array_keys($this->limits));
                $stamps = array_values(array_filter($stamps, fn ($t) => $t > $now - $maxWindow));

                $wait = 0.0;
                foreach ($this->limits as $window => $max) {
                    $inWindow = array_values(array_filter($stamps, fn ($t) => $t > $now - $window));
                    if (count($inWindow) >= $max) {
                        $wait = max($wait, $inWindow[count($inWindow) - $max] + $window - $now);
                    }
                }
                if ($wait <= 0) {
                    $stamps[] = $now;
                    ftruncate($h, 0);
                    rewind($h);
                    fwrite($h, json_encode($stamps));
                    fflush($h);
                    flock($h, LOCK_UN);
                    return;
                }
                flock($h, LOCK_UN);
                ($this->sleep)(min($wait + 0.01, 60));
            }
        } finally {
            fclose($h);
        }
    }
}
