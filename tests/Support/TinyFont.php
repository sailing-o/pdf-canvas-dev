<?php

declare(strict_types=1);

namespace SailingO\PdfCanvas\Tests\Support;

/**
 * Builds a tiny TrueType font in memory for the tests (no font files in the repository).
 *
 *   gid 0 .notdef  advance 500   box
 *   gid 1 'A'      advance 600   triangle
 *   gid 2 'B'      advance 700   box
 *   gid 3 'C'      advance 650   composite of gid 1 + gid 2
 *   gid 4 'D'      advance 550   box (never used by the subset tests)
 *   gid 5 'あ'     advance 1000  box (U+3042)
 */
final class TinyFont
{
    public const ADVANCES = [500, 600, 700, 650, 550, 1000];

    public static function bytes(int $fsType = 0, string $name = 'TinyTest-Regular'): string
    {
        $glyphs = [
            self::simple([[50, 0], [450, 0], [450, 700], [50, 700]]),
            self::simple([[0, 0], [600, 0], [300, 700]]),
            self::simple([[50, 0], [650, 0], [650, 700], [50, 700]]),
            self::composite([[1, 0, 0], [2, 20, 0]]),
            self::simple([[50, 0], [500, 0], [500, 700], [50, 700]]),
            self::simple([[100, -100], [900, -100], [900, 800], [100, 800]]),
        ];
        $glyf = '';
        $loca = [];
        foreach ($glyphs as $g) {
            $loca[] = strlen($glyf);
            $glyf .= $g . str_repeat("\0", (4 - strlen($g) % 4) % 4);
        }
        $loca[] = strlen($glyf);
        $n = count($glyphs);

        $tables = [
            'head' => pack('NNNNnnJJnnnnnnnnn', 0x00010000, 0x00010000, 0, 0x5F0F3CF5, 0, 1000, 0, 0,
                0, -100 & 0xFFFF, 900, 800, 0, 8, 2, 1, 0),
            'hhea' => pack('Nnnnnnnnnnnnnnnnn', 0x00010000, 900, -200 & 0xFFFF, 0, 1000, 0, 0, 900, 1, 0, 0, 0, 0, 0, 0, 0, $n),
            'maxp' => pack('Nn', 0x00005000, $n),
            'hmtx' => implode('', array_map(fn ($a) => pack('nn', $a, 0), self::ADVANCES)),
            'cmap' => self::cmap4([0x41 => 1, 0x42 => 2, 0x43 => 3, 0x44 => 4, 0x3042 => 5]),
            'loca' => pack('N*', ...$loca),
            'glyf' => $glyf,
            'OS/2' => pack('nnnnn', 3, 500, 400, 5, $fsType) . str_repeat("\0", 78 - 10),
            'name' => self::name($name),
            'post' => pack('NNnnNNNNN', 0x00030000, 0, 0, 0, 0, 0, 0, 0, 0),
        ];
        ksort($tables, SORT_STRING);
        $num = count($tables);
        $es = (int) floor(log($num, 2));
        $sr = (2 ** $es) * 16;
        $offset = 12 + 16 * $num;
        $dir = '';
        $body = '';
        foreach ($tables as $tag => $t) {
            $dir .= str_pad((string) $tag, 4) . pack('NNN', 0, $offset, strlen($t));
            $t .= str_repeat("\0", (4 - strlen($t) % 4) % 4);
            $body .= $t;
            $offset += strlen($t);
        }
        return pack('Nnnnn', 0x00010000, $num, $sr, $es, $num * 16 - $sr) . $dir . $body;
    }

    /** @param list<array{0:int,1:int}> $pts one closed contour of on-curve points */
    private static function simple(array $pts): string
    {
        $xs = array_column($pts, 0);
        $ys = array_column($pts, 1);
        $out = pack('nnnnn', 1, min($xs) & 0xFFFF, min($ys) & 0xFFFF, max($xs) & 0xFFFF, max($ys) & 0xFFFF);
        $out .= pack('n', count($pts) - 1) . pack('n', 0) . str_repeat("\x01", count($pts));
        $px = 0;
        $py = 0;
        $dx = '';
        $dy = '';
        foreach ($pts as [$x, $y]) {
            $dx .= pack('n', ($x - $px) & 0xFFFF);
            $dy .= pack('n', ($y - $py) & 0xFFFF);
            $px = $x;
            $py = $y;
        }
        return $out . $dx . $dy;
    }

    /** @param list<array{0:int,1:int,2:int}> $parts [gid, dx, dy] */
    private static function composite(array $parts): string
    {
        $out = pack('nnnnn', 0xFFFF, 0, 0, 700, 700);
        foreach ($parts as $i => [$gid, $dx, $dy]) {
            $flags = 0x0001 | 0x0002 | ($i < count($parts) - 1 ? 0x0020 : 0);
            $out .= pack('nnnn', $flags, $gid, $dx & 0xFFFF, $dy & 0xFFFF);
        }
        return $out;
    }

    /** @param array<int,int> $map code point → gid (one segment per code point, fine for a test font) */
    private static function cmap4(array $map): string
    {
        ksort($map);
        $ends = $starts = $deltas = [];
        foreach ($map as $cp => $g) {
            $ends[] = $cp;
            $starts[] = $cp;
            $deltas[] = ($g - $cp) & 0xFFFF;
        }
        $ends[] = 0xFFFF;
        $starts[] = 0xFFFF;
        $deltas[] = 1;
        $seg = count($ends);
        $es = (int) floor(log($seg, 2));
        $sr = 2 * (2 ** $es);
        $sub = pack('nnnnnnn', 4, 0, 0, 2 * $seg, $sr, $es, 2 * $seg - $sr)
            . pack('n*', ...$ends) . pack('n', 0) . pack('n*', ...$starts) . pack('n*', ...$deltas) . pack('n*', ...array_fill(0, $seg, 0));
        $sub = substr_replace($sub, pack('n', strlen($sub)), 2, 2);
        return pack('nnnnN', 0, 1, 3, 1, 12) . $sub;
    }

    private static function name(string $ps): string
    {
        $s = mb_convert_encoding($ps, 'UTF-16BE', 'UTF-8');
        return pack('nnn', 0, 1, 6 + 12) . pack('nnnnnn', 3, 1, 0x409, 6, strlen($s), 0) . $s;
    }
}
