<?php

declare(strict_types=1);

namespace App\Withdrawing\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'withdrawal_settlement_event')]
#[ORM\UniqueConstraint(name: 'uniq_withdrawal_settlement_provider_event', columns: ['provider', 'provider_event_id'])]
#[ORM\Index(name: 'idx_withdrawal_settlement_rail_reference', columns: ['rail_reference'])]
#[ORM\Index(name: 'idx_withdrawal_settlement_outcome_created', columns: ['outcome', 'created_at'])]
class WithdrawalSettlementEventEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 32)] private string $provider;
    #[ORM\Column(name: 'provider_event_id', length: 191)] private string $providerEventId;
    #[ORM\Column(name: 'event_type', length: 96)] private string $eventType;
    #[ORM\Column(name: 'rail_reference', length: 191, nullable: true)] private ?string $railReference;
    #[ORM\Column(name: 'payload_hash', length: 64)] private string $payloadHash;
    #[ORM\Column(length: 32)] private string $outcome = 'received';
    #[ORM\Column(name: 'failure_code', length: 128, nullable: true)] private ?string $failureCode = null;
    #[ORM\Column(name: 'failure_message', type: 'text', nullable: true)] private ?string $failureMessage = null;
    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')] private \DateTimeImmutable $createdAt;
    #[ORM\Column(name: 'processed_at', type: 'datetime_immutable', nullable: true)] private ?\DateTimeImmutable $processedAt = null;

    public function __construct(string $provider, string $providerEventId, string $eventType, ?string $railReference, string $payloadHash)
    {
        $provider = strtolower(trim($provider));
        $providerEventId = trim($providerEventId);
        $eventType = trim($eventType);
        $railReference = null === $railReference ? null : trim($railReference);
        $payloadHash = strtolower(trim($payloadHash));
        if ('' === $provider || '' === $providerEventId || '' === $eventType || 1 !== preg_match('/^[a-f0-9]{64}$/', $payloadHash)) {
            throw new \InvalidArgumentException('Withdrawal settlement event identity is invalid.');
        }
        self::assertMaxLength($provider, 32, 'provider');
        self::assertMaxLength($providerEventId, 191, 'provider event id');
        self::assertMaxLength($eventType, 96, 'event type');
        if (null !== $railReference && '' !== $railReference) {
            self::assertMaxLength($railReference, 191, 'rail reference');
        }
        $this->id = Uuid::v7();
        $this->provider = $provider;
        $this->providerEventId = $providerEventId;
        $this->eventType = $eventType;
        $this->railReference = '' === $railReference ? null : $railReference;
        $this->payloadHash = $payloadHash;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function provider(): string
    {
        return $this->provider;
    }
    public function providerEventId(): string
    {
        return $this->providerEventId;
    }
    public function eventType(): string
    {
        return $this->eventType;
    }
    public function railReference(): ?string
    {
        return $this->railReference;
    }
    public function payloadHash(): string
    {
        return $this->payloadHash;
    }
    public function outcome(): string
    {
        return $this->outcome;
    }
    public function failureCode(): ?string
    {
        return $this->failureCode;
    }
    public function failureMessage(): ?string
    {
        return $this->failureMessage;
    }
    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
    public function processedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function markProcessed(?string $failureCode = null, ?string $failureMessage = null): void
    {
        $failureCode = null === $failureCode ? null : trim($failureCode);
        if (null !== $failureCode && '' !== $failureCode) {
            self::assertMaxLength($failureCode, 128, 'failure code');
        }
        $this->outcome = 'processed';
        $this->failureCode = $failureCode;
        $this->failureMessage = null === $failureMessage ? null : trim($failureMessage);
        $this->processedAt = new \DateTimeImmutable();
    }
    public function markIgnored(): void
    {
        $this->outcome = 'ignored';
        $this->processedAt = new \DateTimeImmutable();
    }
    public function markFailed(string $code, string $message): void
    {
        $code = trim($code);
        self::assertMaxLength($code, 128, 'failure code');
        $this->outcome = 'failed';
        $this->failureCode = $code;
        $this->failureMessage = trim($message);
        $this->processedAt = new \DateTimeImmutable();
    }

    private static function assertMaxLength(string $value, int $maxLength, string $field): void
    {
        if (strlen($value) > $maxLength) {
            throw new \InvalidArgumentException(sprintf('Withdrawal settlement event %s exceeds the persistence limit of %d characters.', $field, $maxLength));
        }
    }
}
