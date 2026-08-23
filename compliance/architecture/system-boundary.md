# TrustFix System Boundary

**Owner:** TrustFix management / technical owner  
**Status:** Initial working scope  
**Last reviewed:** 2026-08-23  
**Review trigger:** Material architecture, hosting, vendor, authentication, payment, or data-flow change

## Purpose

Define the technical and operational boundary that may become the basis of a future TrustFix ISMS, PIMS, SOC 2 system description, and quality-management scope.

This is a working boundary, not a formal certification scope statement.

## Proposed product scope

TrustFix provides a service-management platform connecting customers/property owners, contractors, and administrators through home-service workflows.

The in-scope product functions currently include:

- user registration and account lifecycle
- authentication, email verification, and password recovery
- properties and property images
- jobs, estimates, job images, changes, and activity
- customer/contractor messaging
- contractor profiles and credential/document handling
- contractor approval/review workflows
- payments and payout workflows
- reviews, disputes, and support cases
- administrative operations and audit logging
- login-security monitoring and IP blocking
- material-price / estimating support functions

## Proposed technical boundary

```text
User Browser
    |
    | HTTPS
    v
PHP Frontend
    |
    | HTTPS / JSON
    v
Laravel API
    |
    +--> MySQL database
    +--> Uploaded/stored files
    +--> Email/SMTP
    +--> Stripe
    +--> Application/security/audit logs
```

## In-scope components

| Component | Function | Data sensitivity | Current owner | Status |
|---|---|---|---|---|
| PHP frontend | Browser-facing application | Confidential | TrustFix | In scope |
| Laravel API | Business logic/API/authentication | Restricted/Confidential | TrustFix | In scope |
| MySQL | Application data store | Restricted/Confidential | TrustFix | In scope |
| File storage | Property/job/contractor uploads | Confidential; potentially Restricted | TrustFix / hosting provider | In scope |
| Authentication/JWT | User authentication/session API access | Restricted | TrustFix | In scope |
| Admin/audit logging | Administrative accountability | Confidential | TrustFix | In scope |
| Login-security logging | Authentication/security monitoring | Confidential | TrustFix | In scope |
| Stripe integration | Payments/payout-related processing | Restricted | TrustFix + Stripe | In scope/interface |
| SMTP/email | Verification, recovery, notifications | Confidential | TrustFix + provider | In scope/interface |
| GitHub repository | Source/version history | Internal/Confidential | TrustFix + GitHub | In scope/supporting |
| Hosting environment | Runtime/infrastructure | Restricted | TrustFix + hosting provider | In scope/supporting |

## External dependencies / trust boundaries

The following are not fully controlled by TrustFix but materially affect the service and therefore require vendor/risk management:

- HawkHost or current production hosting provider
- GitHub
- Stripe
- SMTP/email provider
- domain registrar / DNS provider
- backup/monitoring services, if separate
- any future analytics, messaging, AI, identity, verification, or storage providers

See `../vendors/vendor-register.md`.

## Roles / identities

Current application roles include, at minimum:

- customer / property owner
- contractor / handyman
- company-related account roles if enabled
- administrator

Future governance roles should distinguish:

- system owner
- security owner
- privacy owner
- quality owner
- privileged administrator
- developer/deployer
- support operator
- vendor/subprocessor contact

In a small organization one person may hold multiple roles. Responsibilities should still be documented.

## Important data flows

### Authentication

```text
Browser -> PHP frontend -> Laravel API -> user/authentication store
                               |
                               +-> login-security/audit records
```

### Property/job workflow

```text
Customer -> frontend/API -> property/job records + images
Contractor -> frontend/API -> estimates/activity/messages/documents
Admin -> frontend/API -> review/approval/support/audit actions
```

### Payments

```text
TrustFix application <-> Stripe
```

TrustFix should minimize direct storage of payment-card data and rely on Stripe-hosted/tokenized mechanisms where applicable.

### Email

```text
TrustFix -> SMTP/email provider -> user
```

Messages may contain account and service information. Password-reset/verification secrets must be protected and short-lived.

## Environment model

Current production hosting is a live environment. Future target architecture should separate:

```text
Development -> Staging -> Production
```

with separate:

- credentials/secrets
- databases
- Stripe/test vs live configuration
- mail behavior
- logging/monitoring
- access controls

**Target status:** Planned

## Data locations to verify

Before formal privacy/security assessment, document:

- database physical/logical hosting location
- uploaded-file locations
- application log locations
- backup locations
- GitHub repository visibility and permissions
- email-provider processing locations
- Stripe processing relationships
- administrator endpoint/location practices
- disaster-recovery copy locations

## Explicit exclusions — not yet approved

No formal exclusions have been approved. Future certification scope should document exclusions and rationale rather than relying on assumptions.

## Boundary change process

Update this document before or alongside changes involving:

- new hosting/provider
- new database/storage technology
- new external API/vendor
- new category of sensitive information
- new privileged/admin capability
- new payment/data-transfer architecture
- separate staging/production environments
- major authentication/authorization redesign

## Readiness gaps to address later

- Formal legal/entity and organizational scope
- Named control/process owners
- Network/infrastructure diagram
- Complete data-flow diagram
- Development/staging/production separation
- Formal inventory of systems/assets
- Formal list of subservice organizations for SOC 2
- Defined business continuity and recovery boundary
