<?php

namespace App\Enums;

enum PageTextSource: string
{
    /**
     * Text lifted from the PDF's embedded text layer, which for this corpus
     * was produced by an older OCR pass and varies wildly in quality.
     */
    case TextLayer = 'text_layer';

    /**
     * Text produced by running Tesseract over a freshly rendered page image.
     */
    case Ocr = 'ocr';

    public function label(): string
    {
        return match ($this) {
            self::TextLayer => 'Text layer',
            self::Ocr => 'OCR',
        };
    }
}
