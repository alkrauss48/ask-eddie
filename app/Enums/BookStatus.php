<?php

namespace App\Enums;

enum BookStatus: string
{
    case Pending = 'pending';
    case Extracting = 'extracting';
    case Extracted = 'extracted';
    case Stale = 'stale';
    case Missing = 'missing';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Extracting => 'Extracting',
            self::Extracted => 'Extracted',
            self::Stale => 'Stale',
            self::Missing => 'Missing',
            self::Failed => 'Failed',
        };
    }
}
