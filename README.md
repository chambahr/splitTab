# SplitTrip API

SplitTrip is a mobile-first trip budgeting and expense-sharing product. This repository contains the Laravel API foundation, PostgreSQL schema, domain models, financial calculation service, OTP and invitation flows, audit logging, and automated tests. The public CloudCrane phone interface is published separately.

## Important release boundary

The currently published CloudCrane app is a **local-only prototype**. It stores demo trip data in the browser on one device. It does not yet call this API, and it does not provide real public trip discovery, cross-device sync, email invitations, private-trip OTP, or authenticated multi-user financial records. Do not use the prototype for real shared financial records or sensitive personal information. Those features become real only after this API is installed, configured, secured, tested, and connected to the interface.

## Stack

- PHP 8.3+
- Laravel 12
- PostgreSQL 15+
- Laravel Sanctum bearer tokens
- Gmail SMTP for initial transactional mail
- PHPUnit 11
- CloudCrane-hosted mobile-first HTML/CSS/JavaScript PWA

## Database separation is mandatory

Create two independent databases and two restricted database roles. Never point the test environment at production.

Example PostgreSQL administration commands:

```sql
CREATE ROLE splittrip_app LOGIN PASSWORD 'REPLACE_WITH_A_LONG_PRODUCTION_SECRET';
CREATE DATABASE splittrip_prod OWNER splittrip_app;
CREATE ROLE splittrip_test LOGIN PASSWORD 'REPLACE_WITH_A_DIFFERENT_TEST_SECRET';
CREATE DATABASE splittrip_test OWNER splittrip_test;
```

Use `.env.example` as the production/local API template. Copy it to `.env`, then set the production database credentials. Use `.env.testing.example` as the dedicated test configuration and copy it to `.env.testing`. The PHPUnit configuration also pins tests to `splittrip_test`, and `Tests\TestCase` has a hard safety check that refuses to run unless `APP_ENV=testing`, the connection is PostgreSQL, and the database name is exactly `splittrip_test`.

Do not grant the test role access to `splittrip_prod`. The safest arrangement is separate PostgreSQL credentials and, ideally, a separate test database server.

## Install

```bash
cp .env.example .env
cp .env.testing.example .env.testing
composer install
php artisan key:generate
php artisan migrate --env=local
```

Before running migrations, verify that `.env` points to `splittrip_prod` only on the intended deployment host. For local development, use a separate development database rather than production.

## Run tests

```bash
composer test
composer test:unit
composer test:feature
composer lint
```

The test command must use `.env.testing` and the `splittrip_test` database. Do not run the test suite against the production VPS or production database.

## Gmail SMTP

Use a dedicated Gmail account and a Google App Password. Configure `MAIL_HOST=smtp.gmail.com`, `MAIL_PORT=587`, `MAIL_ENCRYPTION=tls`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, and `MAIL_FROM_NAME` in the backend environment. Never put SMTP credentials in CloudCrane HTML, JavaScript, a public repository, or a client-side environment variable. Gmail sending limits and deliverability are not suitable for unrestricted high-volume production; move to a transactional mail provider before scaling.

## API routes

All endpoints are under `/api/v1`.

| Method | Path | Purpose |
|---|---|---|
| POST | `/register` | Create an account |
| POST | `/login` | Issue a Sanctum token |
| POST | `/logout` | Revoke current token |
| GET | `/me` | Current account |
| GET | `/trips` | Trips the signed-in member belongs to |
| GET | `/trips/discover` | Public trip discovery |
| POST | `/trips` | Create a trip |
| GET | `/trips/{trip}` | Read a trip, subject to visibility and private OTP |
| PATCH | `/trips/{trip}` | Update trip as owner/admin |
| POST | `/trips/{trip}/join` | Join a public trip |
| DELETE | `/trips/{trip}/leave` | Leave a trip |
| POST | `/trips/{trip}/invitations` | Invite an email to a private trip |
| POST | `/invitations/accept` | Accept an invitation using its token |
| POST | `/trips/{trip}/private-access/request` | Email a private-trip OTP |
| POST | `/private-access/verify` | Verify private-trip OTP |
| GET/POST | `/trips/{trip}/expenses` | List/create expenses |
| GET/POST | `/trips/{trip}/expenses/{expense}/comments` | Read/add expense comments |
| GET | `/trips/{trip}/settlements` | List settlement proposals |
| POST | `/trips/{trip}/settlements/preview` | Calculate proposed transfers |
| POST | `/trips/{trip}/settlements` | Save a settlement proposal |

Send the Sanctum token as `Authorization: Bearer <token>`.

## Financial invariants

- Store money in integer minor units, for example 1234 means 12.34 in a two-decimal currency.
- Never use floating-point arithmetic for settlement calculations.
- For each expense, the sum of allocation shares must equal the expense total exactly.
- Net balance is total paid minus total allocated share.
- The sum of all member net balances for a trip must be zero before transfers are proposed.
- A proposed transfer is not evidence of payment. Payment confirmation must be a separate, authenticated, auditable action.
- Completed settlement history must be immutable. Later expense changes should create a new adjustment/proposal, not rewrite paid transfers.

## Security requirements before production

1. Configure HTTPS and strict CORS origins for the actual CloudCrane app origin.
2. Keep `APP_DEBUG=false`; use a strong, unique `APP_KEY` and database credentials.
3. Configure mail secrets in server environment only.
4. Rate-limit registration, login, invitation and OTP endpoints at the reverse proxy and Laravel layers.
5. Add account email verification, password reset, account recovery, session/token expiry and abuse monitoring before public onboarding.
6. Keep private trip access checks server-side on every private resource endpoint.
7. Keep OTP codes hashed, short-lived, single-use and attempt-limited. Do not log codes or tokens.
8. Ensure invitation links are delivered through a verified frontend deep link and expire/revoke safely.
9. Add owner/admin/member/viewer policy tests for every write endpoint.
10. Add database transaction/concurrency tests for joins, idempotency and expense writes.
11. Add settlement confirmation, transfer reconciliation and adjustment history before treating settlements as final.
12. Add retention, export, account deletion, privacy notice and public trip disclosure rules.
13. Do not expose member email addresses or phone numbers in public trip payloads.
14. Configure backups, monitoring, alerting, log retention and a tested restore procedure.

## CloudCrane releases

- V1: trip creation, local budgets, local expenses and settle-up view.
- V2: expense categories and a backward-compatible local data migration.
- V3: local trip participants, equal/manual allocation and notes, with V2-to-V3 migration.

The CloudCrane version history preserves previous app versions. If a release regresses, use its rollback action. The CloudCrane screenshot and browser test services returned transient upstream errors during this build, so visual review and browser-run test results are still pending. The PHP source has been syntax-checked, but PHPUnit could not be run in this environment because Composer and the PDO PostgreSQL driver are not installed.
