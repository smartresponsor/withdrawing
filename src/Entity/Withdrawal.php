<?php

declare(strict_types=1);

namespace App\Withdrawing\Entity;

use App\Objecting\EntityInterface\ObjectEntityInterface;
use App\Objecting\EntityInterface\ObjectVersionedInterface;
use App\Objecting\EntityTrait\Embeddable\ObjectAuditEmbeddableTrait;
use App\Objecting\EntityTrait\Embeddable\ObjectIdentityEmbeddableTrait;
use App\Objecting\EntityTrait\Embeddable\ObjectStateEmbeddableTrait;
use App\Objecting\EntityTrait\Embeddable\ObjectTitleEmbeddableTrait;
use App\Objecting\EntityTrait\Embeddable\ObjectVersionEmbeddableTrait;
use App\Withdrawing\Enum\WithdrawalStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'withdrawal_request')]
#[ORM\Index(name: 'idx_withdrawing_request_source_status', columns: ['source_type', 'source_id', 'withdrawal_status'])]
#[ORM\Index(name: 'idx_withdrawing_request_status_created', columns: ['withdrawal_status', 'created_at'])]
#[ORM\Index(name: 'idx_withdrawing_request_actor_status', columns: ['actor_type', 'actor_id', 'withdrawal_status'])]
#[ORM\UniqueConstraint(name: 'uniq_withdrawal_request_idempotency_key', columns: ['idempotency_key'])]
#[ORM\UniqueConstraint(name: 'uniq_withdrawal_request_rail_reference', columns: ['rail_reference'])]
/**
 * Persists one source-agnostic withdrawal request and enforces its lifecycle invariants.
 */
