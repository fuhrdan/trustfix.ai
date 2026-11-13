# Stripe staging payment policy

Approved by Dan for Stripe test-mode staging on 2026-11-08 UTC.
Policy version: `staging_2026_11_v1`.

These decisions authorize staging implementation and testing. Production
payment activation requires a separate reviewed change.

## Customer collection

- Collect the full customer-approved amount upfront.
- The initial collection schedule has one installment representing 100%.
- Deposits and additional collection installments are deferred.
- Record monetary amounts as integer cents in the existing USD flow.
- Snapshot the authorized scope, amount, fee, currency, and policy version.
- Contractor-entered final invoices do not authorize additional charges.

## Versioned schedules

Represent collection schedules as milestones belonging to a versioned
agreement. Each installment has its own payable obligation, payment record,
Stripe PaymentIntent, status, and audit history.

A future agreement could collect 10% at booking, 50% at an approved work
milestone, and 40% at completion. That example is not an enabled schedule.

Policy changes create new agreement versions. Changes to an existing
agreement require explicit consent and an audit record. Previously recorded
payments and financial facts are never rewritten.

Collection timing, contractor transfer timing, and the platform fee are
independent rules.

## Contractor release

1. The assigned contractor records completion of the agreed scope.
2. The customer explicitly approves that completed scope.
3. An administrator manually initiates the permitted contractor transfer.

There is no automatic release and no administrator bypass of customer
approval. Approvals must identify their actor, time, and approved scope.

Release also requires verified successful funding, sufficient unreleased
funds, contractor approval/readiness, and no unresolved dispute or conflicting
refund operation. Approval does not permit duplicate or excessive transfers.

A transfer moves money to the contractor's connected Stripe account.
Transfer confirmation does not establish that a bank payout succeeded;
bank payout status is separate.

## Platform fee and refunds

- Retain the existing 10% platform fee from contractor proceeds.
- TrustFix absorbs Stripe processing costs.
- Administrators authorize full or partial customer refunds with a reason.
- Return the TrustFix fee proportionally when refunding a payment.
- Use cumulative integer-cent allocation so partial refunds cannot return
  more than the original payment or its original platform fee.
- Full refund of a payment returns its full allocated TrustFix fee.
- Track refund requests, pending amounts, failures, and verified outcomes.
- Cancellation stops release eligibility and surfaces refund handling;
  cancellation alone does not establish that a refund succeeded.
- Post-transfer refunds require separate transfer-recovery tracking.
  A customer refund does not automatically reverse a contractor transfer.
- Recovery failures and funding shortfalls require administrator
  reconciliation and must remain visible in the financial records.

## Change orders

- Both parties must consent to the same scope and price change.
- The requester cannot supply the counterparty's approval.
- Customer financial authorization is required before charging.
- Record which approved changes are included in each authorized amount.
- Approved changes already included in `agreed_price` must not be charged
  a second time.
- Positive changes not already funded have separate payable/payment records.
- Negative changes use credit/refund handling rather than negative charges.
- Completed payments retain their original monetary snapshots.

## Financial and operational controls

Use separate platform charges and contractor transfers. Preserve immutable
financial facts, encrypted verified event receipts, durable processing state,
idempotent operations, and recoverable worker ownership.

Legacy destination-charge payments are not automatically eligible for new
contractor transfers.

All exercise of payment creation, transfers, and refunds requires verified
isolated staging resources and Stripe test credentials. Preserve the existing
API dependency and original checkout until their consumers are accounted for.
Production payment capability remains disabled.

Public staging isolation, MySQL concurrency checks, and Stripe test-mode
acceptance remain delivery requirements.

## Verified foundation checkpoint

Commit `ff93fb514cf8dcf4181fca23b1338fdd01dfd97d` passed 62 tests and
977 assertions on PHP 8.2 and PHP 8.3 using isolated in-memory SQLite.

That checkpoint verifies safety guards, journal constraints, event intake,
and generic event processing. Checkout integration and Stripe staging
acceptance require further implementation and evidence.

## Stripe references

- https://docs.stripe.com/connect/separate-charges-and-transfers
- https://docs.stripe.com/webhooks
