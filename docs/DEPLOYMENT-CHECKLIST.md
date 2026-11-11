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
