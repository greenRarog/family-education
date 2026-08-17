<?php

declare(strict_types=1);

namespace App\Enums;

enum AdvertisementStatus: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
    case CLOSED = 'closed';
}
