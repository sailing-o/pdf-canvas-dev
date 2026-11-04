<?php

declare(strict_types=1);

namespace SailingO\PdfCanvas;

/**
 * A parsed TrueType font (glyf outlines, .ttf). Immutable and independent of any document,
 * so one instance can be shared by many documents (parse once, keep it around).
 *
 * Tables read:
 *   cmap (format 4 / 12)  character → glyph id
 *   hmtx / hhea           advance widths (text measurement and the PDF /W array)
 *   head / OS/2 / post    units per em, bounding box, metrics, embedding permission
 *   loca / glyf           subsetting
 *
 * Subsetting keeps glyph ids unchanged: unused glyphs become empty, so the PDF can use
 * CIDToGIDMap /Identity (character code = glyph id).
 */
final class TrueType
{
    public readonly int $unitsPerEm;
    public readonly int $numGlyphs;
    /** @var list<int> glyph id → advance width (font units) */
    public readonly array $advances;
    /** @var array<int, int> Unicode code point → glyph id */
    public readonly array $cmap;
    public readonly string $postScriptName;
    /** @var array{0:int,1:int,2:int,3:int} xMin, yMin, xMax, yMax */
    public readonly array $bbox;
    public readonly int $ascent;
    public readonly int $descent;
    public readonly int $capHeight;
    public readonly float $italicAngle;
    public readonly bool $fixedPitch;

    /** @var array<string, array{0:int,1:int}> tag → [offset, length] */
    private array $tables = [];
    /** @var list<int> */
    private array $loca = [];
    private int $numHMetrics;

    private function __construct(private readonly string $data)
    {
        if (strlen($data) < 12) throw new PdfException('Not a TrueType font (too short)');
        $this->readDirectory();
        foreach (['head', 'hhea', 'maxp', 'hmtx', 'cmap', 'loca', 'glyf'] as $t) {
            if (!isset($this->tables[$t])) throw new PdfException("TrueType font has no '$t' table");
        }
        $h = $this->tables['head'][0];
        $this->unitsPerEm = max(16, $this->u16($h + 18));
        $this->bbox = [$this->i16($h + 36), $this->i16($h + 38), $this->i16($h + 40), $this->i16($h + 42)];
        $longLoca = $this->i16($h + 50) === 1;

        $this->numGlyphs = $this->u16($this->tables['maxp'][0] + 4);
        $hh = $this->tables['hhea'][0];
        $ascent = $this->i16($hh + 4);
        $descent = $this->i16($hh + 6);
        $nh = max(1, min($this->numGlyphs, $this->u16($hh + 34)));
        $this->numHMetrics = $nh;
        $raw = array_values(unpack('n*', substr($this->data, $this->tables['hmtx'][0], 4 * $nh)) ?: []);
        $widths = [];
        for ($i = 0; $i < $nh; $i++) $widths[] = $raw[$i * 2] ?? 0;
        $last = $widths[$nh - 1];
        for ($i = $nh; $i < $this->numGlyphs; $i++) $widths[] = $last;
        $this->advances = $widths;

        [$lo] = $this->tables['loca'];
        $n = $this->numGlyphs + 1;
        $this->loca = $longLoca
            ? array_values(unpack('N' . $n, substr($this->data, $lo, 4 * $n)) ?: [])
            : array_map(fn ($v) => $v * 2, array_values(unpack('n' . $n, substr($this->data, $lo, 2 * $n)) ?: []));
        if (count($this->loca) !== $n) throw new PdfException('Broken TrueType font (loca table too short)');

        $capHeight = (int) round($ascent * 0.7);
        if (isset($this->tables['OS/2'])) {
            [$o, $len] = $this->tables['OS/2'];
            $version = $this->u16($o);
            $fsType = $this->u16($o + 8);
            // 0x0002 = "restricted license embedding": the font must not be embedded
            if (($fsType & 0x000F) === 0x0002) throw new PdfException('This font does not allow embedding (OS/2 fsType: restricted license)');
            if ($len >= 78) {
                $ascent = $this->i16($o + 68) ?: $ascent;
                $descent = $this->i16($o + 70) ?: $descent;
            }
            if ($version >= 2 && $len >= 90) $capHeight = $this->i16($o + 88) ?: $capHeight;
        }
        $this->ascent = $ascent;
        $this->descent = $descent;
        $this->capHeight = $capHeight;

        $angle = 0.0;
        $fixed = false;
        if (isset($this->tables['post'])) {
            $p = $this->tables['post'][0];
            $angle = $this->i16($p + 4) + $this->u16($p + 6) / 65536;
            $fixed = $this->u32($p + 12) !== 0;
        }
        $this->italicAngle = $angle;
        $this->fixedPitch = $fixed;
        $this->cmap = $this->readCmap();
        $this->postScriptName = $this->readPostScriptName();
    }

