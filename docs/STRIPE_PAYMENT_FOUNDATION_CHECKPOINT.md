# Stripe payment foundation checkpoint

Recorded November 8, 2026. This is an incomplete payment implementation checkpoint for draft review. The last verified production checkpoint is `4eb9bc2f27138f9de6cc5ae6f500d29466d75082`, tagged `production-2026-11-07-4eb9bc2`. This workstream has made no production deployment changes.

## Scope

The branch adds isolated payment tests, default-disabled payment controls, a guard that restricts Stripe operations to non-production test mode, immutable event receipts and payment journal storage, atomic event intake, and recoverable event-processing leases. The approved business policy is recorded in [STRIPE_STAGING_PAYMENT_POLICY.md](STRIPE_STAGING_PAYMENT_POLICY.md).

The event inbox and processor are not yet wired into the webhook endpoint. The existing PaymentIntent construction still uses destination-charge parameters. Converting it to separate platform charges and explicit contractor transfers remains pending. Keep the PR in draft and all payment operation controls disabled.

## Verified evidence

Application/test source was last verified at `ff93fb514cf8dcf4181fca23b1338fdd01dfd97d`. The unpublished staging candidate uses `76d68b999db0af288a1e6a2a0838cf0f6f4bc198`, which adds policy documentation without changing that application/test source.

| Check | Verified result |
| --- | --- |
| Full application suite, PHP 8.2.35 | 62 tests, 977 assertions; no failures, errors, or skips |
| Full application suite, PHP 8.3.36 | 62 tests, 977 assertions; no failures, errors, or skips |
| Migration syntax | All 51 migration files passed PHP 8.2 and 8.3 syntax checks |
| Staging dependency build | Locked production dependencies installed using PHP 8.2.35, with Composer scripts and plugins disabled |
| Composer validation | `composer validate --strict` passed |
| Installed platform requirements | Passed on staging CLI PHP 8.2 |
| Dependency audit | `composer audit --no-dev` reported no security advisories |
| Staging database | Dedicated MariaDB 10.11.19 schema and credentials; authenticated connection identity verified |
| Migration history | 51 migrations recorded: users prerequisite in batch 1, remaining 50 in batch 2 |
| Physical schema | 49 InnoDB tables and 72 foreign keys |
| Immutable storage protections | Four BEFORE UPDATE/DELETE triggers on event receipts and payment journal entries, with append-only rejection definitions verified |
| Data checks | Users, payments, event receipts, event processing, and payment journal tables were empty after migration |
| Resource configuration | Separate writable storage, file caches/sessions, dedicated database queue configuration, and array mail transport verified through Laravel CLI |
| Financial controls | Collection, contractor transfer, refund, and automatic release controls disabled; test-only restriction enabled; Stripe credentials empty |

Detailed operational logs remain private on the source host. This summary records configuration checks and validation totals without credential values.

## Fresh staging initialization

Several historical child-table migrations precede the users migration. The new empty staging database was initialized by running the existing `2026_01_21_203747_create_users_table` migration first, retaining its original name and normal migration history. Both users self-referencing foreign keys were verified before running the remaining migrations.

The remaining migrations ran with foreign-key enforcement enabled and a SQL guard blocking destructive statements. The legacy cache/session replacement branches were not needed for the correctly shaped fresh tables. This initialization procedure applies to a new empty schema; existing production migration history is retained during promotion.

## Hosting status

The staging candidate remains unpublished. The intended staging frontend and protected legacy API still map to the original checkout. cPanel metadata reports PHP 8.1 and PHP-FPM disabled for those protected hosts; their actual web runtime has not been established by this workstream.

The proposed separate staging API hostname remains unregistered in the hosting account. Web handler selection, DNS/TLS, frontend API/session isolation, webhook routing, and queue-worker execution remain pending.

Source CLI PHP 8.3 lacks Sodium. Its attempted dependency install was stopped without changing the lock file; staging dependencies and database verification used PHP 8.2 with Sodium. The successful PHP 8.3 test suite is separate evidence from a complete PHP 8.3 platform check.

## Work remaining before payment acceptance

- Implement separate charges and transfers, contractor onboarding/readiness, durable financial operation records, and reconciliation.
- Implement contractor completion, explicit homeowner approval, and manual administrator transfer permissions bound to the authorized scope and amount.
- Integrate approved change orders, cancellations, refunds before/after transfer, disputes, receipts, and administrator reconciliation.
- Wire durable event intake/processing, retry recovery, ordering protections, and operational visibility into the application.
- Publish only to verified isolated staging with Stripe test credentials and a correctly signed test webhook destination.
- Verify successful payment, decline, 3DS, duplicate/out-of-order events, retry recovery, refunds, disputes, concurrent/duplicate release prevention, and reconciliation against Stripe test records.
- Repeat the full required validation for the completed implementation and attach acceptance evidence to the PR.

No Stripe test payment or contractor transfer has been exercised by this foundation checkpoint.

## Production promotion plan

1. Complete the payment implementation and staging acceptance evidence, obtain PR review, and select an immutable reviewed merge revision. Keep live payment activation outside this workstream.
2. On production, perform a read-only preflight of the current release, shared environment/storage, worker, database, and trigger-creation prerequisites. Verify a fresh backup before applying the new release. Preserve the existing backup artifacts and the initial rollback release.
3. Build a new release from the reviewed revision using production PHP 8.3. Run strict Composer validation, dependency audit, platform checks, syntax checks, and applicable tests. Retain the authorized homepage and password-reset redirect overlays.
4. Attach the existing production shared environment/storage and verify effective resource bindings. Keep every payment operation control disabled and retain the test-only guard. Apply only reviewed pending migrations using existing migration history; preserve financial audit tables and records.
5. Promote the new release through the existing release links, restart the application worker for the new code, and verify health, login, images/property operations, queue health, and disabled payment configuration. Record the revision and acceptance results.
6. If smoke checks fail, restore the prior verified application release links and worker code while retaining shared storage and additive financial audit history. Use forward corrections for schema issues; retain the initial rollback artifact.

This checkpoint is not a production promotion or live payment activation authorization.
