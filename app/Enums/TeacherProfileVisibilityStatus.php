<?php

declare(strict_types=1);

namespace App\Enums;

enum TeacherProfileVisibilityStatus: string
{
    case PRIVATE = 'private';
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
}
