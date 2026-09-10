<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Source Disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk holding the source PDFs. Configure its root with the
    | BOOKS_PATH environment variable; see the "books" disk in the filesystem
    | configuration file.
    |
    */

    'disk' => env('BOOKS_DISK', 'books'),

    /*
    |--------------------------------------------------------------------------
    | Working Directory
    |--------------------------------------------------------------------------
    |
    | Scratch space for PDF copies and rendered page images. This must be on the
    | container's own filesystem rather than inside the project, because the
    | project is a bind mount and page rendering re-reads the source PDF once
    | per page. See LocalPdfWorkspace.
    |
    */

    'temp_path' => env('BOOKS_TEMP_PATH', '/tmp/ask-eddie'),

    /*
    |--------------------------------------------------------------------------
    | Binaries
    |--------------------------------------------------------------------------
    |
    | Absolute paths may be supplied here if these binaries are not on the
    | PATH. They are installed into the Sail image by the OCR layer of
    | the Dockerfile, so the defaults are correct under Sail.
    |
    */

    'binaries' => [
        'pdfinfo' => env('BOOKS_PDFINFO_BIN', 'pdfinfo'),
        'pdftotext' => env('BOOKS_PDFTOTEXT_BIN', 'pdftotext'),
        'pdftoppm' => env('BOOKS_PDFTOPPM_BIN', 'pdftoppm'),
        'tesseract' => env('BOOKS_TESSERACT_BIN', 'tesseract'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Extraction
    |--------------------------------------------------------------------------
    |
    | "concurrency" controls how many pages are processed in parallel. Pages
    | are handled in chunks of this size because a process pool starts every
    | process it is given at once, which would overwhelm the container.
    |
    */

    'extraction' => [
        'concurrency' => (int) env('BOOKS_CONCURRENCY', 8),
        'timeout' => (int) env('BOOKS_PAGE_TIMEOUT', 180),
    ],

    /*
    |--------------------------------------------------------------------------
    | OCR
    |--------------------------------------------------------------------------
    |
    | Page images are rendered at "dpi" before being handed to Tesseract. The
    | page segmentation mode of 1 requests automatic page segmentation with
    | orientation and script detection, which suits these scanned books.
    |
    */

    'ocr' => [
        'dpi' => (int) env('BOOKS_OCR_DPI', 300),
        'page_segmentation_mode' => (int) env('BOOKS_OCR_PSM', 1),
        'default_language' => env('BOOKS_OCR_LANGUAGE', 'eng'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Extraction Strategy
    |--------------------------------------------------------------------------
    |
    | "ocr_all" renders and OCRs every page, keeping the PDF's embedded text
    | layer alongside as a second candidate. "gated" only OCRs pages whose text
    | layer scores below the threshold below, which is faster but inherits the
    | text layer's mistakes everywhere else.
    |
    | The default is "ocr_all" because the embedded layer in this corpus is a
    | pre-LSTM OCR pass that renders, for instance, "ROCHESTER PUNCH" as
    | "KOCllESTKli rUKCll.". Re-running the whole corpus costs about an hour of
    | local CPU, once, and both candidates are kept either way.
    |
    */

    'strategy' => env('BOOKS_STRATEGY', 'ocr_all'),

    /*
    |--------------------------------------------------------------------------
    | Text Quality
    |--------------------------------------------------------------------------
    |
    | Pages are scored between 0 and 1 on how much of their text looks like real
    | language. The score ranks a page's two candidates against each other and
    | flags badly degraded pages; see PageTextQuality for measured values.
    |
    | "min_score" is a severity floor, not a fine-grained gate: observed scores
    | run about 0.58 for scanner noise, 0.84 for dot-leader index pages, and
    | 0.94 upwards for readable prose. It only takes effect under the "gated"
    | strategy. A page must also yield "min_characters" characters before its
    | score means anything at all.
    |
    */

    'quality' => [
        'min_score' => (float) env('BOOKS_QUALITY_MIN_SCORE', 0.90),
        'min_characters' => (int) env('BOOKS_QUALITY_MIN_CHARACTERS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Catalog Overrides
    |--------------------------------------------------------------------------
    |
    | Metadata is derived from filenames, which is right for the whole corpus
    | but cannot know what language a book is written in. Anything set here,
    | keyed by filename with or without the extension, wins over the parsed
    | value. Supported keys: title, author, year, edition, language.
    |
    | Language codes are Tesseract's, and may be combined with a plus sign for
    | books that mix two.
    |
    */

    'catalog' => [
        'Manual del Cantinero by León Pujol and Oscar Muñiz (1924)' => [
            'language' => 'spa',
        ],
        '1000 Misture by Elvezio Grassi (1936)' => [
            'language' => 'ita',
        ],
        'Bariana by Louis Fouquet (1896)' => [
            'language' => 'fra',
        ],
        'Cocktails Bar La Florida by Constante Ribalaigua Vert (1934)' => [
            'language' => 'spa+eng',
        ],
        'The Artistry of Mixing Drinks by Frank Meier (1936)' => [
            // Written in English at the Ritz in Paris, so it is dense with
            // French drink names and proper nouns.
            'language' => 'eng+fra',
        ],
    ],

];
