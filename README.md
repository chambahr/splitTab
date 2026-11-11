# SplitTrip API

SplitTrip is a mobile-first trip budgeting and expense-sharing product. This repository contains the Laravel 12 API, PostgreSQL schema, authentication, trip membership, invitation and private-access flows, budget and expense management, settlement lifecycle, financial calculation services, audit logging, and automated tests. The CloudCrane mobile interface is a separate client and must be connected to this API before the product supports real multi-user synchronization.

## Stack

- PHP 8.3+
- Laravel 12
- PostgreSQL 15+
- Laravel Sanctum bearer tokens
- SMTP for verification, invitations, and recovery mail
- PHPUnit 11
- CloudCrane mobile-first HTML/CSS/JavaScript PWA client

## Release and security boundary

The CloudCrane local-only prototype is not a multi-user production application. It stores local demo data and does not become a live synchronized app merely because this API exists. The API must be deployed behind HTTPS, configured with production secrets, migrated, tested against the dedicated test database, and connected to the client before public use.

This backend has been expanded to include the principal API workflows, but this source delivery is not a substitute for running the PostgreSQL integration suite, a deployment review, mail-delivery verification, and frontend end-to-end testing in the target environment.

## Database separation is mandatory

Create independent production and test databases and restricted roles. Never grant the test role access to production.

```sql
CREATE ROLE splittrip_app LOGIN PASSWORD 'REPLACE_WITH_A_LONG_PRODUCTION_SECRET';
CREATE DATABASE splittrip_prod OWNER splittrip_app;
CREATE ROLE splittrip_test LOGIN PASSWORD 'REPLACE_WITH_A_DIFFERENT_TEST_SECRET';
CREATE DATABASE splittrip_test OWNER splittrip_test;
```

Copy `.env.example` to `.env` and configure the intended local/development or production database. Copy `.env.testing.example` to `.env.testing`. `phpunit.xml` pins test execution to PostgreSQL and `splittrip_test`; `Tests\TestCase` refuses to run unless `APP_ENV=testing`, the default connection is `pgsql`, and the configured database name is exactly `splittrip_test`.

The test database must never share credentials or grants with production. Do not run `migrate:fresh`, tests, or destructive schema operations against `splittrip_prod`.

## Install and migrate

```bash
cp .env.example .env
cp .env.testing.example .env.testing
composer install
php artisan key:generate
php artisan migrate
```

Check the selected database name before running migrations. On a deployment host, take and verify a database backup before applying new migrations. The latest migrations add budget category seeds, idempotency fingerprints, payment references, audit-log immutability, and settlement expense snapshots.

## Required PHP extensions

Install the extensions required by Laravel and PHPUnit, including `pdo_pgsql`, `mbstring`, `dom`, `xml`, `xmlwriter`, `ctype`, `fileinfo`, `openssl`, and `tokenizer`. The test environment also needs a reachable PostgreSQL server and the dedicated `splittrip_test` database.

## Tests and quality checks

```bash
composer test
composer test:unit
composer test:feature
composer lint
php artisan route:list --path=api
```

Do not bypass the `Tests\TestCase` database safety guard. Do not run the test suite on the production VPS or against the production database. The two `tests/run-*-smoke.php` scripts are database-independent calculation smoke checks, not replacements for PHPUnit feature tests.

## Mail configuration

Configure SMTP credentials in the backend environment only. For Gmail, use a dedicated account and an App Password. Set `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, and `MAIL_FROM_NAME`. Never put SMTP credentials in CloudCrane HTML, JavaScript, a public repository, or client-side environment variables. Verify mail delivery and frontend deep links before onboarding users. Move to a transactional mail provider before scaling.

## Authentication API

All endpoints are under `/api/v1`.

| Method | Endpoint | Purpose |
|---|---|---|
| POST | `/register` | Register an account and issue a Sanctum token. Attempts to send email verification. |
| POST | `/login` | Authenticate and issue a token. |
| POST | `/logout` | Revoke the current token. |
| GET | `/me` | Return the authenticated account. |
| PATCH | `/me` | Update name and phone. |
| POST | `/change-password` | Change password and revoke existing tokens. |
| POST | `/email/verification-notification` | Send a signed verification link. |
| GET | `/email/verify/{id}/{hash}` | Verify email using a temporary signed URL. |
| POST | `/forgot-password` | Send a short-lived password reset token when the account exists. |
| POST | `/reset-password` | Reset password and revoke tokens. |

Passwords require at least 12 characters and confirmation. Password reset responses do not reveal whether an email address has an account. Configure rate limits and mail delivery before public use.

## Trips and membership API

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/trips` | List trips the authenticated user actively belongs to. |
| GET | `/trips/discover` | Discover public planning trips. |
| POST | `/trips` | Create a trip and owner membership. |
| GET | `/trips/{trip}` | Read public summary or member trip details, subject to private OTP. |
| PATCH | `/trips/{trip}` | Update trip as owner/admin. |
| DELETE | `/trips/{trip}` | Soft-archive a trip as owner. |
| POST | `/trips/{trip}/join` | Join a public trip. |
| DELETE | `/trips/{trip}/leave` | Leave a trip, after ownership transfer if owner. |
| GET | `/trips/{trip}/members` | List active members. |
| PATCH | `/trips/{trip}/members/{member}/role` | Owner changes member role. |
| DELETE | `/trips/{trip}/members/{member}` | Owner/admin removes a member within role restrictions. |
| POST | `/trips/{trip}/transfer-ownership` | Transfer ownership to an active member. |

