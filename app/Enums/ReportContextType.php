<?php

declare(strict_types=1);

namespace App\Enums;

enum ReportContextType: string
{
    case ADVERTISEMENT = 'advertisement';
    case TEACHER_PROFILE = 'teacher_profile';
    case CONVERSATION = 'conversation';
}
