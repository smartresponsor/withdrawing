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

Withdrawing declares Objecting, Cruding, Viewing, Interfacing, and EasyAdmin directly. Local development resolves those sibling packages through Composer path repositories with explicit `dev-master` identity pins. Because Composer does not inherit repositories from dependencies, the development manifest also exposes Cruding's reachable Collectioning and Tabling path repositories without adding them as direct Withdrawing requirements. `composer.prod.json` intentionally contains no local path repositories and resolves packaged dependencies instead.

Objecting supplies the reusable identity, title, audit, and generic state mappings used by `Withdrawal`. The withdrawal lifecycle itself remains a domain-specific enum stored separately as `withdrawal_status`, while Objecting's generic state projection uses its canonical `status` column.

## Quality gates

```text
composer validate --strict --check-lock
composer cs:check
composer phpstan
composer test
composer test:coverage
composer schema:parity
```

`test:coverage` writes the branch-aware text report to `var/coverage/summary.txt`. The component is a reusable bundle rather than a standalone Symfony application, so `doctrine:schema:validate` and `schema:parity` run the repository-owned `tool/schema-parity.php` check. It loads the Host's installed runtime libraries and PostgreSQL connection environment without booting the Host kernel, builds Doctrine metadata only for Withdrawing entities, compares the owned `withdrawal_request` and `withdrawal_settlement_event` tables with that metadata, and verifies that all six local Withdrawing migrations are recorded as executed. Unrelated Host DI, workflow, table, or custom-type drift therefore cannot create a false-negative Withdrawing RC gate.
