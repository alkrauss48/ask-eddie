<?php

namespace App\Enums;

enum PageStatus: string
{
    case Pending = 'pending';
    case Extracted = 'extracted';

    /**
     * Every candidate ran successfully and every one came back empty. Blank
     * leaves and plate versos are a normal part of these scans, so this is an
     * outcome rather than an error.
     */
    case Blank = 'blank';

    case Failed = 'failed';
}
