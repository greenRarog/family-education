<?php

declare(strict_types=1);

namespace App\Enums;

enum AdvertisementType: string
{
    case FAMILY_TO_FAMILY = 'family_to_family';
    case FAMILY_TO_TEACHER = 'family_to_teacher';
    case TEACHER_TO_SERVICE = 'teacher_to_service';

    public function allowsResponsesFrom(UserType $userType): bool
    {
        return $userType === match ($this) {
            self::FAMILY_TO_FAMILY, self::TEACHER_TO_SERVICE => UserType::FAMILY,
            self::FAMILY_TO_TEACHER => UserType::TEACHER,
        };
    }
}
