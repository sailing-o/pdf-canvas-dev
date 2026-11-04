<?php

declare(strict_types=1);

namespace SailingO\PdfCanvas\Tests;

use PHPUnit\Framework\TestCase;
use SailingO\PdfCanvas\Document;
use SailingO\PdfCanvas\Tests\Support\Png;
use SailingO\PdfCanvas\Tests\Support\TinyFont;

/**
 * Renders the PDF with Ghostscript (the `gs` command) and looks at pixels: the shapes are where the
 * top-left coordinates say, embedded glyphs are drawn, and soft masks blend. Skipped without Ghostscript.
 */
final class RenderTest extends TestCase
{
    private static function gs(): ?string
    {
        foreach (['gs', '/opt/homebrew/bin/gs', '/usr/local/bin/gs', '/usr/bin/gs'] as $c) {
            $p = trim((string) @shell_exec('command -v ' . escapeshellarg($c) . ' 2>/dev/null'));
            if ($p !== '') return $p;
        }
        return null;
    }

    /** @return array<int, array<int, array{0:int,1:int,2:int}>> [y][x] → rgb, at 1 pixel per mm */
    private static function raster(string $pdf): array
    {
        $dir = sys_get_temp_dir() . '/pdf-canvas-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/in.pdf", $pdf);
        exec(sprintf('%s -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=ppmraw -r25.4 -dTextAlphaBits=1 -dGraphicsAlphaBits=1 -sOutputFile=%s %s 2>&1',
            escapeshellarg((string) self::gs()), escapeshellarg("$dir/out.ppm"), escapeshellarg("$dir/in.pdf")), $out, $code);
        $ppm = (string) @file_get_contents("$dir/out.ppm");
        @unlink("$dir/in.pdf");
        @unlink("$dir/out.ppm");
        @rmdir($dir);
        // P6, then width height maxval, with optional # comment lines in between
        if ($code !== 0 || !preg_match('/^P6\s+(?:#[^\n]*\n\s*)*(\d+)\s+(\d+)\s+255\s/', $ppm, $m)) self::fail('Ghostscript failed: ' . implode("\n", $out));
        [$w, $h] = [(int) $m[1], (int) $m[2]];
        $data = substr($ppm, strlen($m[0]));
        $px = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $i = 3 * ($y * $w + $x);
                $px[$y][$x] = [ord($data[$i]), ord($data[$i + 1]), ord($data[$i + 2])];
            }
        }
        return $px;
    }

    protected function setUp(): void
    {
        if (self::gs() === null) $this->markTestSkipped('Ghostscript (gs) is not installed');
    }

    public function testShapesTextAndImagesLandWhereExpected(): void
    {
        $doc = new Document();
        $font = $doc->addTrueType(TinyFont::bytes());
        $half = Png::encode(array_fill(0, 4, array_fill(0, 4, [0, 0, 255, 128])), 6);   // 50% blue
        $doc->addPage(100, 60)
            ->rect(10, 5, 20, 10, ['fill' => '#ff0000', 'stroke' => null])
            ->circle(70, 20, 8, ['fill' => [0, 160, 0], 'stroke' => null])
            ->text('BBBB', 10, 40, $font, 40)                          // box glyphs, baseline at 40 mm
            ->rect(60, 40, 20, 15, ['fill' => '#ffffff', 'stroke' => null])
            ->image($half, 60, 40, 20, 15);
        $px = self::raster($doc->render());

        $this->assertSame([255, 0, 0], $px[10][20], 'inside the red rectangle');
        $this->assertSame([255, 255, 255], $px[17][20], 'below it (y grows downwards)');
        $this->assertSame([0, 160, 0], $px[20][70], 'circle centre');
        $this->assertSame([255, 255, 255], $px[13][63], 'outside the circle, inside its box');
        // 'B' is a box from 0 to 700/1000 em above the baseline: 40pt ≈ 14.1 mm → ink between y ≈ 30 and 40
        $this->assertSame([0, 0, 0], $px[36][15], 'embedded glyph is drawn');
        $this->assertSame([255, 255, 255], $px[44][15], 'nothing below the baseline');
        [$r, $g, $b] = $px[47][70];
        $this->assertSame(255, $b);
        $this->assertTrue($r > 100 && $r < 160 && $g > 100 && $g < 160, "soft mask blends to light blue, got [$r, $g, $b]");
    }

    public function testRotationAndPixelUnits(): void
    {
        $doc = new Document();
        // 100 × 60 "pixels" at 1 unit = 0.5 mm → the page is 50 × 30 mm
        $doc->addPage(100, 60, ['units_per_mm' => 2.0])
            ->rotate(90, 50, 30, fn ($p) => $p->rect(30, 26, 40, 8, ['fill' => 'black', 'stroke' => null]));
        $px = self::raster($doc->render());
        $this->assertCount(30, $px, 'page height in mm');
        $this->assertSame([0, 0, 0], $px[6][25], 'the bar is vertical after the rotation');
        $this->assertSame([255, 255, 255], $px[15][19], 'and no longer horizontal');
    }
}
