# TrustFix Support & Escalation Procedure
# Don't Panic

Owner: TrustFix administrator on duty  
Review cadence: Quarterly and after every Level 3 incident

## Purpose

This procedure gives every TrustFix support request a visible owner, response target, and escalation path. It applies to account, technical, payment, job, contractor, safety, and security requests submitted through TrustFix.

TrustFix support is not an emergency service. If anyone is in immediate danger, direct them to call 911 or the appropriate local emergency service before continuing.

## Priority levels

| Level | Typical cases | First response | Resolution target |
| --- | --- | ---: | ---: |
| Level 1 — Standard | Account questions, minor technical issues, general help | 24 hours | 72 hours |
| Level 2 — High | Blocked jobs, payment/billing issues, major product failures | 4 hours | 24 hours |
| Level 3 — Urgent | Safety, security, privacy, active fraud, or immediate serious impact | 1 hour | 4 hours |

All targets are calendar hours. Safety and security categories start at Level 3. Payment cases start at Level 2. Administrators may raise a case's level but cannot lower it.

## Case flow

1. **Intake**
   - The customer submits a category, impact, subject, description, and optional TrustFix job.
   - TrustFix assigns a case number, priority, escalation level, first-response deadline, and resolution deadline.
   - The operations email receives a new-case alert.
2. **Triage**
   - An administrator confirms the category and checks for immediate safety, security, payment, or account-access risk.
   - The first administrator update assigns the case to that administrator and records the first response.
   - Record useful internal notes without copying passwords, card numbers, government IDs, or unnecessary personal information.
3. **Work**
   - Use **In progress** while TrustFix is actively investigating.
   - Use **Waiting for customer** only when a specific answer or document is required from the customer. State exactly what is needed in the customer communication.
   - Keep the customer informed whenever the status changes.
4. **Escalation**
   - An open or in-progress case that misses its first-response target moves to at least Level 2.
   - A case that misses its resolution target moves to Level 3.
   - TrustFix emails the operations address on escalation.
   - The administrator on duty acknowledges a Level 3 alert immediately, preserves relevant records, and tells the TrustFix owner who is handling it.
5. **Resolution**
   - Use **Resolved** after the underlying issue is fixed or a final answer has been provided.
   - Internal notes must state what changed, what was communicated, and whether follow-up is needed.
   - The customer receives an automatic status email and can see the updated state in TrustFix.
6. **Closure**
   - Use **Closed** after confirmation or after the documented follow-up period.
   - Do not delete the support or audit record to make metrics look cleaner.

## Level 3 safety or security checklist

1. Confirm whether there is immediate danger. If yes, direct the person to emergency services.
2. Do not promise confidentiality, fault, reimbursement, or a resolution time that has not been authorized.
3. Preserve the case number, related job ID, user IDs, timestamps, messages, and relevant audit events.
4. For suspected account compromise:
   - Suspend risky activity if supported by the existing account controls.
   - Require a password reset.
   - Do not ask the customer to send a password or authentication token.
5. For payment concerns:
   - Do not collect full card numbers in support notes.
   - Use the payment provider's official dashboard and dispute process.
6. Notify the TrustFix owner and document who owns the next action.
7. After containment, write a short incident review: impact, timeline, cause, resolution, and prevention.

## Administrator audit expectations

TrustFix automatically records state-changing administrator API actions. Review the audit log when:

- a user role or account status changes;
- a user, job, review, document, badge, material price, report, or dispute is changed;
- a support case is updated or escalated;
- an administrator reports an unexpected action.

Passwords, tokens, messages, descriptions, details, and administrator notes are redacted from audit metadata. Audit history is retained for 365 days by default.

## Daily administrator checklist

- Open **Operations & Support**.
- Confirm the latest database backup is less than 26 hours old.
- Confirm the uptime scheduler has checked in within 12 minutes.
- Review any down endpoint, consecutive failure, or recovery.
- Work urgent and overdue support cases first.
- Review unusual failed administrator actions in the audit log.

## Monthly and quarterly checks

Monthly:

- Confirm the cron entry still runs once per minute.
- Confirm operations alerts reach the correct mailbox.
- Review recurring support categories and reduce repeat causes.

Quarterly:

- Restore a recent backup into a disposable database and document the result.
- Review Level 3 cases and any missed targets.
- Review administrator access and remove accounts that no longer require administrator privileges.
- Update this procedure if ownership, hosting, email, payment, or security processes change.
