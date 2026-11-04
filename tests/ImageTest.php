<?php

declare(strict_types=1);

namespace SailingO\PdfCanvas\Tests;

use PHPUnit\Framework\TestCase;
use SailingO\PdfCanvas\Image;
use SailingO\PdfCanvas\PdfException;
use SailingO\PdfCanvas\Tests\Support\Png;

final class ImageTest extends TestCase
{
    public function testRgbPngIsEmbeddedCompressedAsIs(): void
    {
        $png = Png::encode([[[255, 0, 0], [0, 255, 0]], [[0, 0, 255], [9, 9, 9]]], 2);
        $img = Image::fromBytes($png);
        $this->assertSame([2, 2], [$img->width, $img->height]);
        $this->assertSame('/DeviceRGB', $img->colorSpace);
        $this->assertStringContainsString('/Predictor 15 /Colors 3', (string) $img->decodeParms);
        $this->assertNull($img->smask);
    }

    public function testAlphaPngIsSplitIntoColorAndSoftMask(): void
    {
        // 5 × 7 with a gradient, so every row filter has something to predict
        $pixels = [];
        for ($y = 0; $y < 7; $y++) {
            $row = [];
            for ($x = 0; $x < 5; $x++) $row[] = [($x * 50) % 256, ($y * 40) % 256, ($x * $y * 13) % 256, (255 - $x * 30 - $y * 5) % 256];
            $pixels[] = $row;
        }
        $img = Image::fromBytes(Png::encode($pixels, 6));
        $this->assertNotNull($img->smask);
        $color = gzuncompress($img->data);
        $alpha = gzuncompress($img->smask->data);
        $expectColor = '';
        $expectAlpha = '';
        foreach ($pixels as $row) {
            foreach ($row as [$r, $g, $b, $a]) {
                $expectColor .= chr($r) . chr($g) . chr($b);
                $expectAlpha .= chr($a);
            }
        }
        $this->assertSame(bin2hex($expectColor), bin2hex($color), 'all five PNG row filters are undone');
        $this->assertSame(bin2hex($expectAlpha), bin2hex($alpha));
        $this->assertSame('/DeviceGray', $img->smask->colorSpace);
    }

    public function testGrayAlphaPng(): void
    {
        $img = Image::fromBytes(Png::encode([[[10, 255], [20, 128]], [[30, 0], [40, 64]]], 4));
        $this->assertSame('/DeviceGray', $img->colorSpace);
        $this->assertSame("\x0a\x14\x1e\x28", gzuncompress($img->data));
        $this->assertSame("\xff\x80\x00\x40", gzuncompress($img->smask->data));
    }

    public function testJpegSizeAndComponents(): void
    {
        if (!function_exists('imagejpeg')) $this->markTestSkipped('ext-gd is needed to make a JPEG');
        $im = imagecreatetruecolor(31, 17);
        ob_start();
        imagejpeg($im);
        $img = Image::fromBytes((string) ob_get_clean());
        $this->assertSame([31, 17], [$img->width, $img->height]);
        $this->assertSame('/DCTDecode', $img->filter);
        $this->assertSame('/DeviceRGB', $img->colorSpace);
    }

    public function testPaletteAndGifViaGd(): void
    {
        if (!function_exists('imagegif')) $this->markTestSkipped('ext-gd is needed');
        $p = imagecreate(4, 3);
        imagecolorallocate($p, 255, 255, 255);
        imagefilledrectangle($p, 1, 1, 2, 2, imagecolorallocate($p, 20, 160, 60));
        ob_start();
        imagepng($p);
        $img = Image::fromBytes((string) ob_get_clean());
        $this->assertStringStartsWith('[/Indexed /DeviceRGB', $img->colorSpace);
        ob_start();
        imagegif($p);
        $gif = Image::fromBytes((string) ob_get_clean());
        $this->assertSame([4, 3], [$gif->width, $gif->height]);
    }

    public function testUnsupportedBytes(): void
    {
        $this->assertNull(Image::tryFromBytes('hello'));
        $this->expectException(PdfException::class);
        Image::fromBytes('<svg xmlns="http://www.w3.org/2000/svg"/>');
    }
}
