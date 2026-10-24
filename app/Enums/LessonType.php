<?php

declare(strict_types=1);

namespace App\Enums;

enum LessonType: string
{
    case INDIVIDUAL = 'individual';
    case GROUP = 'group';
}
