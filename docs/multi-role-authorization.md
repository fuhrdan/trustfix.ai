# Multi-role authorization

## Purpose

TrustFix uses one account and identity for each person. A homeowner may also be
a contractor, company representative, or administrator without creating a
second login or duplicating personal data.

`users` is the canonical identity table. It owns credentials, verification,
account status, contact information, and relationships to application data.
Role-specific tables such as `contractor_profiles` extend that identity; they
do not create another user.

`user_roles` stores additive authorization capabilities. Its unique
`(user_id, role)` key prevents duplicate assignments, and its foreign key
deletes assignments when the canonical user is deleted.

## Why `users.role` still exists

`users.role` remains the **primary role** for compatibility and default user
experience. Older API consumers, sessions, and rollback releases may still
read it. It is not the complete authorization set.

The primary role must always be included in `user_roles`. Changing it selects
the legacy/default experience; it must not silently remove other capabilities.

Do not authorize against `users.role` directly. Backend code must use:

- `User::hasRole()` for one capability;
- `User::hasAnyRole()` for any accepted capability;
- `User::withRole()` for role-aware queries;
- `User::roleNames()` when serializing or displaying the complete role set.

Frontend code must use `userRoles()`, `userHasRole()`, `userHasAnyRole()`, and
`requireRole()` from `frontend/config.php`.

## Invariants

1. Every account lives in `users`; there is no contractor or administrator
   identity table.
2. Roles are limited to `customer`, `handyman`, `company`, and `admin`.
3. Every primary role is also an additive assignment.
4. A secondary role never replaces another role unless an administrator
   explicitly synchronizes the complete assignment set.
5. Role-set and primary-role updates occur in one database transaction.
6. TrustFix must retain at least one administrator and one verified
   administrator.
7. Contractor approval adds `handyman`; it does not remove `customer` or
   `admin`.
8. Authorization checks use additive helpers rather than direct comparisons
   with `users.role`.

## Model operations

### Reading roles

`User::roleNames()` returns the normalized union of `user_roles` and the
primary role. Including the primary role makes rolling deployment and
application rollback tolerant of partially migrated data.

### Granting a role

`User::grantRole($role, $makePrimary = false)` is additive and idempotent. It
creates the assignment if needed. When `$makePrimary` is true, it also changes
`users.role` in the same transaction.

### Replacing a role set

`User::syncRoles($roles, $primaryRole)` is the administrative write path. It
rejects an empty or unknown role set and requires the primary role to be one
of the assignments. The assignment set and primary role are synchronized in
one transaction.

### Querying by role

`User::withRole($role)` matches either the primary role or an additive
assignment. Administrative counts and authorization-related queries should
use this scope.

## Account lifecycle behavior

### Public registration

Public registration creates a `customer` account. Contractor self-registration
also creates a pending, non-public contractor profile, but the person keeps
customer capability while approval is pending.

### Contractor approval

Approval grants `handyman`. For an ordinary customer, contractor becomes the
primary/default experience while `customer` remains assigned. For an
administrator, `admin` remains primary and `handyman` is added.

### Contractor invitations

A new invitee may start with `handyman` as primary. An existing customer gains
`handyman` without losing customer access. Invitation acceptance cannot be
used to convert an administrator account.

### Administrator editing

The admin API accepts both `roles` and `role`. `roles` is the complete
assignment set and `role` is the primary role. Legacy clients that omit
`roles` preserve existing assignments. The API guards the last administrator,
the last verified administrator, and the current administrator's verification
state.

## Migration and deployment

The migration creates `user_roles`, then backfills one assignment from every
existing `users.role` in bounded chunks. `insertOrIgnore` and the unique key
make the backfill safe against duplicates.

Recommended release order:

1. Verify a restorable database backup.
2. Run the `user_roles` migration while the previous application release is
   still active; the previous release safely ignores the new table.
3. Confirm every user has an assignment matching `users.role`.
4. Activate the new application release.
5. Warm PHP-FPM/OPcache workers before accepting the release as healthy.
6. Validate customer, contractor, administrator, and combined-role access.

Application rollback does not require dropping `user_roles`. The prior release
continues to use `users.role`, and the additive data remains available for a
retry. Avoid running `migrate:rollback` after secondary assignments have been
used unless losing those assignments is explicitly accepted.

## Read-only verification examples

```sql
SELECT COUNT(*) AS users_count FROM users;
SELECT COUNT(*) AS role_assignment_count FROM user_roles;

SELECT u.id, u.role
FROM users AS u
LEFT JOIN user_roles AS ur
  ON ur.user_id = u.id AND ur.role = u.role
WHERE ur.id IS NULL;

SELECT user_id, role, COUNT(*) AS duplicate_count
FROM user_roles
GROUP BY user_id, role
HAVING COUNT(*) > 1;
```

The first reconciliation query must return no rows, and the duplicate query
must return no rows.

## Human review checklist

- Confirm authorization code uses additive helpers.
- Confirm the primary role is included in every submitted role set.
- Confirm administrative changes remain transactional.
- Confirm last-administrator and verification guards remain in place.
- Confirm contractor approval preserves existing capabilities.
- Confirm API serialization includes both `role` and `roles`.
- Confirm the migration is backed up and reconciled before cutover.
- Confirm rollback leaves the additive table intact.
- Run `MultiRoleAuthorizationTest` and `LegacyRoleCompatibilityTest` under all
  supported PHP runtimes.
