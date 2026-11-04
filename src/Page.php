<?php

declare(strict_types=1);

namespace SailingO\PdfCanvas;

/**
 * One page. Coordinates have their origin at the top-left corner and grow to the right and downwards,
 * in millimetres (or in the page's own unit, see Document::addPage's `units_per_mm`). Lengths such as
 * line widths and radii use the same unit; font sizes are always points.
 *
 * Every drawing method returns the page, so calls can be chained.
 */
final class Page
{
    private const SHAPE_KEYS = ['fill', 'stroke', 'line_width', 'radius'];
    private const LINE_KEYS = ['stroke', 'line_width', 'dash'];
    private const TEXT_KEYS = ['color', 'bold', 'italic', 'align'];

    /** @var list<string> content stream operators */
    private array $ops = [];

    /** @internal use Document::addPage() */
    public function __construct(
        private readonly Document $doc,
        public readonly float $width,
        public readonly float $height,
        public readonly float $unitsPerMm,
    ) {
        $s = 72 / 25.4 / $unitsPerMm;
        // one transform for the whole page: user units, top-left origin, y downwards
        $this->ops[] = sprintf('%s 0 0 %s 0 %s cm', self::n($s), self::n(-$s), self::n($height * $s));
    }

    /** @internal */
    public function widthPt(): float
    {
        return $this->width * 72 / 25.4 / $this->unitsPerMm;
    }

    /** @internal */
    public function heightPt(): float
    {
        return $this->height * 72 / 25.4 / $this->unitsPerMm;
    }

    /** @internal */
    public function contents(): string
    {
        return implode("\n", $this->ops);
    }

    /**
     * Rectangle.
     * @param array{fill?: string|array|null, stroke?: string|array|null, line_width?: float, radius?: float} $style
     *   defaults: no fill, black stroke, line_width 0.2, radius 0 (rounded corners when > 0)
     */
    public function rect(float $x, float $y, float $w, float $h, array $style = []): self
    {
        self::checkKeys($style, self::SHAPE_KEYS, 'rect');
        $paint = $this->paint($style);
        if ($paint === null) return $this;
        $r = max(0.0, min((float) ($style['radius'] ?? 0), abs($w) / 2, abs($h) / 2));
        if ($r <= 0) {
            $path = sprintf('%s %s %s %s re', self::n($x), self::n($y), self::n($w), self::n($h));
        } else {
            $k = 0.5523 * $r;
            $x2 = $x + $w;
            $y2 = $y + $h;
            $path = implode(' ', [
                self::n($x + $r) . ' ' . self::n($y) . ' m',
                self::n($x2 - $r) . ' ' . self::n($y) . ' l',
                self::curve($x2 - $r + $k, $y, $x2, $y + $r - $k, $x2, $y + $r),
                self::n($x2) . ' ' . self::n($y2 - $r) . ' l',
                self::curve($x2, $y2 - $r + $k, $x2 - $r + $k, $y2, $x2 - $r, $y2),
                self::n($x + $r) . ' ' . self::n($y2) . ' l',
                self::curve($x + $r - $k, $y2, $x, $y2 - $r + $k, $x, $y2 - $r),
                self::n($x) . ' ' . self::n($y + $r) . ' l',
                self::curve($x, $y + $r - $k, $x + $r - $k, $y, $x + $r, $y),
                'h',
            ]);
        }
        $this->ops[] = 'q ' . $paint[0] . ' ' . $path . ' ' . $paint[1] . ' Q';
        return $this;
    }

    /** Circle by centre and radius (style as rect(), without radius). */
    public function circle(float $cx, float $cy, float $r, array $style = []): self
    {
        return $this->ellipse($cx, $cy, $r, $r, $style);
    }

    /** Ellipse by centre and radii (style as rect(), without radius). */
    public function ellipse(float $cx, float $cy, float $rx, float $ry, array $style = []): self
    {
        self::checkKeys($style, ['fill', 'stroke', 'line_width'], 'ellipse');
        $paint = $this->paint($style);
        if ($paint === null || $rx <= 0 || $ry <= 0) return $this;
        $kx = 0.5523 * $rx;
        $ky = 0.5523 * $ry;
        $path = implode(' ', [
            self::n($cx + $rx) . ' ' . self::n($cy) . ' m',
            self::curve($cx + $rx, $cy + $ky, $cx + $kx, $cy + $ry, $cx, $cy + $ry),
            self::curve($cx - $kx, $cy + $ry, $cx - $rx, $cy + $ky, $cx - $rx, $cy),
            self::curve($cx - $rx, $cy - $ky, $cx - $kx, $cy - $ry, $cx, $cy - $ry),
            self::curve($cx + $kx, $cy - $ry, $cx + $rx, $cy - $ky, $cx + $rx, $cy),
            'h',
        ]);
        $this->ops[] = 'q ' . $paint[0] . ' ' . $path . ' ' . $paint[1] . ' Q';
        return $this;
    }

