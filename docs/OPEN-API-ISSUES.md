# Remaining API work and release gates

This API now includes authentication and password recovery, trip management, public/private membership, invitation acceptance/revocation, private-trip OTP, budget CRUD, four expense allocation methods, expense update/delete, comments, settlement snapshots, settlement confirmation/payment recording, and audit logging.

The following work remains before production launch:

- Run the full PHPUnit unit and PostgreSQL feature suite on a host with `pdo_pgsql`, `dom`, `mbstring`, `xmlwriter`, and a reachable dedicated `splittrip_test` database.
- Add explicit PostgreSQL concurrency tests for trip capacity, OTP attempts/resend, invitation acceptance, expense idempotency, and settlement confirmation/payment.
- Add API Resources and standardize response/error envelopes and pagination contracts.
- Add full account lifecycle features such as email change, account deletion/anonymization, data export, and recovery support.
- Add stronger abuse monitoring and configurable rate limits for invitations, email verification, password reset, and OTP requests.
- Connect the CloudCrane client to the API and test registration, invitation deep links, private OTP, multi-user sync, budgets, expense allocation, and settlement reconciliation end to end.
- Verify SMTP deliverability and the configured frontend deep-link destination in the deployment environment.
- Complete CORS, HTTPS, backups/restore, monitoring, retention, privacy, and operational security reviews.

The settlement API records payments reported by users. It does not initiate bank, card, mobile-money, or cryptocurrency transfers. Confirmed settlement snapshots are preserved, and confirmed/completed expense items cannot be edited or deleted.

No production database was modified as part of this source update. Do not treat syntax checks or calculation smoke scripts as a substitute for PostgreSQL integration testing.
