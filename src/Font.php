<?php

declare(strict_types=1);

namespace SailingO\PdfCanvas;

/**
 * A TrueType font registered with one Document (Document::addTrueType). The document embeds it once,
 * as a subset of the glyphs actually drawn (Type0 / CIDFontType2, Identity-H, with a ToUnicode map so
 * the text can be searched and copied).
 */
final class Font
{
    /** @var array<int, int> glyph id → code point, for the glyphs drawn so far */
    private array $used = [];

    /** @internal use Document::addTrueType() */
    public function __construct(public readonly TrueType $trueType, public readonly string $resourceName) {}

    /** Width of a string in millimetres at $pt points. */
    public function width(string $text, float $pt): float
    {
        return $this->trueType->width($text, $pt);
    }

    /** True when the font has a glyph for every character of the string. */
    public function covers(string $text): bool
    {
        foreach (TrueType::codepoints($text) as $cp) {
            if (!$this->trueType->hasGlyph($cp)) return false;
        }
        return true;
    }

    /** @internal Encode a string as Identity-H glyph ids (hex) and remember the glyphs. */
    public function encode(string $text): string
    {
        $hex = '';
        foreach (TrueType::codepoints($text) as $cp) {
            $g = $this->trueType->glyphId($cp);
            if (!isset($this->used[$g])) $this->used[$g] = $cp;
            $hex .= sprintf('%04X', $g);
        }
        return $hex;
    }

    /** @internal @return array<int, int> */
    public function usedGlyphs(): array
    {
        return $this->used;
    }
}
