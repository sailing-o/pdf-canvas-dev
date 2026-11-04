<?php

declare(strict_types=1);

namespace SailingO\PdfCanvas\Tests;

use PHPUnit\Framework\TestCase;
use SailingO\PdfCanvas\Document;
use SailingO\PdfCanvas\PdfException;
use SailingO\PdfCanvas\Tests\Support\Png;
use SailingO\PdfCanvas\Tests\Support\TinyFont;

final class DocumentTest extends TestCase
{
    private static function plain(): Document
    {
        return new Document(['producer' => 'Test', 'compress' => false]);
    }

    public function testXrefOffsetsPointAtTheObjects(): void
    {
        $doc = new Document(['title' => '請求書']);
        $font = $doc->addTrueType(TinyFont::bytes());
        $doc->addPage(210, 297)->text('AB', 10, 20, $font, 12);
        $doc->addPage(100, 50)->rect(5, 5, 10, 10, ['fill' => 'red']);
        $pdf = $doc->render();

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);
        $this->assertMatchesRegularExpression('/startxref\n(\d+)\n/', $pdf);
        preg_match('/startxref\n(\d+)\n/', $pdf, $m);
        $xref = (int) $m[1];
        $this->assertSame('xref', substr($pdf, $xref, 4));
        preg_match('/xref\n0 (\d+)\n/', $pdf, $c);
        $entries = array_slice(explode("\n", substr($pdf, $xref)), 3, (int) $c[1] - 1);
        foreach ($entries as $i => $line) {
            $off = (int) substr($line, 0, 10);
            $this->assertSame(($i + 1) . ' 0 obj', substr($pdf, $off, strlen(($i + 1) . ' 0 obj')), "object " . ($i + 1));
        }
        $this->assertStringContainsString('/Count 2', $pdf);
        $this->assertStringContainsString('/Title <FEFF8ACB6C4266F8>', $pdf);
    }

    public function testPageSizeAndTopLeftCoordinates(): void
    {
        $doc = self::plain();
        $doc->addPage(210, 297)->rect(10, 20, 30, 40, ['fill' => '#000', 'stroke' => null]);
        $pdf = $doc->render();
        $this->assertStringContainsString('/MediaBox [0 0 595.2756 841.8898]', $pdf);
        // one page transform mm → pt with y flipped, then the shape in mm
        $this->assertStringContainsString('2.8346 0 0 -2.8346 0 841.8898 cm', $pdf);
        $this->assertStringContainsString('0 0 0 rg 10 20 30 40 re f', $pdf);
    }

    public function testCustomUnit(): void
    {
        $doc = self::plain();
        $page = $doc->addPage(960, 540, ['units_per_mm' => 96 / 25.4]);
        $this->assertSame(960.0, $page->width);
        $this->assertStringContainsString('/MediaBox [0 0 720 405]', $doc->render());
    }

    public function testTextIsEncodedAsGlyphIdsWithToUnicode(): void
    {
        $doc = self::plain();
        $font = $doc->addTrueType(TinyFont::bytes());
        $doc->addPage(210, 297)->text('AあA', 10, 20, $font, 10, ['color' => '#ff0000']);
        $pdf = $doc->render();
        $this->assertStringContainsString('<000100050001> Tj', $pdf);
        $this->assertStringContainsString('1 0 0 rg', $pdf);
        $this->assertStringContainsString('/Encoding /Identity-H', $pdf);
        $this->assertStringContainsString('/CIDToGIDMap /Identity', $pdf);
        $this->assertMatchesRegularExpression('#/BaseFont /[A-Z]{6}\+TinyTest-Regular#', $pdf);
        $this->assertStringContainsString('<0001> <0041>', $pdf);
        $this->assertStringContainsString('<0005> <3042>', $pdf);
        $this->assertStringContainsString('/W [1 [600] 5 [1000]]', $pdf);
        $this->assertStringContainsString('/FontFile2', $pdf);
    }

    public function testAlignUsesTheFontWidths(): void
    {
        $doc = self::plain();
        $font = $doc->addTrueType(TinyFont::bytes());
        $page = $doc->addPage(100, 100);
        $w = $font->width('AB', 10);            // (600 + 700) / 1000 × 10pt in mm
        $this->assertEqualsWithDelta(1.3 * 10 * 25.4 / 72, $w, 1e-9);
        $page->text('AB', 50, 10, $font, 10, ['align' => 'right']);
        $this->assertStringContainsString(' ' . round(50 - $w, 4) . ' 10 Tm', $doc->render());
    }

    public function testSameFontAndImageAreEmbeddedOnce(): void
    {
        $doc = new Document();
        $bytes = TinyFont::bytes();
        $a = $doc->addTrueType($bytes);
        $png = Png::encode([[[1, 2, 3]]], 2);
        $p = $doc->addPage(50, 50);
        $p->text('A', 1, 5, $a, 9)->image($png, 0, 0, 5, 5)->image($png, 10, 10, 5, 5);
        $doc->addPage(50, 50)->text('B', 1, 5, $a, 9);
        $pdf = $doc->render();
        $this->assertSame(1, substr_count($pdf, '/FontFile2'));
        $this->assertSame(1, substr_count($pdf, '/Subtype /Image'));
    }

    public function testFontOfAnotherDocumentIsRejected(): void
    {
        $other = (new Document())->addTrueType(TinyFont::bytes());
        $this->expectException(PdfException::class);
        (new Document())->addPage(10, 10)->text('A', 0, 5, $other, 9);
    }

    public function testUnknownOptionsAreRejected(): void
    {
        $page = (new Document())->addPage(10, 10);
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('unknown option "colour"');
        $page->rect(0, 0, 1, 1, ['colour' => 'red']);
    }

    public function testShapesAndRotation(): void
    {
        $doc = self::plain();
        $doc->addPage(100, 100)
            ->line(0, 0, 10, 10, ['stroke' => 'blue', 'line_width' => 0.5, 'dash' => [3, 1.5]])
            ->circle(50, 50, 10, ['fill' => '#eee'])
            ->rect(10, 10, 20, 10, ['radius' => 2, 'stroke' => 'none', 'fill' => 'black'])
            ->rect(0, 0, 5, 5, ['stroke' => null])          // nothing to paint
            ->rotate(90, 50, 50, fn ($p) => $p->rect(40, 45, 20, 10, ['fill' => 'red', 'stroke' => null]));
        $pdf = $doc->render();
        $this->assertStringContainsString('0 0 1 RG 0.5 w [3 1.5] 0 d 0 0 m 10 10 l S', $pdf);
        $this->assertSame(4 + 4, substr_count($pdf, ' c '), 'four curves for the rounded corners, four for the circle');
        $this->assertStringContainsString('1 0 0 1 50 50 cm 0 1 -1 0 0 0 cm 1 0 0 1 -50 -50 cm', $pdf);
        $this->assertStringNotContainsString('0 0 5 5 re', $pdf);
    }

    public function testEmptyDocumentHasOneBlankPage(): void
    {
        $this->assertStringContainsString('/Count 1', (new Document())->render());
    }
}
