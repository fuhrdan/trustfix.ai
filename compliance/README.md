# TrustFix Compliance Foundation

**Document owner:** TrustFix management / security owner  
**Status:** Living compliance-readiness documentation  
**Last reviewed:** 2026-08-23  
**Review frequency:** At least quarterly and after material architectural, security, privacy, vendor, or operational changes

## Purpose

This directory is the starting point for TrustFix's long-term compliance and certification readiness program.

The goal is **not** to claim that TrustFix is currently certified or compliant with any particular standard. The goal is to make security, privacy, quality, risk management, evidence collection, and continual improvement part of normal product development so future formal certification and attestation require less rework.

TrustFix is being designed with future alignment to:

- ISO/IEC 27001:2022 — Information Security Management System (ISMS)
- ISO/IEC 27701:2025 — Privacy Information Management System (PIMS)
- SOC 2 — AICPA Trust Services Criteria
- ISO 9001 — Quality Management System (QMS); monitor the 2026 revision and transition guidance when published

This repository does **not** reproduce copyrighted standard text. Formal certification readiness should ultimately be checked against licensed/current copies of the standards and with qualified auditors or advisers.

## Guiding principle

For important TrustFix activities, design the process so that it is:

1. **Controlled** — authorization and expected behavior are defined.
2. **Attributable** — important actions can be associated with a person or system identity.
3. **Recoverable** — failures and mistakes have tested recovery paths.
4. **Measurable** — meaningful performance/security/privacy indicators can be tracked.
5. **Evidenced** — proof is retained that the control actually operated.
6. **Reviewed** — risks, controls, incidents, vendors, and objectives are revisited over time.
7. **Improved** — defects and findings result in corrective action rather than one-time fixes.

## Directory structure

```text
compliance/
├── README.md
├── architecture/
│   └── system-boundary.md
├── risk/
│   └── risk-register.md
├── security/
│   ├── access-control.md
│   ├── incident-response.md
│   ├── backup-recovery.md
│   └── secure-development.md
├── privacy/
│   ├── data-inventory.md
│   └── retention.md
├── vendors/
│   └── vendor-register.md
└── quality/
    └── quality-objectives.md
```

## Status vocabulary

Use these terms consistently:

| Status | Meaning |
|---|---|
| Implemented | Control/process exists and evidence can be produced |
| Partial | Some elements exist but the process is incomplete or inconsistently evidenced |
| Planned | Intentionally designed for future implementation |
| To verify | Likely exists, but evidence or exact configuration must be confirmed |
| Not applicable | Explicitly determined not to apply, with rationale recorded |

Do not mark a control **Implemented** because the application has a related feature. Implementation requires the operating process and evidence as well.

## Framework alignment

TrustFix should maintain **one control environment**, not four parallel compliance programs.

| Capability | ISO 27001 | ISO 27701 | SOC 2 | ISO 9001 |
|---|:---:|:---:|:---:|:---:|
| Risk management | ✓ | ✓ | ✓ | ✓ |
| Access control | ✓ | ✓ | ✓ |  |
| Security logging | ✓ | ✓ | ✓ |  |
| Incident management | ✓ | ✓ | ✓ | ✓ |
| Change management | ✓ |  | ✓ | ✓ |
| Supplier/vendor management | ✓ | ✓ | ✓ | ✓ |
| Data inventory / privacy lifecycle |  | ✓ | Privacy |  |
| Backups / resilience | ✓ |  | ✓ | ✓ |
| Internal review / audit | ✓ | ✓ | ✓ | ✓ |
| Corrective action | ✓ | ✓ | ✓ | ✓ |
| Quality objectives and customer outcomes |  |  |  | ✓ |

This is a planning matrix only. It is not a formal clause-by-clause mapping.

## Current TrustFix direction

Known product/security capabilities that support future readiness include:

- Authenticated API architecture with role-aware routes
- Account status and authorization controls
- Login throttling/rate limiting
- Email verification and password recovery
- Administrative audit logging
- Login-security logging, risk indicators, and IP blocking
- Secrets kept outside source control
- Git-based source history
- Laravel feature-test capability
- Stripe-based payment/payout integrations
- Production database migrations
- Support and dispute workflows

These are useful building blocks, but future certification will also require governance, evidence, periodic reviews, documented responsibilities, internal audits, management review, and demonstrated operating effectiveness.

## Minimum maintenance workflow

### For every material feature

Ask:

- Does it create or change access to sensitive data?
- Does it add a new type of PII?
- Does it add a vendor/subprocessor?
- Does it create a new security or availability risk?
- Does it change a production deployment/recovery procedure?
- Should an audit event be generated?
- Does the data need a retention/deletion rule?
- What test or evidence demonstrates the feature works as intended?
- Does a quality/security/privacy objective need to be updated?

Update the relevant files in this directory in the same pull/commit cycle when practical.

### Quarterly

Review at minimum:

- `risk/risk-register.md`
- `privacy/data-inventory.md`
- `vendors/vendor-register.md`
- `quality/quality-objectives.md`
- privileged/admin access
- unresolved incidents/findings
- backup/restore evidence
- security vulnerabilities and remediation status

### Annually or before formal certification readiness

Perform:

- formal scope review
- risk assessment review
- supplier review
- internal audit
- incident-response exercise
- backup/restore or disaster-recovery exercise
- privacy data-flow review
- management review
- corrective-action review

## Evidence philosophy

Do not store production secrets, customer PII, access tokens, raw sensitive logs, or confidential third-party reports in this public repository.

The repository may store:

- policies and procedures
- sanitized checklists
- blank evidence templates
- control descriptions
- test plans
- dates/results that contain no sensitive information

Sensitive evidence should eventually live in a restricted evidence repository or GRC/document-management system.

## Future additions

As TrustFix grows, add:

```text
compliance/
├── controls/
│   ├── master-control-matrix.*
│   └── statement-of-applicability.*
├── audits/
├── evidence-index/
├── training/
├── business-continuity/
├── policies/
├── procedures/
├── corrective-actions/
└── management-review/
```

A formal ISO 27001 Statement of Applicability should be created only after a defined ISMS scope, risk methodology, risk assessment, and control-selection process exist.

## Certification-readiness rule

**Do not claim ISO certification, SOC 2 compliance, or audit completion unless a qualified external body has actually issued the relevant certificate/report.**

Until then, use language such as:

> "Designed with future ISO 27001, ISO 27701, SOC 2, and ISO 9001 readiness in mind."
