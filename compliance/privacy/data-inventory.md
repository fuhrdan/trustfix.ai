# TrustFix PII and Data Inventory

**Owner:** TrustFix privacy / security owner  
**Status:** Initial inventory; data-flow verification required  
**Last reviewed:** 2026-08-23

## Objective

Maintain an understandable inventory of information TrustFix collects, creates, receives, stores, shares, and deletes.

This supports privacy-by-design, retention decisions, access controls, incident response, vendor assessment, and future privacy-management certification.

## Classification model

| Classification | Description | Examples |
|---|---|---|
| Public | Intended for unrestricted disclosure | Public marketing information |
| Internal | Operational information not intended for public disclosure | Internal procedures |
| Confidential | PII/business information requiring controlled access | Contact data, property/job records |
| Restricted | Highest sensitivity; compromise could cause material harm | Authentication secrets, privileged credentials, sensitive payment/security tokens |

## Initial data inventory

| Data category | Examples | Purpose | Classification | Primary subjects | Access | Likely systems/vendors | Retention status |
|---|---|---|---|---|---|---|---|
| Account identity | Name, email, username, account role/status | Account/service delivery | Confidential | Customers, contractors, admins | User + authorized admins | TrustFix DB, email provider | To define |
| Authentication data | Password hash, verification/recovery artifacts, JWT/session data | Authentication | Restricted | Users/admins | System / limited admins | TrustFix backend | Security-defined |
| Login/security telemetry | IP, timestamp, attempted account/email, browser/user-agent, result/risk indicators | Security monitoring | Confidential | Users/attempting clients | Authorized admins/security | TrustFix DB/logs | To define |
| Property data | Address, property attributes, images | Service/job management | Confidential | Customers/property owners | Owner, authorized contractor/admin | TrustFix DB/files | To define |
| Job/service records | Descriptions, estimates, status, materials, hours, images | Fulfill/manage service | Confidential | Customers/contractors | Authorized participants/admin | TrustFix DB/files | To define |
| Messages/activity | Job messages, activity history | Collaboration/support/audit | Confidential | Customers/contractors/admin | Authorized participants/admin | TrustFix DB | To define |
| Contractor profile | Business/contact details, skills | Marketplace/service operations | Confidential/Public by field | Contractors | Contractor/admin; some customer visibility | TrustFix DB | To define |
| Contractor credentials | Licenses, insurance/COI, bonds, verification documents | Contractor verification | Confidential; possibly Restricted | Contractors | Contractor + authorized admin | TrustFix files/DB | To define |
| Payment metadata | Stripe identifiers, transaction/payment/payout metadata | Payments/payouts/accounting | Restricted/Confidential | Customers/contractors | Limited app/admin | TrustFix + Stripe | Legal/business review |
| Reviews | Rating/comment and linkage | Service quality/reputation | Public/Confidential by field | Customers/contractors | Users/admin | TrustFix DB | To define |
| Support/disputes | Support case content, dispute details | Support, resolution | Confidential | Users | Authorized support/admin | TrustFix DB/email | To define |
| Admin audit data | Administrator identity, action, target, timestamp/context | Accountability/security | Confidential | Admins/users affected | Authorized admins/security | TrustFix DB/logs | To define |
| Uploaded technical metadata | File names/types/sizes, timestamps | File workflow/security | Confidential | Users | System/authorized participants | TrustFix | To define |

## Data minimization

Before adding a field:

1. State the business purpose.
2. Confirm the data is necessary/proportionate.
3. Classify it.
4. Define who may access it.
5. Determine whether it reaches a vendor.
6. Define deletion/retention behavior.
7. Determine whether audit logging is needed.
8. Avoid collecting sensitive information "just in case."

## Sensitive data rules

- Never store plaintext passwords.
- Avoid storing raw payment-card data; use Stripe-hosted/tokenized mechanisms where applicable.
- Do not place secrets/tokens in logs.
- Do not expose contractor/customer documents outside authorized workflows.
- Avoid including unnecessary PII in audit/security logs.
- Restrict production database/file access.

## Privacy roles

Before formal ISO 27701 readiness, determine for each processing activity whether TrustFix acts as:

- PII controller
- PII processor
- joint/other applicable role

Legal terminology and obligations may vary by jurisdiction.

## Data-subject / privacy operations roadmap

Future capabilities/processes should address where legally/contractually applicable:

- access/export
- correction
- deletion/anonymization
- account closure
- identity verification before privacy action
- consent/preference management where needed
- objection/restriction where applicable
- request tracking and response evidence

## ROPA roadmap

A formal Record of Processing Activities should eventually add:

- processing activity
- purpose
- legal basis/authority where applicable
- categories of PII
- categories of people
- recipients/vendors
- international transfers
- retention
- technical/organizational safeguards
- owner
- review date

## Change trigger

Update this inventory when a feature:

- adds a database field containing person-related data
- adds a document/upload type
- changes visibility/permissions
- adds analytics/tracking
- integrates a new vendor
- exports/shares data
- changes payment/identity workflows
- changes retention/deletion
