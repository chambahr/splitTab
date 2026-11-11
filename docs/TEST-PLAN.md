# Test plan

## Unit tests

- Equal split rounding, including remainders.
- Manual allocations sum exactly to expense total.
- Ledger balances are paid minus shares.
- Transfers reconcile to zero and reject unbalanced ledgers.
- No negative allocation or transfer amounts.

## Feature tests to complete

- Registration, login, logout and token revocation.
- Public trip discovery, join, leave and capacity race.
- Private trip isolation for all API endpoints.
- Invitation email, expiry, revocation, duplicate acceptance and email identity match.
- OTP expiry, wrong-code attempt cap, resend invalidation, replay prevention and access-window expiry.
- Equal, manual, percentage and shares allocation methods.
- Idempotency under duplicate/concurrent expense requests.
- Expense comments and audit events.
- Settlement preview and immutable proposed/confirmed transfer lifecycle.
- Currency mismatch and integer minor-unit boundaries.
- Owner/admin/member/viewer authorization matrix.
- Migration from previous database schema with production-like anonymized data.

## Environment checks

Tests must run only on `splittrip_test`. Production DB credentials should not be available to the test process. The `Tests\TestCase` safety guard is intentional and should not be removed.
