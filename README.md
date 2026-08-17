# Withdrawing

Symfony 8.1 / PHP 8.4 component that owns the reusable cash-out lifecycle across SmartResponsor products and brands.

## Responsibility

Withdrawing owns withdrawal intent, source identity, actor identity, amount/currency, destination reference, lifecycle state, idempotency, and the external rail execution reference.

It does not own wallet balances, payment methods, payment tokens, or provider implementations.

### Boundary

- Walleting owns wallet/account balance, ledger, reservation and funding.
- Withdrawing owns the source-agnostic withdrawal lifecycle.
- Paying owns payment method/token, gateway/provider routing and physical external payment rails.
- Walleting, Commissioning, Financing and branded components may act as withdrawal sources.

The expected orchestration is:

```text
source component -> Withdrawing -> Paying -> external rail
       ^                 |
       +---- reserve/finalize/release ----+
```

## Model

`Withdrawal` stores:

- `sourceType` and `sourceId` — component-owned source of withdrawable value;
- `actorType` and `actorId` — authenticated business actor requesting cash-out;
- `destinationReference` — opaque reference resolved by the payment boundary;
- amount in integer minor units and ISO-4217 currency;
- idempotency key;
- lifecycle state;
- optional external `railReference` after execution starts.

The lifecycle is:

```text
pending -> reserved -> processing -> succeeded -> reversed
   |          |            |
   +----------+------------+-> failed
   +-----------> cancelled
```

## Legacy Walleting withdrawal

Walleting currently contains a legacy `withdrawal` table and entity. Withdrawing intentionally uses the new durable table `withdrawal_request` during extraction so both components can coexist in one Host runtime without double Doctrine ownership.

Migration of legacy records and removal of Walleting withdrawal ownership must be additive and performed as a separate production-safe milestone.

## Platform dependencies