    /**
     * Straight line.
     * @param array{stroke?: string|array|null, line_width?: float, dash?: list<float>} $style
     *   defaults: black, line_width 0.2, solid. dash = dash and gap lengths, e.g. [3, 1.5]
     */
    public function line(float $x1, float $y1, float $x2, float $y2, array $style = []): self
    {
        self::checkKeys($style, self::LINE_KEYS, 'line');
        $stroke = Color::parse(array_key_exists('stroke', $style) ? $style['stroke'] : '#000000');
        $lw = (float) ($style['line_width'] ?? 0.2);
        if ($stroke === null || $lw <= 0) return $this;
        $dash = array_values(array_filter(array_map('floatval', (array) ($style['dash'] ?? [])), fn ($v) => $v >= 0));
        $dashOp = ($dash && array_sum($dash) > 0) ? '[' . implode(' ', array_map([self::class, 'n'], $dash)) . '] 0 d ' : '';
        $this->ops[] = sprintf('q %s RG %s w %s%s %s m %s %s l S Q',
            self::rgb($stroke), self::n($lw), $dashOp, self::n($x1), self::n($y1), self::n($x2), self::n($y2));
        return $this;
    }

    /**
     * Raster image stretched to the box (x, y, w, h).
     * @param string|Image $image JPEG / PNG bytes (GIF / WebP with ext-gd), or an Image
     * @throws PdfException for unsupported bytes
     */
    public function image(string|Image $image, float $x, float $y, float $w, float $h): self
    {
        if ($w <= 0 || $h <= 0) return $this;
        $name = $this->doc->imageResource(is_string($image) ? Image::fromBytes($image) : $image);
        // image space is the unit square with y upwards: flip it into the box
        $this->ops[] = sprintf('q %s 0 0 %s %s %s cm /%s Do Q', self::n($w), self::n(-$h), self::n($x), self::n($y + $h), $name);
        return $this;
    }

    /**
     * One line of text. (x, y) is the left end of the baseline (or its centre / right end with `align`).
     * @param array{color?: string|array|null, bold?: bool, italic?: bool, align?: 'left'|'center'|'right'} $options
     *   bold / italic are synthesized (thickened outline / slant); register a bold font for real bold.
     */
    public function text(string $text, float $x, float $y, Font $font, float $pt, array $options = []): self
    {
        self::checkKeys($options, self::TEXT_KEYS, 'text');
        if ($text === '' || $pt <= 0) return $this;
        $this->doc->assertOwnFont($font);
        $size = $pt * 25.4 / 72 * $this->unitsPerMm;
        $align = $options['align'] ?? 'left';
        if ($align === 'center' || $align === 'right') {
            $w = $font->width($text, $pt) * $this->unitsPerMm;
            $x -= $align === 'center' ? $w / 2 : $w;
        } elseif ($align !== 'left') {
            throw new PdfException("text(): align must be left, center or right");
        }
        $color = Color::parse(array_key_exists('color', $options) ? $options['color'] : '#000000');
        if ($color === null) return $this;
        $bold = !empty($options['bold']) ? sprintf('%s RG 2 Tr %s w ', self::rgb($color), self::n($size * 0.03)) : '';
        $this->ops[] = sprintf('q %s rg %sBT /%s %s Tf 1 0 %s -1 %s %s Tm <%s> Tj ET Q',
            self::rgb($color), $bold, $font->resourceName, self::n($size), !empty($options['italic']) ? '0.2' : '0',
            self::n($x), self::n($y), $font->encode($text));
        return $this;
    }

    /** Draw inside a rotation of $deg degrees clockwise around (cx, cy). */
    public function rotate(float $deg, float $cx, float $cy, callable $draw): self
    {
        $r = deg2rad($deg);
        $c = cos($r);
        $s = sin($r);
        $this->ops[] = 'q';
        $this->ops[] = sprintf('1 0 0 1 %s %s cm %s %s %s %s 0 0 cm 1 0 0 1 %s %s cm',
            self::n($cx), self::n($cy), self::n($c), self::n($s), self::n(-$s), self::n($c), self::n(-$cx), self::n(-$cy));
        $draw($this);
        $this->ops[] = 'Q';
        return $this;
    }

    /** @return array{0:string,1:string}|null [state operators, paint operator] */
    private function paint(array $style): ?array
    {
        $fill = Color::parse($style['fill'] ?? null);
        $stroke = Color::parse(array_key_exists('stroke', $style) ? $style['stroke'] : '#000000');
        $lw = (float) ($style['line_width'] ?? 0.2);
        $hasStroke = $stroke !== null && $lw > 0;
        if ($fill === null && !$hasStroke) return null;
        $pre = [];
        if ($fill !== null) $pre[] = self::rgb($fill) . ' rg';
        if ($hasStroke) {
            $pre[] = self::rgb($stroke) . ' RG';
            $pre[] = self::n($lw) . ' w';
        }
        return [implode(' ', $pre), $fill !== null ? ($hasStroke ? 'B' : 'f') : 'S'];
    }

    private static function checkKeys(array $given, array $allowed, string $method): void
    {
        $unknown = array_diff(array_keys($given), $allowed);
        if ($unknown) throw new PdfException(sprintf('%s(): unknown option "%s" (allowed: %s)', $method, implode('", "', $unknown), implode(', ', $allowed)));
    }

    private static function curve(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3): string
    {
        return implode(' ', array_map([self::class, 'n'], [$x1, $y1, $x2, $y2, $x3, $y3])) . ' c';
    }

    private static function rgb(array $c): string
    {
        return self::n($c[0]) . ' ' . self::n($c[1]) . ' ' . self::n($c[2]);
    }

    /** @internal A number as written in PDF (up to 4 decimals). */
    public static function n(float $v): string
    {
        $s = rtrim(rtrim(sprintf('%.4F', $v), '0'), '.');
        return ($s === '-0' || $s === '') ? '0' : $s;
    }
}
