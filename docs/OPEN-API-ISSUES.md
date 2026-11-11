# API implementation status and remaining work

This is an initial Laravel API codebase, not a production-certified fintech service. Before enabling real users, complete and test:

- Wire all models and migrations into a freshly installed Laravel 12 skeleton and run migrations against disposable PostgreSQL databases.
- Add API request/response resources and consistent error envelopes.
- Add verified-email registration, password reset, email change and account recovery.
- Complete role-based authorization policies for every route, including viewer read-only behavior.
- Add invitation deep-link handling in the CloudCrane client, revocation/resend, and invitation abuse controls.
- Add OTP tests for expiration, resend invalidation, five-attempt cap, replay, and private access timeout.
- Implement budget CRUD and category rollups. Budget tables/models exist, but no budget API controller is included yet.
- Implement percentage and weighted-share allocations. The domain enum reserves the methods, but the expense endpoint currently implements equal and manual only.
- Add expense update/delete with audit trail and settlement adjustment semantics.
- Add settlement confirmation and payment evidence lifecycle, ensuring confirmed history is immutable.
- Add concurrency/idempotency tests and PostgreSQL integration tests.
- Add API integration in the CloudCrane app, member email invitations, private OTP UX, and public trip membership UX.
- Add production monitoring, rate limiting, security review, privacy compliance and disaster recovery.

No production database was created or modified by this build. No SMTP password, API key or production credential was supplied or stored.
