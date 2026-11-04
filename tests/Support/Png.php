<?php

declare(strict_types=1);

namespace SailingO\PdfCanvas\Tests\Support;

/** Hand-written PNG encoder for the tests: uses every row filter (None, Sub, Up, Average, Paeth) in turn. */
final class Png
{
    /**
     * @param list<list<list<int>>> $pixels rows of pixels, each pixel = channel values (0–255)
     * @param int $colorType 0 gray, 2 RGB, 4 gray + alpha, 6 RGBA
     */
    public static function encode(array $pixels, int $colorType): string
    {
        $h = count($pixels);
        $w = count($pixels[0]);
        $bpp = count($pixels[0][0]);
        $raw = '';
        $prev = array_fill(0, $w * $bpp, 0);
        foreach ($pixels as $y => $row) {
            $line = array_merge(...$row);
            $ft = $y % 5;
            $out = [];
            foreach ($line as $i => $v) {
                $a = $i >= $bpp ? $line[$i - $bpp] : 0;
                $b = $prev[$i];
                $c = $i >= $bpp ? $prev[$i - $bpp] : 0;
                $pred = match ($ft) {
                    0 => 0,
                    1 => $a,
                    2 => $b,
                    3 => ($a + $b) >> 1,
                    4 => self::paeth($a, $b, $c),
                };
                $out[] = ($v - $pred) & 0xFF;
            }
            $raw .= chr($ft) . pack('C*', ...$out);
            $prev = $line;
        }
        return "\x89PNG\r\n\x1a\n"
            . self::chunk('IHDR', pack('NNCCCCC', $w, $h, 8, $colorType, 0, 0, 0))
            . self::chunk('IDAT', gzcompress($raw))
            . self::chunk('IEND', '');
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);
        return ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }
}
