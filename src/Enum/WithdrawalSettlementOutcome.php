<?php

declare(strict_types=1);

namespace App\Withdrawing\Enum;

enum WithdrawalSettlementOutcome: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';
}
