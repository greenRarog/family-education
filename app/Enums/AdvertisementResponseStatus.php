<?php

declare(strict_types=1);

namespace App\Enums;

enum AdvertisementResponseStatus: string
{
    case SENT = 'sent';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
}