class Withdrawal implements ObjectEntityInterface, ObjectVersionedInterface
{
    use ObjectIdentityEmbeddableTrait;
    use ObjectTitleEmbeddableTrait;
    use ObjectAuditEmbeddableTrait;
    use ObjectStateEmbeddableTrait;
    use ObjectVersionEmbeddableTrait;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(name: 'source_type', length: 64)]
    private string $sourceType;

    #[ORM\Column(name: 'source_id', length: 128)]
    private string $sourceId;

    #[ORM\Column(name: 'actor_type', length: 64)]
    private string $actorType;

    #[ORM\Column(name: 'actor_id', length: 128)]
    private string $actorId;

    #[ORM\Column(name: 'destination_reference', length: 191)]
    private string $destinationReference;

    #[ORM\Column(name: 'amount_minor', type: 'bigint')]
    private int $amountMinor;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(name: 'idempotency_key', length: 128)]
    private string $idempotencyKey;

    #[ORM\Column(name: 'source_reference', length: 191, nullable: true)]
    private ?string $sourceReference = null;

    #[ORM\Column(name: 'withdrawal_status', enumType: WithdrawalStatus::class)]
    private WithdrawalStatus $status;

    #[ORM\Column(name: 'rail_reference', length: 191, nullable: true)]
    private ?string $railReference = null;

    public function __construct(
        string $sourceType,
        string $sourceId,
        string $actorType,
        string $actorId,
        string $destinationReference,
        int $amountMinor,
        string $currency,
        string $idempotencyKey,
        ?Uuid $id = null,
    ) {
        $sourceType = trim($sourceType);
        $sourceId = trim($sourceId);
        $actorType = trim($actorType);
        $actorId = trim($actorId);
        $destinationReference = trim($destinationReference);
        $currency = strtoupper(trim($currency));
        $idempotencyKey = trim($idempotencyKey);

        if ('' === $sourceType || '' === $sourceId || '' === $actorType || '' === $actorId || '' === $destinationReference || '' === $idempotencyKey) {
            throw new \InvalidArgumentException('Withdrawal source, actor, destination, and idempotency key are required.');
        }
        self::assertMaxLength($sourceType, 64, 'source type');
        self::assertMaxLength($sourceId, 128, 'source id');
        self::assertMaxLength($actorType, 64, 'actor type');
        self::assertMaxLength($actorId, 128, 'actor id');
        self::assertMaxLength($destinationReference, 191, 'destination reference');
        self::assertMaxLength($idempotencyKey, 128, 'idempotency key');
        if ($amountMinor <= 0 || 1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('Withdrawal amount and ISO 4217 currency are invalid.');
        }

        $this->id = $id ?? Uuid::v7();
        $this->sourceType = $sourceType;
        $this->sourceId = $sourceId;
        $this->actorType = $actorType;
        $this->actorId = $actorId;
        $this->destinationReference = $destinationReference;
        $this->amountMinor = $amountMinor;
        $this->currency = $currency;
        $this->idempotencyKey = $idempotencyKey;
        $this->status = WithdrawalStatus::Pending;
        $now = new \DateTimeImmutable();
        $this->initializeObjectIdentity($this->id->toRfc4122(), 'withdrawal:'.$this->id->toRfc4122());
        $this->initializeObjectTitle('Withdrawal '.$this->id->toRfc4122());
        $this->initializeObjectAudit($now);
        $this->initializeObjectState(objectStatus: $this->status->value);
        $this->initializeObjectVersion();
    }

    /**
     * Attach the source reservation and advance a pending withdrawal to reserved.
     */
    public function reserve(string $sourceReference): void
    {
        $sourceReference = trim($sourceReference);
        if ('' === $sourceReference) {
            throw new \InvalidArgumentException('Withdrawal source reference is required.');
        }
        self::assertMaxLength($sourceReference, 191, 'source reference');

        $this->transition(WithdrawalStatus::Pending, WithdrawalStatus::Reserved);
        $this->sourceReference = $sourceReference;
    }

    /**
     * Attach the external rail correlation and advance a reserved withdrawal to processing.
     */
    public function start(string $railReference): void
    {
        $railReference = trim($railReference);
        if ('' === $railReference) {
            throw new \InvalidArgumentException('Withdrawal rail reference is required.');
        }
        self::assertMaxLength($railReference, 191, 'rail reference');
        $this->transition(WithdrawalStatus::Reserved, WithdrawalStatus::Processing);
        $this->railReference = $railReference;
    }

    /**
     * Restore the reserved state after a submitted rail operation was compensated before local persistence succeeded.
     */
    public function restoreReservedAfterStartFailure(): void
    {
        if (WithdrawalStatus::Processing !== $this->status) {
            throw new \LogicException('Only a processing withdrawal can restore its reserved state after a failed start.');
        }

        $this->railReference = null;
        $this->setStatus(WithdrawalStatus::Reserved);
    }

    /**
     * Mark a processing withdrawal as successfully settled.
     */
    public function succeed(): void
    {
        $this->transition(WithdrawalStatus::Processing, WithdrawalStatus::Succeeded);
    }

    /**
     * Mark a non-terminal withdrawal as failed while rejecting terminal transitions.
     */
    public function fail(): void
    {
        if (!in_array($this->status, [WithdrawalStatus::Pending, WithdrawalStatus::Reserved, WithdrawalStatus::Processing], true)) {
            throw new \LogicException('Withdrawal cannot fail from its current status.');
        }
        $this->setStatus(WithdrawalStatus::Failed);
    }

    /**
     * Cancel a withdrawal before external rail processing begins.
     */
    public function cancel(): void
    {
        if (!in_array($this->status, [WithdrawalStatus::Pending, WithdrawalStatus::Reserved], true)) {
            throw new \LogicException('Withdrawal cannot be cancelled from its current status.');
        }
        $this->setStatus(WithdrawalStatus::Cancelled);
    }

    /**
     * Reverse a previously succeeded withdrawal after external compensation is coordinated.
     */
    public function reverse(): void
    {
        $this->transition(WithdrawalStatus::Succeeded, WithdrawalStatus::Reversed);
    }

    private function transition(WithdrawalStatus $from, WithdrawalStatus $to): void
    {
        if ($this->status !== $from) {
            throw new \LogicException(sprintf('Withdrawal must be %s before becoming %s.', $from->value, $to->value));
        }
        $this->setStatus($to);
    }

    private function setStatus(WithdrawalStatus $status): void
    {
        $this->status = $status;
        $this->setObjectStatus($status->value);
        $this->touchModified();
    }

    private static function assertMaxLength(string $value, int $maxLength, string $field): void
    {
        if (strlen($value) > $maxLength) {
            throw new \InvalidArgumentException(sprintf('Withdrawal %s exceeds the persistence limit of %d characters.', $field, $maxLength));
        }
    }

    /** Return the durable withdrawal identifier used for persistence and correlation. */
    public function id(): Uuid
    {
        return $this->id;
    }

    /** Return the source component discriminator that owns the withdrawable value. */
    public function sourceType(): string
    {
        return $this->sourceType;
    }

    /** Return the source-owned identifier of the withdrawable value. */
    public function sourceId(): string
    {
        return $this->sourceId;
    }

    /** Return the business actor discriminator that requested this withdrawal. */
    public function actorType(): string
    {
        return $this->actorType;
    }

    /** Return the business actor identifier that requested this withdrawal. */
    public function actorId(): string
    {
        return $this->actorId;
    }

    /** Return the opaque destination reference resolved by the payment boundary. */
    public function destinationReference(): string
    {
        return $this->destinationReference;
    }

    /** Return the withdrawal amount expressed in integer minor currency units. */
    public function amountMinor(): int
    {
        return $this->amountMinor;
    }

    /** Return the normalized ISO 4217 currency code for this withdrawal. */
    public function currency(): string
    {
        return $this->currency;
    }

    /** Return the stable caller key used to reject mismatched request replays. */
    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    /** Return the source reservation reference once reservation has succeeded. */
    public function sourceReference(): ?string
    {
        return $this->sourceReference;
    }

    /**
     * Return the current business lifecycle state of the withdrawal.
     */
    public function status(): WithdrawalStatus
    {
        return $this->status;
    }

    /** Return the external rail correlation reference after processing starts. */
    public function railReference(): ?string
    {
        return $this->railReference;
    }
}
