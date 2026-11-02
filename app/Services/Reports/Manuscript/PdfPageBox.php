<?php

namespace App\Services\Reports\Manuscript;

use Smalot\PdfParser\Element;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\PDFObject;

/**
 * The visible box of a page (CropBox, else MediaBox, clipped to the MediaBox) and its rotation.
 * Values missing on the page are read from its `Parent` chain. Must agree with the box mPDF imports.
 */
final readonly class PdfPageBox
{
    public function __construct(
        public float $x1,
        public float $y1,
        public float $x2,
        public float $y2,
        public int $rotation,
    ) {}

    public static function fromPage(Page $page): self
    {
        $media = self::numbers($page, 'MediaBox') ?? [0.0, 0.0, 612.0, 792.0];
        $crop = self::numbers($page, 'CropBox') ?? $media;

        $x1 = max($crop[0], $media[0]);
        $y1 = max($crop[1], $media[1]);
        $x2 = min($crop[2], $media[2]);
        $y2 = min($crop[3], $media[3]);

        if ($x2 <= $x1 || $y2 <= $y1) {
            [$x1, $y1, $x2, $y2] = $media;
        }

        $rotate = self::inherited($page, 'Rotate');
        $rotation = is_numeric($rotate) ? (((int) $rotate % 360) + 360) % 360 : 0;

        return new self($x1, $y1, $x2, $y2, $rotation);
    }

    public function width(): float
    {
        return $this->x2 - $this->x1;
    }

    public function height(): float
    {
        return $this->y2 - $this->y1;
    }

    /** @return array{0: float, 1: float, 2: float, 3: float}|null corners ordered so x1 < x2 and y1 < y2 */
    private static function numbers(PDFObject $page, string $key): ?array
    {
        $value = self::inherited($page, $key);
        if (! is_array($value) || count($value) < 4) {
            return null;
        }

        $n = [];
        foreach (array_slice(array_values($value), 0, 4) as $item) {
            $raw = $item instanceof Element ? $item->getContent() : $item;
            if (! is_numeric($raw)) {
                return null;
            }
            $n[] = (float) $raw;
        }

        return [min($n[0], $n[2]), min($n[1], $n[3]), max($n[0], $n[2]), max($n[1], $n[3])];
    }

    private static function inherited(PDFObject $object, string $key): mixed
    {
        for ($depth = 0; $depth < 20; $depth++) {
            $header = $object->getHeader();
            if ($header === null) {
                return null;
            }
            if ($header->has($key)) {
                $found = $header->get($key);

                return $found instanceof Element ? $found->getContent() : $found;
            }
            $parent = $header->has('Parent') ? $header->get('Parent') : null;
            if (! $parent instanceof PDFObject) {
                return null;
            }
            $object = $parent;
        }

        return null;
    }
}
