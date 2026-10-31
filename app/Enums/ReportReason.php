<?php

declare(strict_types=1);

namespace App\Enums;

enum ReportReason: string
{
    case SPAM = 'spam';
    case FRAUD = 'fraud';
    case ABUSE = 'abuse';
    case UNSAFE_CONTENT = 'unsafe_content';
    case PERSONAL_DATA = 'personal_data';
    case OFF_TOPIC = 'off_topic';
    case OTHER = 'other';
}
