<?php

namespace App\Enums;

enum UserType: string
{
    case FAMILY = 'family';
    case TEACHER = 'teacher';
    case ADMIN = 'admin';
}
