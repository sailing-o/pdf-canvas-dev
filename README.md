# pdf-canvas

Draw PDFs with coordinates: rectangles, lines, circles, images and text, with TrueType fonts embedded as subsets — Japanese, Chinese and Korean included. Pure PHP, no dependencies, no temporary files.

[日本語](README.ja.md)

```php
use SailingO\PdfCanvas\Document;

$doc  = new Document(['producer' => 'My App', 'title' => 'Invoice']);
$font = $doc->addTrueType(file_get_contents('your-font.ttf'));
$page = $doc->addPage(210, 297);                    // A4 in millimetres, origin at the top-left

$page->rect(10, 10, 190, 20, ['fill' => '#eef2ff', 'stroke' => null])
     ->text('Invoice 請求書', 15, 24, $font, 18)
     ->text('¥1,234,567', 195, 24, $font, 12, ['align' => 'right']);

file_put_contents('invoice.pdf', $doc->render());
```

## Why

- **Small and predictable.** It draws what you tell it to draw where you tell it to. There is no HTML or CSS layout engine, so there is nothing to second-guess.
- **CJK text that works.** TrueType fonts are embedded as `Type0 / CIDFontType2` with `Identity-H` encoding. Only the glyphs you use are included, so a 5 MB Japanese font usually adds 20–60 KB. A `ToUnicode` map keeps the text searchable and copyable.
- **No I/O.** The library never opens files or URLs. You pass font and image bytes in, and you get PDF bytes back. You decide where data comes from, which keeps the security and licensing story simple.
- **MIT licensed.**

## Requirements

- PHP 8.2+ with `ext-mbstring` and `ext-zlib`
- Optional: `ext-gd` for GIF and WebP images, and for PNGs that cannot be embedded as is (interlaced, 16-bit, or palette with transparency)

```sh
composer require sailing-o/pdf-canvas
```

## API (1.0 contract)

Everything not listed here is `@internal` and may change.

### `Document`

| | |
|---|---|
| `new Document(array $options = [])` | Options: `producer`, `title`, `author`, `subject` (strings) and `compress` (bool, default `true`). Unknown keys throw. |
| `addTrueType(string\|TrueType $font): Font` | Registers a `.ttf` font, from bytes or a parsed `TrueType`. Registering the same `TrueType` twice returns the same `Font`. |
| `addPage(float $width, float $height, array $options = []): Page` | Sizes are in millimetres. Pass `['units_per_mm' => 96 / 25.4]` to give sizes and coordinates in another unit (here, CSS pixels). |
| `pages(): list<Page>` | |
| `render(): string` | Returns the PDF bytes. A document without pages gets one blank A4 page. |

### `Page`

Coordinates start at the **top-left** and grow right and **down**. Lengths (line widths, radii) use the page unit. Font sizes are always in points. Every method returns the page, so you can chain calls.

| | |
|---|---|
| `rect($x, $y, $w, $h, $style = [])` | Style keys: `fill` (default none), `stroke` (default black), `line_width` (default 0.2), `radius` (rounded corners). |
| `circle($cx, $cy, $r, $style = [])` / `ellipse($cx, $cy, $rx, $ry, $style = [])` | Style keys: `fill`, `stroke`, `line_width`. |
| `line($x1, $y1, $x2, $y2, $style = [])` | Style keys: `stroke` (default black), `line_width` (default 0.2), `dash` (for example `[3, 1.5]`). |
| `image(string\|Image $image, $x, $y, $w, $h)` | Draws JPEG or PNG bytes (or an `Image`) stretched to fill the box. PNG transparency is kept. |
| `text($text, $x, $y, Font $font, float $pt, $options = [])` | Draws one line. `(x, y)` is on the baseline. Options: `color`, `align` (`left` / `center` / `right`, relative to `x`), and `bold` / `italic`, which are synthesized; register a bold `.ttf` for real bold. |
| `rotate($deg, $cx, $cy, callable $draw)` | Anything drawn inside `$draw($page)` is rotated `$deg` degrees clockwise around `(cx, cy)`. |
| `width`, `height`, `unitsPerMm` | Read-only properties. |

Colors can be `'#rgb'`, `'#rrggbb'`, `'#rrggbbaa'`, `'rgb(r, g, b)'`, a basic CSS name, or `[r, g, b]` with values from 0 to 255. `null`, `'none'`, `'transparent'` and zero alpha all mean "do not paint".

Unknown option keys throw `PdfException`, so a typo never fails silently.

### `Font` / `TrueType` / `Image`

| | |
|---|---|
| `Font::width(string $text, float $pt): float` | Width in millimetres: the sum of advance widths, without kerning. Use it for your own wrapping and alignment. |
| `Font::covers(string $text): bool` | Whether the font has a glyph for every character. |
| `TrueType::parse(string $bytes): TrueType` | Parses a font once. The result is immutable, so keep it and reuse it across documents to save the parsing time (a few ms for a large CJK font). |
| `TrueType::width(string $text, float $pt): float` | Same as `Font::width`, without a document. |
| `Image::fromBytes(string $bytes): Image` / `Image::tryFromBytes(...): ?Image` | Decodes an image once so it can be reused. `width` and `height` are in pixels. |

`PdfException` (a `RuntimeException`) is thrown for unusable fonts and images and for invalid arguments.

## What it does not do

- Layout: wrapping, pagination and tables. Build them on top using `Font::width()`.
- Kerning, ligatures, complex scripts (Arabic, Indic), right-to-left text and color emoji. Characters the font does not have are drawn as its `.notdef` glyph.
- OpenType fonts with CFF outlines (`.otf`) and font collections (`.ttc`).
- Vector images (SVG, EPS), transparency other than PNG alpha, gradients, encryption, forms.

Fonts whose license flags forbid embedding (OS/2 `fsType` = restricted) are rejected.

## Testing

```sh
composer install
vendor/bin/phpunit
```

The tests build a tiny TrueType font in memory, so the repository contains no font files. The render tests rasterize the output with Ghostscript (`gs`) and check pixels. They are skipped when Ghostscript is not installed.

## License

MIT
