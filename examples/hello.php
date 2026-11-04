<?php
// php examples/hello.php path/to/font.ttf > hello.pdf
require __DIR__ . '/../vendor/autoload.php';

use SailingO\PdfCanvas\Document;

$ttf = $argv[1] ?? null;
if ($ttf === null || !is_file($ttf)) {
    fwrite(STDERR, "usage: php examples/hello.php <font.ttf> > hello.pdf\n");
    exit(1);
}

$doc = new Document(['producer' => 'pdf-canvas example', 'title' => 'Hello']);
$font = $doc->addTrueType(file_get_contents($ttf));
$page = $doc->addPage(210, 297);                           // A4, millimetres, origin top-left

$page->rect(15, 15, 180, 30, ['fill' => '#eef2ff', 'stroke' => '#1e3a8a', 'line_width' => 0.5, 'radius' => 3])
     ->text('Hello, PDF — こんにちは', 22, 34, $font, 20, ['color' => '#1e3a8a'])
     ->text('right aligned 1,234,567', 188, 42, $font, 9, ['align' => 'right'])
     ->line(15, 55, 195, 55, ['stroke' => '#dc2626', 'dash' => [3, 1.5]])
     ->circle(40, 80, 15, ['fill' => '#fde68a', 'stroke' => '#92400e'])
     ->rotate(-15, 120, 80, fn ($p) => $p->rect(95, 70, 50, 20, ['fill' => '#16a34a', 'stroke' => null]));

$width = $font->width('Hello, PDF — こんにちは', 20);       // millimetres, for your own layout
$page->line(22, 36, 22 + $width, 36, ['stroke' => '#1e3a8a', 'line_width' => 0.3]);

echo $doc->render();
