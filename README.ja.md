# pdf-canvas

座標を指定して PDF を描く PHP ライブラリです。矩形・線・円・画像・文字を描け、TrueType の書体は使った字だけを埋め込みます（日本語・中国語・韓国語も扱えます）。PHP だけで書かれており、依存するライブラリも一時ファイルもありません。

[English](README.md)

```php
use SailingO\PdfCanvas\Document;

$doc  = new Document(['producer' => 'My App', 'title' => '請求書']);
$font = $doc->addTrueType(file_get_contents('your-font.ttf'));
$page = $doc->addPage(210, 297);                    // A4 (mm)。原点は左上

$page->rect(10, 10, 190, 20, ['fill' => '#eef2ff', 'stroke' => null])
     ->text('請求書 Invoice', 15, 24, $font, 18)
     ->text('¥1,234,567', 195, 24, $font, 12, ['align' => 'right']);

file_put_contents('invoice.pdf', $doc->render());
```

## 特徴

- **小さく、動きが読める。** 描けと指示した物を、指示した位置に描きます。HTML / CSS の組版はしないので、思わぬ位置にずれることがありません。
- **日本語がそのまま出る。** 書体は `Type0 / CIDFontType2`（`Identity-H`）として、使った字形だけを埋め込みます。5 MB の日本語書体でも、増えるのはたいてい 20〜60 KB です。`ToUnicode` を付けるので、出来た PDF の文字は検索もコピーもできます。
- **外を読まない。** ライブラリはファイルも URL も開きません。書体と画像はバイト列で渡し、PDF はバイト列で返ります。何を読むかは呼び出し側が決めるので、安全とライセンスの話が単純になります。
- **MIT ライセンス。**

## 必要なもの

- PHP 8.2 以上と、`ext-mbstring` / `ext-zlib`
- 任意: `ext-gd`。GIF・WebP の画像と、そのままでは埋め込めない PNG（インターレース・16 bit・透過つきのパレット）を扱うときに使います

```sh
composer require sailing-o/pdf-canvas
```

Packagist に登録するまでは、先にリポジトリを指定してください:

```sh
composer config repositories.pdf-canvas vcs https://github.com/sailing-o/pdf-canvas-dev
composer require sailing-o/pdf-canvas:^0.1
```

## API（1.0 の契約）

ここに載っていないものは `@internal` で、予告なく変わることがあります。

### `Document`

| | |
|---|---|
| `new Document(array $options = [])` | 指定できるのは `producer` / `title` / `author` / `subject`（文字列）と `compress`（真偽値、既定は `true`）です。知らないキーを渡すと例外になります。 |
| `addTrueType(string\|TrueType $font): Font` | `.ttf` の書体を登録します。バイト列と、解析済みの `TrueType` のどちらも渡せます。同じ `TrueType` を 2 回登録すると、同じ `Font` が返ります。 |
| `addPage(float $width, float $height, array $options = []): Page` | 大きさは mm で指定します。`['units_per_mm' => 96 / 25.4]` を渡すと、大きさも座標もその単位（この例では CSS の px）になります。 |
| `pages(): list<Page>` | |
| `render(): string` | PDF のバイト列を返します。ページが無いときは、白紙の A4 を 1 枚にします。 |

### `Page`

座標の原点は**左上**で、右と**下**に向かって増えます。線の太さや角の丸みなどの長さはページの単位で、文字の大きさだけは常に pt です。どのメソッドもページを返すので、続けて呼べます。

| | |
|---|---|
| `rect($x, $y, $w, $h, $style = [])` | 指定できるのは `fill`（既定は塗りなし）、`stroke`（既定は黒）、`line_width`（既定は 0.2）、`radius`（角の丸み）です。 |
| `circle($cx, $cy, $r, $style = [])` / `ellipse($cx, $cy, $rx, $ry, $style = [])` | 指定できるのは `fill` / `stroke` / `line_width` です。 |
| `line($x1, $y1, $x2, $y2, $style = [])` | 指定できるのは `stroke`（既定は黒）、`line_width`（既定は 0.2）、`dash`（例: `[3, 1.5]`）です。 |
| `image(string\|Image $image, $x, $y, $w, $h)` | JPEG / PNG のバイト列（または `Image`）を、枠いっぱいに引き伸ばして描きます。PNG の透過は残ります。 |
| `text($text, $x, $y, Font $font, float $pt, $options = [])` | 文字を 1 行描きます。`(x, y)` はベースライン上の点です。指定できるのは `color`、`align`（`left` / `center` / `right`、`x` が基準）、`bold` / `italic` です。`bold` / `italic` は疑似の太字・斜体なので、本当の太字にしたいときは太字の `.ttf` を登録してください。 |
| `rotate($deg, $cx, $cy, callable $draw)` | `$draw($page)` の中で描いた物を、`(cx, cy)` を中心に時計回りに `$deg` 度回します。 |
| `width` / `height` / `unitsPerMm` | 読み取り専用のプロパティです。 |

色は `'#rgb'`、`'#rrggbb'`、`'#rrggbbaa'`、`'rgb(r, g, b)'`、CSS の基本の色名、`[r, g, b]`（0〜255）のどれでも書けます。`null` / `'none'` / `'transparent'` / 透明度 0 は「塗らない・線を引かない」の意味になります。

知らないオプションのキーは `PdfException` になるので、綴りの誤りに気付かないまま進むことはありません。

### `Font` / `TrueType` / `Image`

| | |
|---|---|
| `Font::width(string $text, float $pt): float` | 文字列の幅を mm で返します。送り幅の和で、カーニングはしません。折り返しや寄せを自分で組むときに使います。 |
| `Font::covers(string $text): bool` | 書体がすべての字の字形を持っているかを返します。 |
| `TrueType::parse(string $bytes): TrueType` | 書体を解析します。結果は変化しないので、取っておけば文書をまたいで使い回せます（大きな日本語の書体だと、解析に数 ms かかります）。 |
| `TrueType::width(string $text, float $pt): float` | `Font::width` と同じ計算を、文書無しで行います。 |
| `Image::fromBytes(string $bytes): Image` / `Image::tryFromBytes(...): ?Image` | 画像を 1 回だけ解釈して、使い回せる形にします。`width` / `height` はピクセル数です。 |

使えない書体・画像や、誤った引数を渡すと `PdfException`（`RuntimeException` の子）になります。

## しないこと

- 組版（折り返し・改ページ・表）はしません。必要なら `Font::width()` を使って、呼び出し側で組んでください。
- カーニング、合字、複雑な文字体系（アラビア文字・インド系の文字）、右から左へ書く文字、カラー絵文字は扱いません。書体に無い字は、その書体の `.notdef`（いわゆる豆腐）で描きます。
- CFF の輪郭を持つ OpenType（`.otf`）と、書体の集まり（`.ttc`）は読めません。
- ベクタ画像（SVG / EPS）、PNG の透過以外の透明度、グラデーション、暗号化、フォームには対応していません。

埋め込みを禁止している書体（OS/2 の `fsType` が「制限付き」のもの）は、例外を投げて受け付けません。

## テスト

```sh
composer install
vendor/bin/phpunit
```

テストは小さな TrueType の書体をメモリ上で組み立てて使うので、リポジトリに書体のファイルはありません。描画のテストは、出来た PDF を Ghostscript（`gs`）でラスタ化し、画素の色を確かめます。Ghostscript が入っていなければ、このテストは飛ばします。

## ライセンス

MIT
