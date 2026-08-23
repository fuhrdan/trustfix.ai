# Backup and Recovery Standard

**Owner:** TrustFix technical / operations owner  
**Status:** Working standard; implementation details require verification  
**Last reviewed:** 2026-08-23

## Objective

Ensure TrustFix can recover important data and service capabilities after accidental deletion, corruption, failed deployment, provider outage, security incident, or other disruption.

## Scope

Backup/recovery planning should cover:

- MySQL production database
- uploaded property/job/contractor files
- production application configuration required to rebuild service
- source code (GitHub)
- deployment/recovery instructions
- critical encryption/key material where appropriate and securely stored
- compliance/audit evidence where applicable

Secrets must be backed up securely and must not be stored in public Git.

## Core requirements

- backups occur on a defined schedule
- backup success/failure is monitored
- copies are retained according to an approved schedule
- at least one recovery path is independent of the primary production failure mode where feasible
- backup access is restricted
- sensitive backups are encrypted where appropriate
- restoration is periodically tested
- failed restore tests produce corrective actions

## RPO / RTO

Before formal readiness, management should approve:

**RPO — Recovery Point Objective:** maximum acceptable data loss measured in time.  
**RTO — Recovery Time Objective:** maximum target time to restore a critical service.

Initial values: **TBD**

Do not publish arbitrary RPO/RTO promises to customers until the architecture and recovery testing support them.

## Backup inventory

| Asset | Backup mechanism | Frequency | Retention | Off-system? | Restore tested? | Status |
|---|---|---|---|---|---|---|
| MySQL database | Verify current production process | TBD | TBD | TBD | TBD | To verify |
| Uploaded files | Verify hosting/file backup coverage | TBD | TBD | TBD | TBD | To verify |
| Source code | GitHub + live checkout | Continuous through commits | Git history | Yes | N/A | Partial |
| Production config/secrets | Secure recovery method required | TBD | TBD | Must be secure | TBD | Planned/verify |
| Compliance evidence | Future restricted evidence store | TBD | TBD | TBD | TBD | Planned |

## Restore test

At least quarterly when operationally mature:

1. select representative backup
2. restore into a safe/non-production environment
3. verify database integrity
4. verify representative uploaded files
5. verify application can use restored data where practical
6. record actual restoration time
7. record data point recovered
8. document failures/findings
9. assign corrective actions

Never overwrite production merely to demonstrate a restore test.

## Deployment rollback

Each material release should have an understood rollback/recovery approach, considering:

- application code rollback
- migration compatibility
- database backup before high-risk migration
- configuration changes
- third-party integration changes

Database schema changes deserve special care because code rollback may not safely reverse destructive migrations.

## Evidence

Retain:

- automated backup success/failure reports
- backup configuration review
- restore-test date and result
- measured restore duration
- exceptions/failures
- corrective actions
- proof of off-system copy where applicable

Do not commit backup files or sensitive restore evidence to public Git.

## Future resilience roadmap

- documented RPO/RTO
- monitored automated backups
- off-system/off-provider copy where practical
- staging environment for restore validation
- disaster-recovery runbook
- annual larger-scale recovery exercise
- provider-outage contingency planning
