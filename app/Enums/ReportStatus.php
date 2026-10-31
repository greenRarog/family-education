<?php

declare(strict_types=1);

namespace App\Enums;

enum ReportStatus: string
{
    case OPEN = 'open';
    case RESOLVED = 'resolved';
    case DISMISSED = 'dismissed';
}
