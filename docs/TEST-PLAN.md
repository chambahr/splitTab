# Test plan

## Database-independent unit coverage

- Equal split rounding and remainder distribution.
- Manual allocations sum exactly to expense total.
- Percentage allocation totals exactly 10,000 basis points.
- Weighted allocations and zero-weight handling.
- Invalid methods, participant IDs, weights, and amounts.
- Ledger balances are paid minus allocated shares.
- Transfers reconcile to zero and reject unbalanced ledgers.

Run calculation smoke checks with:

```bash
php tests/run-allocation-smoke.php
php tests/run-settlement-smoke.php
```

These are useful smoke checks, not a replacement for PHPUnit.

## PostgreSQL feature coverage

- Registration, login, logout, email verification, password reset, password change, token revocation.
- Public trip discovery, join, leave, archive, and participant-capacity enforcement.
- Private trip isolation and OTP expiry, wrong-code attempt cap, resend invalidation, replay prevention, and access-window expiry.
- Invitation identity matching, expiry, revocation, resend invalidation, capacity checks, duplicate acceptance, and rejoining.
- Budget category listing, CRUD, currency validation, and actual/remaining rollups.
- Equal, manual, percentage, and weighted allocation methods.
- Expense detail, create, update, soft-delete, audit history, and idempotency fingerprint behavior.
- Viewer read-only enforcement on comments, expenses, budgets, and settlements.
- Settlement preview, proposal snapshots, confirmation, rejection, payment references, idempotent payment recording, and subsequent settlement cycles.
- Currency mismatch and integer minor-unit boundaries.
- Owner/admin/member/viewer authorization matrix.
- Database rollback and concurrency behavior under PostgreSQL.
- Audit-log immutability trigger.

## Environment safety

Tests must run only on `splittrip_test`. Production DB credentials must not be available to the test process. The `Tests\TestCase` safety guard is intentional and must not be removed. Do not run `migrate:fresh` or the test suite against production.
