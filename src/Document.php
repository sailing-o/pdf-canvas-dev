<?php

declare(strict_types=1);

namespace SailingO\PdfCanvas;

/**
 * A PDF document drawn with coordinates (PDF 1.4). No dependencies beyond ext-zlib and ext-mbstring,
 * no temporary files, and it never reads files or URLs: fonts and images are passed in as bytes.
 *
 *   $doc  = new Document(['producer' => 'My App', 'title' => 'Invoice']);
 *   $font = $doc->addTrueType(file_get_contents('your-font.ttf'));
 *   $page = $doc->addPage(210, 297);                       // A4 in mm, origin at the top-left
 *   $page->rect(10, 10, 190, 20, ['fill' => '#eef2ff'])
 *        ->text('Invoice 請求書', 15, 24, $font, 18);
 *   $pdf  = $doc->render();
 */
final class Document
{
    private const OPTION_KEYS = ['producer', 'title', 'author', 'subject', 'compress'];

    /** @var list<Page> */
    private array $pages = [];
    /** @var list<Font> */
    private array $fonts = [];
    /** @var array<string, array{img: Image, name: string}> content hash → image */
    private array $images = [];
    /** @var array<int, string> spl_object_id(Image) → resource name */
    private array $imageIds = [];
    private array $options;

    /** @param array{producer?: string, title?: string, author?: string, subject?: string, compress?: bool} $options */
    public function __construct(array $options = [])
    {
        $unknown = array_diff(array_keys($options), self::OPTION_KEYS);
        if ($unknown) throw new PdfException('Document: unknown option "' . implode('", "', $unknown) . '"');
        $this->options = $options + ['compress' => true];
    }

    /**
     * Register a TrueType font (.ttf bytes or a parsed TrueType). Only the glyphs drawn are embedded.
     * Parsing a large font takes a few milliseconds: keep a TrueType around to reuse it across documents.
     * @throws PdfException for fonts that are not TrueType or forbid embedding
     */
    public function addTrueType(string|TrueType $font): Font
    {
        $tt = is_string($font) ? TrueType::parse($font) : $font;
        foreach ($this->fonts as $f) {
            if ($f->trueType === $tt) return $f;
        }
        return $this->fonts[] = new Font($tt, 'F' . (count($this->fonts) + 1));
    }

    /**
     * Add a page of $width × $height. Sizes and coordinates are millimetres, or another unit given as
     * `units_per_mm` (e.g. 96 / 25.4 to draw in CSS pixels at 96 dpi).
     * @param array{units_per_mm?: float} $options
     */
    public function addPage(float $width, float $height, array $options = []): Page
    {
        $unknown = array_diff(array_keys($options), ['units_per_mm']);
        if ($unknown) throw new PdfException('addPage(): unknown option "' . implode('", "', $unknown) . '"');
        $u = (float) ($options['units_per_mm'] ?? 1.0);
        if ($width <= 0 || $height <= 0 || $u <= 0) throw new PdfException('addPage(): width, height and units_per_mm must be positive');
        return $this->pages[] = new Page($this, $width, $height, $u);
    }

    /** @return list<Page> */
    public function pages(): array
    {
        return $this->pages;
    }

    /** @internal */
    public function assertOwnFont(Font $font): void
    {
        if (!in_array($font, $this->fonts, true)) throw new PdfException('This font belongs to another document: register it with addTrueType()');
    }

    /** @internal resource name of an image (identical bytes are embedded once) */
    public function imageResource(Image $img): string
    {
        $oid = spl_object_id($img);
        if (isset($this->imageIds[$oid])) return $this->imageIds[$oid];
        $key = md5($img->data . "\0" . ($img->smask?->data ?? '') . "\0" . $img->width . 'x' . $img->height . $img->colorSpace);
        if (!isset($this->images[$key])) $this->images[$key] = ['img' => $img, 'name' => 'Im' . (count($this->images) + 1)];
        return $this->imageIds[$oid] = $this->images[$key]['name'];
    }

