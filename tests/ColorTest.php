<?php

declare(strict_types=1);

namespace SailingO\PdfCanvas\Tests;

use PHPUnit\Framework\TestCase;
use SailingO\PdfCanvas\Color;

final class ColorTest extends TestCase
{
    public function testParsesCommonForms(): void
    {
        $this->assertSame([1.0, 0.0, 0.0], Color::parse('#f00'));
        $this->assertEqualsWithDelta([0x1e / 255, 0x3a / 255, 0x8a / 255], Color::parse('#1E3A8A'), 1e-9);
        $this->assertEqualsWithDelta([71 / 255, 85 / 255, 105 / 255], Color::parse('rgb(71, 85, 105)'), 1e-9);
        $this->assertSame([0.0, 0.0, 1.0], Color::parse('blue'));
        $this->assertSame([1.0, 0.5, 0.0], Color::parse([255, 127.5, 0]));
        $this->assertSame([0.0, 0.0, 0.0], Color::parse('something odd'));
    }

    public function testNoPaint(): void
    {
        foreach ([null, '', 'none', 'transparent', '#ff000000', 'rgba(1, 2, 3, 0)'] as $c) {
            $this->assertNull(Color::parse($c), var_export($c, true));
        }
    }
}
