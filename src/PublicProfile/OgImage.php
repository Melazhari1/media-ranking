<?php

namespace App\PublicProfile;

/**
 * 1200×630 Open Graph card: display name, headline counts and the top posters.
 * Uses a TrueType font when one is available (OG_FONT env or common system paths), otherwise
 * GD's built-in font scaled up, so it works on any host with GD.
 */
final class OgImage
{
    private const W = 1200;
    private const H = 630;
    private const FONTS = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        'C:/Windows/Fonts/arialbd.ttf',
        'C:/Windows/Fonts/segoeuib.ttf',
    ];

    private ?string $font;

    public function __construct(?string $font = null)
    {
        $this->font = null;
        foreach (array_filter([$font, getenv('OG_FONT') ?: null, ...self::FONTS]) as $f) {
            if (is_file($f) && function_exists('imagettftext')) {
                $this->font = $f;
                break;
            }
        }
    }

    /** @param string[] $posterPaths local image files, best first */
    public function render(string $name, array $stats, array $posterPaths): string
    {
        $img = imagecreatetruecolor(self::W, self::H);
        imagealphablending($img, true);

        // Background: vertical gradient from the app's dark palette
        for ($y = 0; $y < self::H; $y++) {
            $t = $y / self::H;
            $c = imagecolorallocate($img, (int) (10 + 13 * $t), (int) (13 + 15 * $t), (int) (21 + 19 * $t));
            imageline($img, 0, $y, self::W, $y, $c);
        }
        $white = imagecolorallocate($img, 238, 241, 250);
        $muted = imagecolorallocate($img, 137, 145, 168);
        $accent = imagecolorallocate($img, 62, 230, 255);

        // Posters on the right, slightly overlapping
        $posters = array_slice($posterPaths, 0, 5);
        $pw = 190;
        $ph = 285;
        $x0 = self::W - 60 - $pw - (count($posters) - 1) * 120;
        foreach ($posters as $i => $path) {
            $src = @imagecreatefromstring((string) @file_get_contents($path));
            if (!$src) {
                continue;
            }
            $x = $x0 + $i * 120;
            $y = 300 - (int) ($ph / 2) + ($i % 2 ? 30 : -10);
            $shadow = imagecolorallocatealpha($img, 0, 0, 0, 80);
            imagefilledrectangle($img, $x + 8, $y + 10, $x + $pw + 8, $y + $ph + 10, $shadow);
            imagecopyresampled($img, $src, $x, $y, 0, 0, $pw, $ph, imagesx($src), imagesy($src));
            imagerectangle($img, $x, $y, $x + $pw - 1, $y + $ph - 1, imagecolorallocate($img, 43, 51, 70));
            imagedestroy($src);
        }

        $this->text($img, 'MMR', 60, 90, 28, $accent);
        $this->text($img, mb_strimwidth($name, 0, 22, '…'), 60, 175, 54, $white);
        $this->text($img, 'My Media Ranking', 60, 225, 24, $muted);

        $lines = [
            [number_format($stats['in_list'] ?? 0), 'in my list'],
            [number_format($stats['by_group']['done'] ?? 0), 'finished'],
            [$stats['my_avg'] !== null ? number_format((float) $stats['my_avg'], 1) . ' / 10' : '—', 'average rating'],
        ];
        $y = 340;
        foreach ($lines as [$value, $label]) {
            $this->text($img, $value, 60, $y, 44, $white);
            $this->text($img, $label, 60 + $this->width($value, 44) + 16, $y, 24, $muted);
            $y += 75;
        }

        ob_start();
        imagepng($img, null, 6);
        imagedestroy($img);
        return (string) ob_get_clean();
    }

    private function text($img, string $text, int $x, int $baseline, int $size, int $color): void
    {
        if ($this->font) {
            imagettftext($img, $size, 0, $x, $baseline, $color, $this->font, $text);
            return;
        }
        // Fallback: render with built-in font 5 (9×15 px) and scale up.
        $scale = max(1, (int) round($size / 13));
        $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text) ?: $text;
        $w = imagefontwidth(5) * strlen($text);
        $h = imagefontheight(5);
        $tmp = imagecreatetruecolor(max(1, $w), $h);
        imagesavealpha($tmp, true);
        imagefill($tmp, 0, 0, imagecolorallocatealpha($tmp, 0, 0, 0, 127));
        imagestring($tmp, 5, 0, 0, $text, $color);
        imagecopyresized($img, $tmp, $x, $baseline - $h * $scale, 0, 0, $w * $scale, $h * $scale, $w, $h);
        imagedestroy($tmp);
    }

    private function width(string $text, int $size): int
    {
        if ($this->font) {
            $box = imagettfbbox($size, 0, $this->font, $text);
            return (int) abs($box[2] - $box[0]);
        }
        return imagefontwidth(5) * strlen($text) * max(1, (int) round($size / 13));
    }
}