    /** The PDF bytes. A document without pages gets one blank A4 page. */
    public function render(): string
    {
        $pages = $this->pages ?: [new Page($this, 210, 297, 1.0)];
        $objs = [];
        $next = 1;
        $alloc = function () use (&$next): int { return $next++; };
        $catalog = $alloc();
        $pagesId = $alloc();
        $infoId = $alloc();
        $resId = $alloc();

        $fontRefs = [];
        foreach ($this->fonts as $f) {
            if ($f->usedGlyphs()) $fontRefs[$f->resourceName] = $this->writeFont($f, $alloc, $objs);
        }
        $xobj = [];
        foreach ($this->images as $im) $xobj[$im['name']] = $this->writeImage($im['img'], $alloc, $objs);
        $res = '<< /ProcSet [/PDF /Text /ImageB /ImageC /ImageI]';
        if ($fontRefs) $res .= ' /Font << ' . implode(' ', array_map(fn ($n, $r) => "/$n $r 0 R", array_keys($fontRefs), $fontRefs)) . ' >>';
        if ($xobj) $res .= ' /XObject << ' . implode(' ', array_map(fn ($n, $r) => "/$n $r 0 R", array_keys($xobj), $xobj)) . ' >>';
        $objs[$resId] = $res . ' >>';

        $kids = [];
        foreach ($pages as $p) {
            $pid = $alloc();
            $cid = $alloc();
            $kids[] = "$pid 0 R";
            $objs[$cid] = $this->stream('', $p->contents());
            $objs[$pid] = sprintf('<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %s %s] /Resources %d 0 R /Contents %d 0 R >>',
                $pagesId, Page::n($p->widthPt()), Page::n($p->heightPt()), $resId, $cid);
        }
        $objs[$pagesId] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $kids), count($kids));
        $objs[$catalog] = sprintf('<< /Type /Catalog /Pages %d 0 R >>', $pagesId);
        $info = '<< /Producer ' . self::textString((string) ($this->options['producer'] ?? 'pdf-canvas')) . ' /CreationDate (D:' . gmdate('YmdHis') . 'Z)';
        foreach (['title' => 'Title', 'author' => 'Author', 'subject' => 'Subject'] as $k => $key) {
            if (($this->options[$k] ?? '') !== '') $info .= " /$key " . self::textString((string) $this->options[$k]);
        }
        $objs[$infoId] = $info . ' >>';

        ksort($objs);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($out);
        $n = count($objs) + 1;
        $out .= "xref\n0 $n\n0000000000 65535 f \n";
        for ($i = 1; $i < $n; $i++) $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        $docId = md5($out);
        $out .= sprintf("trailer\n<< /Size %d /Root %d 0 R /Info %d 0 R /ID [<%s> <%s>] >>\nstartxref\n%d\n%%%%EOF\n",
            $n, $catalog, $infoId, $docId, $docId, $xref);
        return $out;
    }

    /** Type0 font with an embedded subset. Returns the Type0 object number. */
    private function writeFont(Font $f, callable $alloc, array &$objs): int
    {
        $tt = $f->trueType;
        $glyphs = $f->usedGlyphs();
        ksort($glyphs);
        $type0 = $alloc();
        $cid = $alloc();
        $desc = $alloc();
        $file = $alloc();
        $toUni = $alloc();
        // subset tag: six capital letters derived from the glyph set
        $h = md5(implode(',', array_keys($glyphs)) . $tt->postScriptName);
        $tag = '';
        for ($i = 0; $i < 6; $i++) $tag .= chr(65 + hexdec($h[$i]) % 26);
        $base = $tag . '+' . $tt->postScriptName;

        $ttf = $tt->subset(array_keys($glyphs), array_flip(array_filter($glyphs, fn ($cp, $g) => $g > 0, ARRAY_FILTER_USE_BOTH)));
        $objs[$file] = $this->stream('/Length1 ' . strlen($ttf), $ttf);
        $flags = 4 | ($tt->fixedPitch ? 1 : 0) | ($tt->italicAngle != 0.0 ? 64 : 0);
        [$x0, $y0, $x1, $y1] = $tt->bbox;
        $objs[$desc] = sprintf('<< /Type /FontDescriptor /FontName /%s /Flags %d /FontBBox [%d %d %d %d] /ItalicAngle %s /Ascent %d /Descent %d /CapHeight %d /StemV 80 /FontFile2 %d 0 R >>',
            $base, $flags, $tt->scale1000($x0), $tt->scale1000($y0), $tt->scale1000($x1), $tt->scale1000($y1),
            Page::n($tt->italicAngle), $tt->scale1000($tt->ascent), $tt->scale1000($tt->descent), $tt->scale1000($tt->capHeight), $file);
        // widths, consecutive glyph ids grouped: [g [w w w] g2 [w]]
        $w = [];
        $run = [];
        $start = null;
        $prev = null;
        foreach (array_keys($glyphs) as $g) {
            if ($prev !== null && $g === $prev + 1) {
                $run[] = $tt->advance1000($g);
            } else {
                if ($start !== null) $w[] = $start . ' [' . implode(' ', $run) . ']';
                $start = $g;
                $run = [$tt->advance1000($g)];
            }
            $prev = $g;
        }
        if ($start !== null) $w[] = $start . ' [' . implode(' ', $run) . ']';
        $objs[$cid] = sprintf('<< /Type /Font /Subtype /CIDFontType2 /BaseFont /%s /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor %d 0 R /DW %d /W [%s] /CIDToGIDMap /Identity >>',
            $base, $desc, $tt->advance1000(0), implode(' ', $w));
        $objs[$toUni] = $this->stream('', self::toUnicode($glyphs));
        $objs[$type0] = sprintf('<< /Type /Font /Subtype /Type0 /BaseFont /%s /Encoding /Identity-H /DescendantFonts [%d 0 R] /ToUnicode %d 0 R >>',
            $base, $cid, $toUni);
        return $type0;
    }

    private static function toUnicode(array $glyphs): string
    {
        $map = [];
        foreach ($glyphs as $g => $cp) {
            if ($g === 0) continue;
            $u = mb_convert_encoding(mb_chr($cp, 'UTF-8'), 'UTF-16BE', 'UTF-8');
            $map[] = sprintf('<%04X> <%s>', $g, strtoupper(bin2hex($u)));
        }
        $s = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";
        foreach (array_chunk($map, 100) as $chunk) $s .= count($chunk) . " beginbfchar\n" . implode("\n", $chunk) . "\nendbfchar\n";
        return $s . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
    }

    private function writeImage(Image $img, callable $alloc, array &$objs): int
    {
        $id = $alloc();
        $dict = sprintf('/Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s /BitsPerComponent %d',
            $img->width, $img->height, $img->colorSpace, $img->bits);
        if ($img->decodeParms) $dict .= ' /DecodeParms ' . $img->decodeParms;
        if ($img->decode) $dict .= ' /Decode ' . $img->decode;
        if ($img->mask) $dict .= ' /Mask ' . $img->mask;
        if ($img->smask) $dict .= ' /SMask ' . $this->writeImage($img->smask, $alloc, $objs) . ' 0 R';
        $objs[$id] = self::rawStream($dict, $img->data, $img->filter);
        return $id;
    }

    /** A stream of plain data, compressed unless the `compress` option is false. */
    private function stream(string $dict, string $data): string
    {
        return $this->options['compress'] ? self::rawStream($dict, gzcompress($data, 6), '/FlateDecode') : self::rawStream($dict, $data, null);
    }

    private static function rawStream(string $dict, string $data, ?string $filter): string
    {
        $d = trim($dict . ($filter ? " /Filter $filter" : '') . ' /Length ' . strlen($data));
        return "<< $d >>\nstream\n" . $data . "\nendstream";
    }

    /** Document information string (UTF-16BE with BOM). */
    private static function textString(string $s): string
    {
        return '<FEFF' . strtoupper(bin2hex(mb_convert_encoding($s, 'UTF-16BE', 'UTF-8'))) . '>';
    }
}
