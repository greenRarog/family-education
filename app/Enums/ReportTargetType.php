<?php

declare(strict_types=1);

namespace App\Enums;

enum ReportTargetType: string
{
    case ADVERTISEMENT = 'advertisement';
    case USER = 'user';
}
