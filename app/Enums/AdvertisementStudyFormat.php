<?php

declare(strict_types=1);

namespace App\Enums;

enum AdvertisementStudyFormat: string
{
    case OFFLINE = 'offline';
    case ONLINE = 'online';
    case HYBRID = 'hybrid';
}
