<?php

declare(strict_types=1);

namespace App\Enums;

enum AdminActionType: string
{
    case USER_BLOCKED = 'user_blocked';
    case USER_UNBLOCKED = 'user_unblocked';
    case ADVERTISEMENT_HIDDEN = 'advertisement_hidden';
    case ADVERTISEMENT_RESTORED = 'advertisement_restored';
    case ADVERTISEMENT_EXPIRED = 'advertisement_expired';
    case REPORT_RESOLVED = 'report_resolved';
    case REPORT_DISMISSED = 'report_dismissed';
    case APPEAL_REJECTED = 'appeal_rejected';

    public function targetType(): ?string
    {
        return match ($this) {
            self::USER_BLOCKED, self::USER_UNBLOCKED => 'user',
            self::ADVERTISEMENT_HIDDEN, self::ADVERTISEMENT_RESTORED, self::ADVERTISEMENT_EXPIRED => 'advertisement',
            self::REPORT_RESOLVED, self::REPORT_DISMISSED => 'report',
            self::APPEAL_REJECTED => null,
        };
    }
}
