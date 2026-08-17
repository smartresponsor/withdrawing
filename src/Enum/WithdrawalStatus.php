<?php

declare(strict_types=1);

namespace App\Withdrawing\Enum;

enum WithdrawalStatus: string
{
    case Pending = 'pending';
    case Reserved = 'reserved';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Reversed = 'reversed';
}
