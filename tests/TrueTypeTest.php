<?php

declare(strict_types=1);

namespace SailingO\PdfCanvas\Tests;

use PHPUnit\Framework\TestCase;
use SailingO\PdfCanvas\PdfException;
use SailingO\PdfCanvas\Tests\Support\TinyFont;
use SailingO\PdfCanvas\TrueType;

final class TrueTypeTest extends TestCase
{
    public function testReadsMetricsAndCmap(): void
    {
        $tt = TrueType::parse(TinyFont::bytes());
        $this->assertSame(1000, $tt->unitsPerEm);
        $this->assertSame(6, $tt->numGlyphs);
        $this->assertSame(TinyFont::ADVANCES, $tt->advances);
        $this->assertSame(1, $tt->glyphId(ord('A')));
        $this->assertSame(5, $tt->glyphId(0x3042));
        $this->assertSame(0, $tt->glyphId(ord('Z')));
        $this->assertSame('TinyTest-Regular', $tt->postScriptName);
    }

    public function testWidthIsTheSumOfAdvanceWidthsInMillimetres(): void
    {
        $tt = TrueType::parse(TinyFont::bytes());
        // A (600) + あ (1000) + unknown Z → .notdef (500) at 10pt
        $expected = (600 + 1000 + 500) / 1000 * 10 * 25.4 / 72;
        $this->assertEqualsWithDelta($expected, $tt->width('AあZ', 10), 1e-9);
        $this->assertSame(0.0, $tt->width('', 10));
    }

    public function testRejectsFontsThatForbidEmbedding(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('does not allow embedding');
        TrueType::parse(TinyFont::bytes(fsType: 0x0002));
    }

    public function testRejectsOtherFormats(): void
    {
        foreach (['OTTO' . str_repeat("\0", 20), 'ttcf' . str_repeat("\0", 20), 'not a font at all', ''] as $bytes) {
            try {
                TrueType::parse($bytes);
                $this->fail('accepted: ' . substr($bytes, 0, 4));
            } catch (PdfException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSubsetKeepsGlyphIdsAndCompositeParts(): void
    {
        $tt = TrueType::parse(TinyFont::bytes());
        $sub = $tt->subset([3], [ord('C') => 3]);   // 'C' = composite of A + B
        $re = TrueType::parse($sub);
        $this->assertSame(6, $re->numGlyphs);
        $lengths = self::glyphLengths($sub);
        $this->assertGreaterThan(0, $lengths[0], '.notdef is always kept');
        $this->assertGreaterThan(0, $lengths[1], 'component A');
        $this->assertGreaterThan(0, $lengths[2], 'component B');
        $this->assertGreaterThan(0, $lengths[3], 'composite C');
        $this->assertSame(0, $lengths[4], 'unused D is dropped');
        $this->assertSame(0, $lengths[5], 'unused あ is dropped');
        // kept glyphs keep their metrics, dropped ones are zeroed
        $this->assertSame(650, $re->advances[3]);
        $this->assertSame(0, $re->advances[4]);
        $this->assertSame(3, $re->glyphId(ord('C')), 'the subset carries a cmap for the drawn characters');
        $this->assertSame(0, $re->glyphId(ord('A')), 'characters not drawn are not mapped');
    }

    public function testSubsetHasValidChecksums(): void
    {
        $sub = TrueType::parse(TinyFont::bytes())->subset([1, 5], [0x41 => 1, 0x3042 => 5, 0x20BB7 => 5]);
        $this->assertSame(5, TrueType::parse($sub)->glyphId(0x20BB7), 'format 12 for characters beyond the BMP');
        $sum = 0;
        $padded = $sub . str_repeat("\0", (4 - strlen($sub) % 4) % 4);
        foreach (unpack('N*', $padded) as $v) $sum = ($sum + $v) & 0xFFFFFFFF;
        $this->assertSame(0xB1B0AFBA, $sum, 'whole-font checksum (head.checkSumAdjustment)');
    }

    /** @return array<string, array{0:int,1:int}> */
    private static function tables(string $font): array
    {
        $n = unpack('n', substr($font, 4, 2))[1];
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $r = 12 + 16 * $i;
            $v = unpack('Nsum/Noff/Nlen', substr($font, $r + 4, 12));
            $out[substr($font, $r, 4)] = [$v['off'], $v['len']];
        }
        return $out;
    }

    /** @return list<int> */
    private static function glyphLengths(string $font): array
    {
        $t = self::tables($font);
        $loca = array_values(unpack('N*', substr($font, $t['loca'][0], $t['loca'][1])));
        $out = [];
        for ($i = 0; $i + 1 < count($loca); $i++) $out[] = $loca[$i + 1] - $loca[$i];
        return $out;
    }
}
