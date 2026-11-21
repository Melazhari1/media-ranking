<?php

namespace App\Tests\Support;

use App\Http\HttpClient;
use App\Http\HttpException;

/** Routes URLs (regex => response) and records every request. */
final class FakeHttpClient implements HttpClient
{
    public array $requests = [];

    /** @param array<string, array|callable|string> $routes regex => JSON array | callable(url) | raw bytes for getBytes */
    public function __construct(public array $routes = [])
    {
    }

    public function getJson(string $url, array $headers = []): array
    {
        $this->requests[] = $url;
        foreach ($this->routes as $pattern => $response) {
            if (preg_match($pattern, $url)) {
                $r = is_callable($response) ? $response($url) : $response;
                if ($r instanceof HttpException) {
                    throw $r;
                }
                return $r;
            }
        }
        throw new HttpException("No fake route for $url", 404);
    }

    public function getBytes(string $url, int $maxBytes): array
    {
        $this->requests[] = $url;
        foreach ($this->routes as $pattern => $response) {
            if (preg_match($pattern, $url)) {
                return ['body' => is_callable($response) ? $response($url) : $response, 'content_type' => 'image/jpeg'];
            }
        }
        throw new HttpException("No fake route for $url", 404);
    }

    /** A valid JPEG of the given size (generated with GD). */
    public static function jpeg(int $w = 300, int $h = 450): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 40, 80, 160));
        for ($i = 0; $i < 200; $i++) { // noise so the file isn't tiny
            imagesetpixel($img, random_int(0, $w - 1), random_int(0, $h - 1), imagecolorallocate($img, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        }
        ob_start();
        imagejpeg($img, null, 95);
        imagedestroy($img);
        return (string) ob_get_clean();
    }

    public static function jikanAnime(int $id, string $title, array $over = []): array
    {
        return $over + [
            'mal_id' => $id, 'title' => $title, 'title_english' => null, 'titles' => [['type' => 'Default', 'title' => $title]],
            'type' => 'TV', 'episodes' => 24, 'score' => 8.5, 'year' => 2020, 'aired' => ['from' => '2020-04-01T00:00:00+00:00'],
            'rating' => 'PG-13 - Teens 13 or older', 'synopsis' => "Synopsis of $title.\n\n[Written by MAL Rewrite]",
            'images' => ['jpg' => ['large_image_url' => "https://cdn.myanimelist.net/images/anime/1/$id.jpg"]],
            'genres' => [['name' => 'Action'], ['name' => 'Drama']], 'themes' => [['name' => 'Military']], 'demographics' => [],
        ];
    }
}
