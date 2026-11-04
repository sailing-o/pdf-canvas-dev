<?php

declare(strict_types=1);

namespace SailingO\PdfCanvas;

/**
 * A raster image ready to be placed in a PDF (an image XObject). Independent of any document,
 * so the same instance can be reused. The library never reads files or URLs: pass the bytes.
 *
 *   JPEG                      embedded as is (DCTDecode; gray / RGB / CMYK)
 *   PNG (gray, RGB, palette)  embedded compressed as is (FlateDecode + PNG predictors), tRNS → color key mask
 *   PNG with alpha            decoded and split into color + soft mask (SMask)
 *   GIF / WebP, interlaced or 16-bit PNG, palette PNG with alpha
 *                             converted to an 8-bit PNG with ext-gd first (unsupported without GD)
 */
final class Image
{
    /** Pixel size. */
    public int $width = 0;
    public int $height = 0;
    public string $colorSpace = '/DeviceRGB';
    public int $bits = 8;
    public string $filter = '/FlateDecode';
    public ?string $decodeParms = null;
    public ?string $decode = null;
    public ?string $mask = null;
    public string $data = '';
    public ?Image $smask = null;

    private function __construct() {}

    /** @throws PdfException when the bytes are not a supported image */
    public static function fromBytes(string $bytes): self
    {
        return self::tryFromBytes($bytes) ?? throw new PdfException('Unsupported or broken image (JPEG and PNG are supported; GIF / WebP need ext-gd)');
    }

    /** Like fromBytes(), but returns null for unsupported images. */
    public static function tryFromBytes(string $bytes): ?self
    {
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) return self::png($bytes);
        if (str_starts_with($bytes, "\xFF\xD8")) return self::jpeg($bytes);
        if (str_starts_with($bytes, 'GIF8') || (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP')) {
            $png = self::viaGd($bytes);
            return $png !== null ? self::png($png, false) : null;
        }
        return null;
    }

    private static function jpeg(string $b): ?self
    {
        $len = strlen($b);
        $p = 2;
        $adobe = false;
        while ($p + 4 <= $len) {
            if (ord($b[$p]) !== 0xFF) { $p++; continue; }
            $m = ord($b[$p + 1]);
            if ($m === 0xD8 || $m === 0x01 || ($m >= 0xD0 && $m <= 0xD7)) { $p += 2; continue; }
            if ($m === 0xFF) { $p++; continue; }
            $seg = (ord($b[$p + 2]) << 8) | ord($b[$p + 3]);
            if ($m === 0xEE && substr($b, $p + 4, 5) === 'Adobe') $adobe = true;
            // SOF0..SOF15 (except DHT C4 / JPG C8 / DAC CC)
            if ($m >= 0xC0 && $m <= 0xCF && !in_array($m, [0xC4, 0xC8, 0xCC], true)) {
                if ($p + 9 >= $len) return null;
                $img = new self();
                $img->bits = ord($b[$p + 4]);
                $img->height = (ord($b[$p + 5]) << 8) | ord($b[$p + 6]);
                $img->width = (ord($b[$p + 7]) << 8) | ord($b[$p + 8]);
                $comp = ord($b[$p + 9]);
                $img->colorSpace = match ($comp) { 1 => '/DeviceGray', 4 => '/DeviceCMYK', default => '/DeviceRGB' };
                // Adobe CMYK JPEGs are stored inverted
                if ($comp === 4 && $adobe) $img->decode = '[1 0 1 0 1 0 1 0]';
                $img->filter = '/DCTDecode';
                $img->data = $b;
                return $img->width > 0 && $img->height > 0 ? $img : null;
            }
            $p += 2 + $seg;
        }
        return null;
    }

