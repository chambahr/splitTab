# Deployment checklist

- [ ] Provision API host and PostgreSQL production database.
- [ ] Create restricted production DB role.
- [ ] Configure `.env` outside web root and never commit it.
- [ ] Set `APP_ENV=production`, `APP_DEBUG=false`, unique `APP_KEY`.
- [ ] Configure HTTPS and CORS to the exact CloudCrane origin.
- [ ] Configure Gmail App Password in secret environment.
- [ ] Configure production queue/cache/session stores.
- [ ] Run migrations after reviewing SQL and confirming target DB.
- [ ] Install PHP extensions including `pdo_pgsql`, `mbstring`, `dom`, `xml`, and `xmlwriter`.
- [ ] Run PHPUnit only against isolated `splittrip_test`, with production credentials unavailable to the test process.
- [ ] Configure backups and restore drill.
- [ ] Connect CloudCrane UI to API using a public API base URL and Sanctum bearer tokens.
- [ ] Remove local-only prototype mode only after API integration tests pass.
- [ ] Test public/private data isolation with two separate accounts.
- [ ] Verify registration email verification, password reset, invitation email/deep links, and OTP flows end to end.
- [ ] Verify budget rollups, expense idempotency, expense edit/delete restrictions, settlement snapshots, confirmation, and payment reconciliation.
- [ ] Verify audit-log immutability and restore from backup.
- [ ] Complete privacy, security and operational review before inviting real users.

## Final pre-staging verification

1. Confirm the test process uses `splittrip_test`, never `splittrip_prod`.
2. Run `composer test` and `composer lint` after every source change.
3. Connect explicitly to `splittrip_test` and run `psql -v ON_ERROR_STOP=1 -d splittrip_test -f docs/postgres-integrity-checks.sql`.
4. Confirm every required table exists, every listed constraint is validated, and the audit immutability trigger/function exist.
5. Exercise password reset end to end: request token, reset once, verify old Sanctum tokens are revoked, verify token replay and expired token are rejected.
6. Exercise private-trip OTP, invitation acceptance/revocation, role restrictions, expense allocation/idempotency, and settlement confirmation/payment recording with separate users.
7. Verify email delivery through the configured production mail transport in staging, not by sending test messages to real users.
8. Take a database backup and perform a restore drill before production migration.
9. Deploy migrations only after reviewing `php artisan migrate --pretend` output against the intended production database. Then take a fresh backup and apply normal forward migrations during a controlled deployment window.
10. Never run `migrate:fresh`, `db:wipe`, or PHPUnit against production.