    /** @throws PdfException when the bytes are not a usable TrueType font */
    public static function parse(string $bytes): self
    {
        return new self($bytes);
    }

    /** Glyph id for a code point (0 = .notdef when the font has no such character). */
    public function glyphId(int $codepoint): int
    {
        return $this->cmap[$codepoint] ?? 0;
    }

    public function hasGlyph(int $codepoint): bool
    {
        return isset($this->cmap[$codepoint]);
    }

    /** Width of a string in millimetres at $pt points (sum of advance widths, no kerning). */
    public function width(string $text, float $pt): float
    {
        $sum = 0;
        foreach (self::codepoints($text) as $cp) $sum += $this->advances[$this->cmap[$cp] ?? 0] ?? 0;
        return $sum / $this->unitsPerEm * $pt * 25.4 / 72;
    }

    /**
     * @internal UTF-8 → code points (invalid sequences become U+FFFD).
     * @return list<int>
     */
    public static function codepoints(string $text): array
    {
        if ($text === '') return [];
        if (!mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        return array_map('mb_ord', mb_str_split($text));
    }

    /** @internal Advance width in PDF glyph space (1/1000 em). */
    public function advance1000(int $gid): int
    {
        return (int) round(($this->advances[$gid] ?? 0) * 1000 / $this->unitsPerEm);
    }

    /** @internal */
    public function scale1000(int $v): int
    {
        return (int) round($v * 1000 / $this->unitsPerEm);
    }

    /**
     * @internal A font program containing only the given glyphs (plus .notdef and composite parts),
     * with glyph ids unchanged. Keeps head hhea maxp hmtx loca glyf and, when present, cvt fpgm prep OS/2 name post,
     * plus a small cmap for the given characters (code point → glyph id) so the subset is a complete font.
     * @param list<int> $gids
     * @param array<int, int> $cmap code point → glyph id
     */
    public function subset(array $gids, array $cmap = []): string
    {
        $gOff = $this->tables['glyf'][0];
        $keep = [];
        $queue = array_merge([0], array_map('intval', $gids));
        while ($queue) {
            $g = array_pop($queue);
            if ($g < 0 || $g >= $this->numGlyphs || isset($keep[$g])) continue;
            $keep[$g] = true;
            foreach ($this->componentGlyphs($g) as $c) $queue[] = $c;
        }
        $glyf = '';
        $loca = [];
        for ($g = 0; $g < $this->numGlyphs; $g++) {
            $loca[] = strlen($glyf);
            if (!isset($keep[$g])) continue;
            $len = $this->loca[$g + 1] - $this->loca[$g];
            if ($len <= 0) continue;
            $glyf .= substr($this->data, $gOff + $this->loca[$g], $len);
            if (strlen($glyf) % 4) $glyf .= str_repeat("\0", 4 - strlen($glyf) % 4);
        }
        $loca[] = strlen($glyf);

        // hmtx: metrics of dropped glyphs are zeroed (the PDF positions text with /W, and zeros compress away)
        $src = substr($this->data, $this->tables['hmtx'][0], $this->tables['hmtx'][1]);
        $parts = [];
        for ($g = 0; $g < $this->numGlyphs; $g++) {
            $size = $g < $this->numHMetrics ? 4 : 2;
            $pos = $g < $this->numHMetrics ? 4 * $g : 4 * $this->numHMetrics + 2 * ($g - $this->numHMetrics);
            $parts[] = isset($keep[$g]) ? substr($src, $pos, $size) : str_repeat("\0", $size);
        }
        $hmtx = implode('', $parts);

        $tables = [];
        foreach (['cvt ', 'fpgm', 'prep', 'hhea', 'maxp', 'OS/2', 'name', 'post'] as $t) {
            if (isset($this->tables[$t])) $tables[$t] = substr($this->data, $this->tables[$t][0], $this->tables[$t][1]);
        }
        $head = substr($this->data, $this->tables['head'][0], $this->tables['head'][1]);
        $head = substr_replace($head, "\0\0\0\0", 8, 4);        // checkSumAdjustment, set below
        $head = substr_replace($head, pack('n', 1), 50, 2);     // indexToLocFormat = long
        $tables['head'] = $head;
        $tables['hmtx'] = $hmtx;
        $tables['cmap'] = self::buildCmap(array_filter($cmap, fn ($g) => isset($keep[$g]) && $g > 0));
        $tables['loca'] = pack('N*', ...$loca);
        $tables['glyf'] = $glyf;
        ksort($tables, SORT_STRING);

        $num = count($tables);
        $es = (int) floor(log($num, 2));
        $sr = (2 ** $es) * 16;
        $offset = 12 + 16 * $num;
        $dir = '';
        $body = '';
        $headPos = 0;
        foreach ($tables as $tag => $bytes) {
            $len = strlen($bytes);
            $padded = $bytes . str_repeat("\0", (4 - $len % 4) % 4);
            if ($tag === 'head') $headPos = $offset;
            $dir .= str_pad((string) $tag, 4) . pack('NNN', self::checksum($padded), $offset, $len);
            $body .= $padded;
            $offset += strlen($padded);
        }
        $font = pack('Nnnnn', 0x00010000, $num, $sr, $es, $num * 16 - $sr) . $dir . $body;
        $adj = (0xB1B0AFBA - self::checksum($font)) & 0xFFFFFFFF;
        return substr_replace($font, pack('N', $adj), $headPos + 8, 4);
    }

    /**
     * cmap with a (3,1) format 4 subtable for the BMP and, when needed, a (3,10) format 12 subtable.
     * @param array<int, int> $map code point → glyph id
     */
    private static function buildCmap(array $map): string
    {
        ksort($map);
        $bmp = array_filter($map, fn ($cp) => $cp < 0xFFFF, ARRAY_FILTER_USE_KEY);
        // format 4: one segment per run of consecutive code points with consecutive glyph ids
        $segs = [];
        foreach ($bmp as $cp => $g) {
            $last = $segs ? $segs[count($segs) - 1] : null;
            if ($last && $cp === $last[1] + 1 && $g - $cp === $last[2]) $segs[count($segs) - 1][1] = $cp;
            else $segs[] = [$cp, $cp, $g - $cp];
        }
        $segs[] = [0xFFFF, 0xFFFF, 1];
        $n = count($segs);
        $es = (int) floor(log($n, 2));
        $sr = 2 * (2 ** $es);
        $f4 = pack('n*', ...array_column($segs, 1)) . pack('n', 0) . pack('n*', ...array_column($segs, 0))
            . pack('n*', ...array_map(fn ($d) => $d & 0xFFFF, array_column($segs, 2))) . str_repeat("\0\0", $n);
        $f4 = pack('nnnnnnn', 4, 14 + strlen($f4), 0, 2 * $n, $sr, $es, 2 * $n - $sr) . $f4;
        $subs = [[3, 1, $f4]];
        if (count($bmp) < count($map)) {
            $groups = '';
            foreach ($map as $cp => $g) $groups .= pack('NNN', $cp, $cp, $g);
            $subs[] = [3, 10, pack('nnNNN', 12, 0, 16 + strlen($groups), 0, count($map)) . $groups];
        }
        $head = pack('nn', 0, count($subs));
        $body = '';
        $off = 4 + 8 * count($subs);
        foreach ($subs as [$pid, $eid, $data]) {
            $head .= pack('nnN', $pid, $eid, $off + strlen($body));
            $body .= $data;
        }
        return $head . $body;
    }

    /** @return list<int> glyph ids referenced by a composite glyph */
    private function componentGlyphs(int $g): array
    {
        $len = $this->loca[$g + 1] - $this->loca[$g];
        if ($len < 10) return [];
        $p = $this->tables['glyf'][0] + $this->loca[$g];
        $end = $p + $len;
        if ($this->i16($p) >= 0) return [];
        $p += 10;
        $out = [];
        do {
            if ($p + 4 > $end) break;
            $flags = $this->u16($p);
            $out[] = $this->u16($p + 2);
            $p += 4 + (($flags & 0x0001) ? 4 : 2);
            if ($flags & 0x0008) $p += 2;
            elseif ($flags & 0x0040) $p += 4;
            elseif ($flags & 0x0080) $p += 8;
        } while ($flags & 0x0020);
        return $out;
    }

    private static function checksum(string $bytes): int
    {
        $bytes .= str_repeat("\0", (4 - strlen($bytes) % 4) % 4);
        $sum = 0;
        foreach (unpack('N*', $bytes) ?: [] as $v) $sum = ($sum + $v) & 0xFFFFFFFF;
        return $sum;
    }

    private function readDirectory(): void
    {
        $tag = substr($this->data, 0, 4);
        if ($tag === 'ttcf') throw new PdfException('TrueType collections (.ttc) are not supported');
        if ($tag === 'OTTO') throw new PdfException('OpenType fonts with CFF outlines (.otf) are not supported; use a TrueType (.ttf) font');
        if ($tag !== "\x00\x01\x00\x00" && $tag !== 'true') throw new PdfException('Not a TrueType font');
        $n = $this->u16(4);
        if (12 + 16 * $n > strlen($this->data)) throw new PdfException('Broken TrueType font (table directory)');
        for ($i = 0; $i < $n; $i++) {
            $r = 12 + 16 * $i;
            $off = $this->u32($r + 8);
            $len = $this->u32($r + 12);
            if ($off + $len > strlen($this->data)) throw new PdfException('Broken TrueType font (table outside the file)');
            $this->tables[substr($this->data, $r, 4)] = [$off, $len];
        }
    }

    /** @return array<int, int> Unicode cmap (format 12 preferred, otherwise format 4) */
    private function readCmap(): array
    {
        $c = $this->tables['cmap'][0];
        $n = $this->u16($c + 2);
        $best = null;
        $bestFmt = 0;
        for ($i = 0; $i < $n; $i++) {
            $pid = $this->u16($c + 4 + 8 * $i);
            $eid = $this->u16($c + 6 + 8 * $i);
            $off = $c + $this->u32($c + 8 + 8 * $i);
            if ($off + 2 > strlen($this->data)) continue;
            $fmt = $this->u16($off);
            if (!($pid === 0 || ($pid === 3 && ($eid === 1 || $eid === 10)))) continue;
            if ($fmt === 12 && $bestFmt !== 12) { $best = $off; $bestFmt = 12; }
            elseif ($fmt === 4 && $bestFmt === 0) { $best = $off; $bestFmt = 4; }
        }
        if ($best === null) throw new PdfException('TrueType font has no Unicode cmap');
        $map = [];
        if ($bestFmt === 12) {
            $groups = $this->u32($best + 12);
            for ($i = 0; $i < $groups; $i++) {
                $v = unpack('Ns/Ne/Ng', substr($this->data, $best + 16 + 12 * $i, 12));
                if (!$v) break;
                for ($cp = $v['s']; $cp <= $v['e'] && $cp <= 0x10FFFF; $cp++) $map[$cp] = $v['g'] + ($cp - $v['s']);
            }
            return $map;
        }
        $segX2 = $this->u16($best + 6);
        $seg = intdiv($segX2, 2);
        $endP = $best + 14;
        $startP = $endP + $segX2 + 2;
        $deltaP = $startP + $segX2;
        $rangeP = $deltaP + $segX2;
        $ends = array_values(unpack('n' . $seg, substr($this->data, $endP, $segX2)) ?: []);
        $starts = array_values(unpack('n' . $seg, substr($this->data, $startP, $segX2)) ?: []);
        $deltas = array_values(unpack('n' . $seg, substr($this->data, $deltaP, $segX2)) ?: []);
        $ranges = array_values(unpack('n' . $seg, substr($this->data, $rangeP, $segX2)) ?: []);
        for ($i = 0; $i < $seg; $i++) {
            for ($cp = $starts[$i]; $cp <= $ends[$i] && $cp !== 0xFFFF; $cp++) {
                if ($ranges[$i] === 0) {
                    $g = ($cp + $deltas[$i]) & 0xFFFF;
                } else {
                    $gp = $rangeP + 2 * $i + $ranges[$i] + 2 * ($cp - $starts[$i]);
                    if ($gp + 2 > strlen($this->data)) continue;
                    $g = $this->u16($gp);
                    if ($g !== 0) $g = ($g + $deltas[$i]) & 0xFFFF;
                }
                if ($g !== 0 && $g < $this->numGlyphs) $map[$cp] = $g;
            }
        }
        return $map;
    }

    private function readPostScriptName(): string
    {
        $fallback = 'Font' . substr(md5($this->data), 0, 8);
        if (!isset($this->tables['name'])) return $fallback;
        $n = $this->tables['name'][0];
        $count = $this->u16($n + 2);
        $strings = $n + $this->u16($n + 4);
        for ($i = 0; $i < $count; $i++) {
            $r = $n + 6 + 12 * $i;
            if ($this->u16($r + 6) !== 6) continue;   // nameID 6 = PostScript name
            $pid = $this->u16($r);
            $raw = substr($this->data, $strings + $this->u16($r + 10), $this->u16($r + 8));
            $s = ($pid === 3 || $pid === 0) ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE') : $raw;
            $s = (string) preg_replace('/[^A-Za-z0-9\-_]/', '', $s);
            if ($s !== '') return substr($s, 0, 63);
        }
        return $fallback;
    }

    private function u16(int $p): int
    {
        if ($p + 2 > strlen($this->data)) throw new PdfException('Broken TrueType font (read past the end)');
        return (ord($this->data[$p]) << 8) | ord($this->data[$p + 1]);
    }

    private function i16(int $p): int
    {
        $v = $this->u16($p);
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private function u32(int $p): int
    {
        if ($p + 4 > strlen($this->data)) throw new PdfException('Broken TrueType font (read past the end)');
        return unpack('N', substr($this->data, $p, 4))[1];
    }
}
