<?php

declare(strict_types=1);

namespace App\Withdrawing\Tests\Entity;

use App\Withdrawing\Entity\WithdrawalSettlementEventEntity;
use App\Withdrawing\Enum\WithdrawalSettlementOutcome;
use PHPUnit\Framework\TestCase;

final class WithdrawalSettlementEventEntityTest extends TestCase
{
    public function testSettlementOutcomeVocabularyIsClosedAndPersistenceStable(): void
    {
        self::assertSame(
            ['received', 'processed', 'ignored', 'failed'],
            array_map(
                static fn (WithdrawalSettlementOutcome $outcome): string => $outcome->value,
                WithdrawalSettlementOutcome::cases(),
            ),
        );
    }

    public function testConstructionNormalizesProviderAndPayloadIdentity(): void
    {
        $hash = str_repeat('A', 64);
        $event = new WithdrawalSettlementEventEntity(
            ' Stripe ',
            ' evt_123 ',
            ' payout.paid ',
            ' rail-123 ',
            $hash,
        );

        self::assertSame('stripe', $event->provider());
        self::assertSame('evt_123', $event->providerEventId());
        self::assertSame('payout.paid', $event->eventType());
        self::assertSame('rail-123', $event->railReference());
        self::assertSame(strtolower($hash), $event->payloadHash());
        self::assertSame('received', $event->outcome());
        self::assertNull($event->failureCode());
        self::assertNull($event->failureMessage());
        self::assertNull($event->processedAt());
        self::assertNotSame('', $event->id()->toRfc4122());
    }

    public function testProcessedOutcomePreservesSettlementFailureDiagnostics(): void
    {
        $event = $this->event();

        $event->markProcessed(' bank_rejected ', ' Destination rejected ');

        self::assertSame('processed', $event->outcome());
        self::assertSame('bank_rejected', $event->failureCode());
        self::assertSame('Destination rejected', $event->failureMessage());
        self::assertNotNull($event->processedAt());
        self::assertLessThanOrEqual($event->processedAt(), $event->createdAt());
    }

    public function testIgnoredAndFailedOutcomesAreRecordedForHostRetryPolicy(): void
    {
        $ignored = $this->event();
        $ignored->markIgnored();
        self::assertSame('ignored', $ignored->outcome());
        self::assertNotNull($ignored->processedAt());

        $failed = $this->event();
        $failed->markFailed(' settlement_failed ', ' Temporary provider failure ');
        self::assertSame('failed', $failed->outcome());
        self::assertSame('settlement_failed', $failed->failureCode());
        self::assertSame('Temporary provider failure', $failed->failureMessage());
        self::assertNotNull($failed->processedAt());
    }

    public function testInvalidSettlementIdentityIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WithdrawalSettlementEventEntity('', 'evt_123', 'payout.paid', null, 'not-a-sha256');
    }

    public function testBlankRailReferenceNormalizesToNullAndProcessedDiagnosticsMayBeAbsent(): void
    {
        $event = new WithdrawalSettlementEventEntity('stripe', 'evt_456', 'payout.paid', ' ', str_repeat('b', 64));
        self::assertNull($event->railReference());

        $event->markProcessed(' ', ' ');
        self::assertSame('processed', $event->outcome());
        self::assertNull($event->failureCode());
        self::assertNull($event->failureMessage());
        self::assertNotNull($event->processedAt());
    }

    public function testFailedOutcomeRequiresNonBlankFailureCodeBeforeStateMutation(): void
    {
        $event = $this->event();

        try {
            $event->markFailed(' ', 'Provider rejected');
            self::fail('Expected a blank settlement failure code to be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame('received', $event->outcome());
            self::assertNull($event->failureCode());
            self::assertNull($event->failureMessage());
            self::assertNull($event->processedAt());
        }
    }

    public function testFailedOutcomeNormalizesBlankOptionalFailureMessageToNull(): void
    {
        $event = $this->event();

        $event->markFailed('provider_rejected', ' ');

        self::assertSame('failed', $event->outcome());
        self::assertSame('provider_rejected', $event->failureCode());
        self::assertNull($event->failureMessage());
        self::assertNotNull($event->processedAt());
    }

    public function testPersistedIdentityLengthLimitsAreRejectedBeforeFlush(): void
    {
        foreach ([
            [str_repeat('p', 33), 'evt_123', 'payout.paid', null],
            ['stripe', str_repeat('e', 192), 'payout.paid', null],
            ['stripe', 'evt_123', str_repeat('t', 97), null],
            ['stripe', 'evt_123', 'payout.paid', str_repeat('r', 192)],
        ] as [$provider, $providerEventId, $eventType, $railReference]) {
            try {
                new WithdrawalSettlementEventEntity($provider, $providerEventId, $eventType, $railReference, str_repeat('a', 64));
                self::fail('Expected persistence length validation to reject settlement identity.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testFailureCodeLengthLimitIsRejectedBeforeFlush(): void
    {
        $processed = $this->event();
        try {
            $processed->markProcessed(str_repeat('c', 129), 'Provider rejected');
            self::fail('Expected processed failure code length validation.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        $failed = $this->event();
        $this->expectException(\InvalidArgumentException::class);
        $failed->markFailed(str_repeat('c', 129), 'Provider rejected');
    }

    public function testTerminalSettlementOutcomeCannotBeOverwritten(): void
    {
        foreach (['processed', 'ignored', 'failed'] as $terminalOutcome) {
            $event = $this->event();
            match ($terminalOutcome) {
                'processed' => $event->markProcessed(),
                'ignored' => $event->markIgnored(),
                'failed' => $event->markFailed('provider_failed', 'Provider rejected'),
            };

            foreach (['processed', 'ignored', 'failed'] as $nextOutcome) {
                try {
                    match ($nextOutcome) {
                        'processed' => $event->markProcessed(),
                        'ignored' => $event->markIgnored(),
                        'failed' => $event->markFailed('retry_failed', 'Retry rejected'),
                    };
                    self::fail(sprintf('Expected terminal %s outcome to reject %s overwrite.', $terminalOutcome, $nextOutcome));
                } catch (\LogicException) {
                    self::assertSame($terminalOutcome, $event->outcome());
                }
            }
        }
    }

    private function event(): WithdrawalSettlementEventEntity
    {
        return new WithdrawalSettlementEventEntity('stripe', 'evt_123', 'payout.paid', 'rail-123', str_repeat('a', 64));
    }
}
