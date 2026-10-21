# Backend modularization: Phase 8 identity and control plane

## Outcome

Phase 8 establishes application boundaries for administrative identity and the platform control plane. Registration, two-factor challenges, user presentation, administrator lifecycle, store directory and lifecycle operations, domain availability and verification, platform overview, and activity-log queries now live outside controllers.

Routes, middleware, session behavior, authorization responses, and public/admin response envelopes remain unchanged.

## Identity boundary

`App\Modules\Identity\Application` contains:

- `TwoFactorChallenge` for invalidating, creating, delivering, verifying, and consuming hashed one-time codes.
- `Registration` for atomic store provisioning, initial administrator creation, role assignment, and optional reCAPTCHA verification.
- `AdminUserManager` for administrator queries, store assignment/provisioning, status changes, last-super-admin protections, and deletion cleanup.
- `UserPresenter` for the distinct authentication, registration, administration, and impersonation projections.

Session establishment, invalidation, pending-2FA session keys, impersonation guard recovery, and HTTP validation remain in their controllers because they are request/session boundary concerns.

## Control-plane boundary

`App\Modules\ControlPlane\Application` contains:

- `StoreAdministration` for store directory filters, selector options, administrator membership, updates, activation/deactivation, and deletion.
- `StorePresenter` for the established store detail and subscription-summary projection.
- `StoreDomainManager` for canonicalization, availability, claimant detection, DNS reporting, and verification outcomes.
- `OverviewQuery` for aggregate store/user/admin counts and newest-store summaries.
- `ActivityQuery` for role-aware tenant filtering, activity filters, pagination, and safe response projection.

The existing `StoreProvisioner`, `DomainVerifier`, `StoreReadiness`, tenant middleware, and impersonation package remain infrastructure collaborators rather than being duplicated inside the modules.

## Security and containment preserved

- Password authentication still logs out before fallible 2FA delivery, so mail failure cannot leave an authenticated session.
- Two-factor codes remain hashed, single-use, expiring, and invalidated before resending.
- Disabled users, non-admin users, self-disable/delete attempts, and last-super-admin removal remain blocked with the same responses.
- Registration and administrator-created stores remain atomic and use the existing provisioner, including required tenant data.
- Store administrators remain bound to their assigned store; super administrators remain store-independent.
- Impersonation still verifies eligibility, restores or terminates failed sessions, restamps Laravel's password fingerprint, and records activity.
- Custom domains remain canonicalized, uniquely claimed, DNS-verified, and live only after verification.
- Store administrators remain restricted to their own activity log while super administrators retain explicit filtering.

## Deliberate deferrals

Subscription checkout, billing webhooks, subscription lifecycle, and the control-plane comp ledger remain owned by the existing subscription services and are deferred to Phase 9. Dashboard commerce analytics, Admin Assistant integration, SiteConfig/Website Editor internals, frontend code, and infrastructure are unchanged.

Store provisioning and readiness retain their existing direct SiteConfig dependency because creating and validating required tenant state is inseparable from those operations; no SiteConfig or Website Editor files were modified.

## Validation

Focused coverage includes login and 2FA failure safety, registration atomicity and throttling, administrator directory/lifecycle constraints, store assignment and deletion, control-plane directory filters and overview, domain verification, impersonation recovery, CSRF protection, activity isolation, tenant routing, canonical hosts, and TLS authorization.

```bash
php artisan test \
  tests/Feature/AuthApiTest.php \
  tests/Feature/RegistrationApiTest.php \
  tests/Feature/AdminUserManagementApiTest.php \
  tests/Feature/SuperAdmin \
  tests/Feature/Tenancy/CanonicalHostTest.php \
  tests/Feature/Tenancy/StoreScopingTest.php \
  tests/Feature/Tenancy/StorefrontRoutingTest.php \
  tests/Feature/Tenancy/TlsCheckTest.php
```
