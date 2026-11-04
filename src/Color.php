<?php

declare(strict_types=1);

namespace SailingO\PdfCanvas;

/**
 * Colors as accepted by the drawing methods:
 *   '#rgb' / '#rgba' / '#rrggbb' / '#rrggbbaa', 'rgb(r, g, b)' / 'rgba(r, g, b, a)', basic CSS names,
 *   [r, g, b] with 0–255 components.
 * null, '', 'none', 'transparent' and a zero alpha mean "no paint". Alpha is otherwise ignored.
 * An unreadable string is black (as in SVG).
 */
final class Color
{
    private const NAMED = [
        'black' => '000000', 'white' => 'ffffff', 'red' => 'ff0000', 'green' => '008000', 'blue' => '0000ff',
        'yellow' => 'ffff00', 'gray' => '808080', 'grey' => '808080', 'silver' => 'c0c0c0', 'maroon' => '800000',
        'purple' => '800080', 'fuchsia' => 'ff00ff', 'magenta' => 'ff00ff', 'lime' => '00ff00', 'olive' => '808000',
        'navy' => '000080', 'teal' => '008080', 'aqua' => '00ffff', 'cyan' => '00ffff', 'orange' => 'ffa500',
        'pink' => 'ffc0cb', 'brown' => 'a52a2a', 'lightgray' => 'd3d3d3', 'lightgrey' => 'd3d3d3',
        'darkgray' => 'a9a9a9', 'darkgrey' => 'a9a9a9',
    ];

    /**
     * @param string|array{0:int|float,1:int|float,2:int|float}|null $color
     * @return array{0:float,1:float,2:float}|null components 0–1, or null for no paint
     */
    public static function parse(string|array|null $color): ?array
    {
        if ($color === null) return null;
        if (is_array($color)) {
            $v = array_values($color);
            if (count($v) < 3) throw new PdfException('A color array needs three components [r, g, b]');
            return [self::clamp($v[0] / 255.0), self::clamp($v[1] / 255.0), self::clamp($v[2] / 255.0)];
        }
        $s = strtolower(trim($color));
        if ($s === '' || $s === 'none' || $s === 'transparent') return null;
        if (isset(self::NAMED[$s])) $s = '#' . self::NAMED[$s];
        if (preg_match('/^#([0-9a-f]{3,8})$/', $s, $m)) {
            $h = $m[1];
            if (strlen($h) === 3 || strlen($h) === 4) $h = implode('', array_map(fn ($c) => $c . $c, str_split($h)));
            if (strlen($h) === 8 && hexdec(substr($h, 6, 2)) === 0) return null;
            if (strlen($h) === 6 || strlen($h) === 8) {
                return [hexdec(substr($h, 0, 2)) / 255.0, hexdec(substr($h, 2, 2)) / 255.0, hexdec(substr($h, 4, 2)) / 255.0];
            }
        }
        if (preg_match('/^rgba?\(\s*([\d.]+%?)\s*[, ]\s*([\d.]+%?)\s*[, ]\s*([\d.]+%?)\s*(?:[,\/]\s*([\d.]+%?)\s*)?\)$/', $s, $m)) {
            if (isset($m[4]) && $m[4] !== '' && (float) $m[4] == 0.0) return null;
            $c = fn (string $v) => self::clamp(str_ends_with($v, '%') ? (float) $v / 100 : (float) $v / 255);
            return [$c($m[1]), $c($m[2]), $c($m[3])];
        }
        return [0.0, 0.0, 0.0];
    }

    private static function clamp(float $v): float
    {
        return max(0.0, min(1.0, $v));
    }
}