Private trips require an invitation and recent OTP verification for private resources and management actions. OTPs are hashed, single-use, attempt-limited, and short-lived. Membership rows are retained when members leave or are removed.

## Invitations and private access

| Method | Endpoint | Purpose |
|---|---|---|
| POST | `/trips/{trip}/invitations` | Create or resend a private-trip invitation by email, invalidating older active invitations for that email. |
| DELETE | `/trips/{trip}/invitations/{invitation}` | Revoke an unaccepted invitation. |
| POST | `/invitations/accept` | Accept an invitation using its token, while signed in with the invited email. |
| POST | `/trips/{trip}/private-access/request` | Email a private-trip OTP. |
| POST | `/private-access/verify` | Verify a private-trip OTP. |

## Budgets

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/budget-categories` | List system budget categories. |
| GET | `/trips/{trip}/budgets` | List budgets with actual and remaining amounts. |
| POST | `/trips/{trip}/budgets` | Create a category budget. |
| PATCH | `/trips/{trip}/budgets/{budget}` | Update a budget. |
| DELETE | `/trips/{trip}/budgets/{budget}` | Delete a budget. |

Budget amounts are integer minor units and must use the trip's base currency. Actual category totals are derived from trip expenses.

## Expenses and comments

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/trips/{trip}/expenses` | List expenses. |
| POST | `/trips/{trip}/expenses` | Create an expense and allocation splits. Supports an idempotency key with payload fingerprint validation. |
| GET | `/trips/{trip}/expenses/{expense}` | Read an expense. |
| PUT/PATCH | `/trips/{trip}/expenses/{expense}` | Update an expense before it enters confirmed settlement history. |
| DELETE | `/trips/{trip}/expenses/{expense}` | Soft-delete an expense before it enters confirmed settlement history. |
| GET | `/trips/{trip}/expenses/{expense}/comments` | List comments. |
| POST | `/trips/{trip}/expenses/{expense}/comments` | Add a comment. Viewers are read-only. |

Allocation methods are `equal`, `manual`, `percentage` (basis points totaling 10,000), and `shares` (integer weights). All allocations use integer arithmetic and deterministic remainder handling. Payer and participants must be active trip members. Expense currency must match the trip currency.

## Settlements

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/trips/{trip}/settlements` | List settlement history and transfers. |
| POST | `/trips/{trip}/settlements/preview` | Calculate the current unsettled balance and proposed transfers. |
| POST | `/trips/{trip}/settlements` | Create a proposal and immutable expense/split snapshots. |
| POST | `/trips/{trip}/settlements/{settlement}/confirm` | Owner/admin confirms a proposal. |
| POST | `/trips/{trip}/settlements/{settlement}/reject` | Owner/admin rejects an unconfirmed proposal. |
| POST | `/trips/{trip}/settlements/{settlement}/transfers/{transfer}/paid` | Payer or administrator records a transfer as paid, with optional reference. |

A proposed transfer is not evidence of payment. Confirmed settlements lock their snapshotted expenses out of later settlement calculations. Once all transfers are marked paid, the settlement becomes completed. Subsequent expenses are settled in a new proposal. Confirmed/completed snapshot expenses cannot be edited or deleted. These endpoints record user-reported payments, they do not initiate bank, card, or mobile-money transfers.

## Financial and security invariants

- Store money in integer minor units, never floating-point values.
- Every expense's allocation shares must equal its total exactly.
- Net balance is total paid minus total allocated share.
- All member net balances must sum to zero before transfers are proposed.
- Idempotency keys cannot be reused with a different request payload.
- Expense writes and membership changes use database transactions and row locks where concurrency matters.
- Settlement proposals preserve the expense and split state used to calculate them.
- Confirmed settlement history must not be rewritten by later expense edits.
- Viewers are read-only across financial write endpoints.
- Private trip resources must enforce membership and recent OTP checks server-side.
- Audit records include financial, membership, authentication, and security events. PostgreSQL prevents direct update/delete of audit rows.
- Public trip summaries do not expose member email addresses or phone numbers.

## Production checklist

1. Use HTTPS and strict CORS origins for the actual frontend origin.
2. Set `APP_DEBUG=false`, unique `APP_KEY`, strong database credentials, and secure mail credentials.
3. Confirm that the test database role cannot access production.
4. Back up production before migrations and verify restore procedures.
5. Verify signed email links, invitation deep links, SMTP deliverability, and OTP expiry behavior.
6. Add monitoring, alerting, rate-limit review, log retention, privacy notice, data export, and account deletion procedures.
7. Run the full PHPUnit suite against `splittrip_test` on a host with the required PHP extensions and PostgreSQL driver.
8. Connect the CloudCrane client and run real end-to-end tests for registration, invitations, private access, budgets, expense allocation, and settlement reconciliation.

## Client integration boundary

The API is independent of the CloudCrane app. The client must use HTTPS and Sanctum bearer tokens, handle 401/403/409/422/423 responses, refresh trip/member/expense/budget/settlement state from the API, and never treat LocalStorage as the source of truth for shared data. No real payments are executed by the settlement API.
