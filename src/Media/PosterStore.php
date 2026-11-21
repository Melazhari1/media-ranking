<?php

namespace App\Media;

use App\Http\HttpClient;
use App\Http\HttpException;
use InvalidArgumentException;

/**
 * Downloads posters from known metadata CDNs into medias/ and checks image quality.
 * Only allow-listed hosts are fetched, so the API can't be used to fetch arbitrary URLs (SSRF).
 */
final class PosterStore
{
    public const ALLOWED_HOSTS = ['cdn.myanimelist.net', 'myanimelist.cdn-dena.com', 'image.tmdb.org'];
    private const MAX_BYTES = 5_000_000;
    private const TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

    public function __construct(private HttpClient $http, private string $mediaDir)
    {
    }

    public static function isAllowedUrl(string $url): bool
    {
        return parse_url($url, PHP_URL_SCHEME) === 'https'
            && in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), self::ALLOWED_HOSTS, true);
    }

    /** @return string saved file name (relative to medias/) */
    public function download(string $url): string
    {
        if (!self::isAllowedUrl($url)) {
            throw new InvalidArgumentException('Poster URL host is not allowed');
        }
        $res  = $this->http->getBytes($url, self::MAX_BYTES);
        $info = @getimagesizefromstring($res['body']);
        if (!$info || !isset(self::TYPES[$info[2]])) {
            throw new HttpException('Downloaded file is not a JPEG/PNG/WebP image');
        }
        if (!is_dir($this->mediaDir)) {
            mkdir($this->mediaDir, 0775, true);
        }
        $name = bin2hex(random_bytes(10)) . '.' . self::TYPES[$info[2]];
        file_put_contents($this->mediaDir . '/' . $name, $res['body']);
        return $name;
    }

    /**
     * Why a stored poster should be replaced, or null if it looks fine.
     * Missing file, tiny file (< 4 KB), low resolution (< 150 px wide), or a watermarked stock source.
     */
    public function qualityProblem(?string $image): ?string
    {
        $image = (string) $image;
        if ($image === '') {
            return 'no poster';
        }
        if (stripos($image, 'alamy') !== false) {
            return 'watermarked (alamy)';
        }
        if (preg_match('#^https?://#i', $image)) {
            return null; // remote URL: not checked locally
        }
        $path = $this->mediaDir . '/' . basename($image);
        if (!is_file($path)) {
            return 'file missing';
        }
        if (filesize($path) < 4096) {
            return 'tiny file';
        }
        $info = @getimagesize($path);
        if (!$info) {
            return 'unreadable image';
        }
        if ($info[0] < 150) {
            return 'low resolution (' . $info[0] . 'px)';
        }
        return null;
    }
}