    private static function png(string $b, bool $allowGd = true): ?self
    {
        $p = 8;
        $len = strlen($b);
        $ihdr = null; $plte = ''; $trns = null; $idat = '';
        while ($p + 8 <= $len) {
            $n = unpack('N', substr($b, $p, 4))[1];
            $type = substr($b, $p + 4, 4);
            $chunk = substr($b, $p + 8, $n);
            $p += 12 + $n;
            if ($type === 'IHDR') $ihdr = $chunk;
            elseif ($type === 'PLTE') $plte = $chunk;
            elseif ($type === 'tRNS') $trns = $chunk;
            elseif ($type === 'IDAT') $idat .= $chunk;
            elseif ($type === 'IEND') break;
        }
        if ($ihdr === null || strlen($ihdr) < 13 || $idat === '') return null;
        $h = unpack('Nw/Nh/Cbits/Ccolor/Ccomp/Cfilter/Cinterlace', $ihdr);
        $paletteAlpha = $h['color'] === 3 && $trns !== null && trim($trns, "\xFF") !== '';
        if ($h['interlace'] !== 0 || $h['bits'] > 8 || $paletteAlpha) {
            // not embeddable as is: let GD rewrite it as 8-bit, non-interlaced
            if (!$allowGd) return null;
            $png = self::viaGd($b);
            return $png !== null ? self::png($png, false) : null;
        }
        $img = new self();
        $img->width = $h['w'];
        $img->height = $h['h'];
        $img->bits = $h['bits'];
        $colors = match ($h['color']) { 0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4, default => 0 };
        if ($colors === 0 || $img->width <= 0 || $img->height <= 0) return null;

        if ($h['color'] === 4 || $h['color'] === 6) {
            // alpha channel: decode and split into color + soft mask
            $raw = @gzuncompress($idat);
            if ($raw === false) return null;
            [$color, $alpha] = self::splitAlpha($raw, $img->width, $img->height, $colors);
            $img->colorSpace = $h['color'] === 4 ? '/DeviceGray' : '/DeviceRGB';
            $img->data = gzcompress($color, 6);
            $sm = new self();
            $sm->width = $img->width; $sm->height = $img->height; $sm->bits = 8;
            $sm->colorSpace = '/DeviceGray';
            $sm->data = gzcompress($alpha, 6);
            $img->smask = $sm;
            return $img;
        }
        $img->data = $idat;
        $img->decodeParms = sprintf('<< /Predictor 15 /Colors %d /BitsPerComponent %d /Columns %d >>', $colors, $img->bits, $img->width);
        if ($h['color'] === 3) {
            if ($plte === '') return null;
            $img->colorSpace = sprintf('[/Indexed /DeviceRGB %d <%s>]', intdiv(strlen($plte), 3) - 1, bin2hex($plte));
        } else {
            $img->colorSpace = $h['color'] === 0 ? '/DeviceGray' : '/DeviceRGB';
            // a single transparent color (tRNS): color key mask
            if ($trns !== null) {
                $v = array_values(unpack('n*', $trns));
                $img->mask = '[' . implode(' ', array_merge(...array_map(fn ($x) => [$x, $x], $v))) . ']';
            }
        }
        return $img;
    }

    /**
     * Undo the PNG row filters and split the pixels into color and alpha (8-bit only).
     * @return array{0:string,1:string}
     */
    private static function splitAlpha(string $raw, int $w, int $h, int $channels): array
    {
        $bpp = $channels;
        $stride = $w * $bpp;
        $prev = array_fill(0, $stride, 0);
        $color = '';
        $alpha = '';
        $pos = 0;
        $cc = $channels - 1;
        for ($y = 0; $y < $h; $y++) {
            $ft = ord($raw[$pos] ?? "\0");
            $line = array_values(unpack('C*', substr($raw, $pos + 1, $stride)) ?: []);
            $pos += $stride + 1;
            if (count($line) < $stride) $line = array_pad($line, $stride, 0);
            switch ($ft) {
                case 1:
                    for ($i = $bpp; $i < $stride; $i++) $line[$i] = ($line[$i] + $line[$i - $bpp]) & 0xFF;
                    break;
                case 2:
                    for ($i = 0; $i < $stride; $i++) $line[$i] = ($line[$i] + $prev[$i]) & 0xFF;
                    break;
                case 3:
                    for ($i = 0; $i < $stride; $i++) {
                        $left = $i >= $bpp ? $line[$i - $bpp] : 0;
                        $line[$i] = ($line[$i] + (($left + $prev[$i]) >> 1)) & 0xFF;
                    }
                    break;
                case 4:
                    for ($i = 0; $i < $stride; $i++) {
                        $a = $i >= $bpp ? $line[$i - $bpp] : 0;
                        $b = $prev[$i];
                        $c = $i >= $bpp ? $prev[$i - $bpp] : 0;
                        $pp = $a + $b - $c;
                        $pa = abs($pp - $a); $pb = abs($pp - $b); $pc = abs($pp - $c);
                        $pr = ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
                        $line[$i] = ($line[$i] + $pr) & 0xFF;
                    }
                    break;
            }
            $prev = $line;
            $cRow = [];
            $aRow = [];
            for ($i = 0; $i < $stride; $i += $channels) {
                for ($k = 0; $k < $cc; $k++) $cRow[] = $line[$i + $k];
                $aRow[] = $line[$i + $cc];
            }
            $color .= pack('C*', ...$cRow);
            $alpha .= pack('C*', ...$aRow);
        }
        return [$color, $alpha];
    }

    /** Rewrite any GD-readable image as an 8-bit PNG (alpha kept). null without GD or when unreadable. */
    private static function viaGd(string $bytes): ?string
    {
        if (!function_exists('imagecreatefromstring')) return null;
        $im = @imagecreatefromstring($bytes);
        if ($im === false) return null;
        if (!imageistruecolor($im)) imagepalettetotruecolor($im);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imageinterlace($im, false);
        ob_start();
        imagepng($im, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($im);
        return $png !== '' ? $png : null;
    }
}
