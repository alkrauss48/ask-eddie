<?php

use App\Services\Books\TesseractOcr;

beforeEach(function (): void {
    $this->ocr = new TesseractOcr;
});

it('builds the expected argument list', function (): void {
    config(['books.binaries.tesseract' => '/usr/bin/tesseract']);

    $command = $this->ocr->command('/tmp/page-001.png', 'spa+eng', 6);

    expect($command)->toBe([
        '/usr/bin/tesseract',
        '/tmp/page-001.png',
        'stdout',
        '-l', 'spa+eng',
        '--oem', '1',
        '--psm', '6',
        '-c', 'preserve_interword_spaces=1',
    ]);
});

it('falls back to the configured page segmentation mode', function (): void {
    config(['books.ocr.page_segmentation_mode' => 3]);

    expect($this->ocr->command('/tmp/page.png', 'eng'))->toContain('3');
});

/**
 * Tesseract is multithreaded by default and grabs every core it can see.
 * Because pages are OCR'd by a pool of parallel Tesseract processes, leaving
 * this unset oversubscribes the CPU badly enough to run slower than serial.
 */
it('limits each process to a single thread', function (): void {
    expect($this->ocr->environment())->toBe(['OMP_THREAD_LIMIT' => '1']);
});
