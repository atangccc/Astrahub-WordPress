# Changelog

## 0.1.1 - 2026-06-24

- Fixed friend relation removal failing with `invalid request body` when the removal reason is empty.
- Fixed realtime token requests returning `401 Unauthorized` because the signed body did not match the Hub endpoint.
- Fixed the latest sync status panel not showing the server-side stored status.
- Updated latest sync time display to reflect the most recent sync attempt.
- Prepared the repository for public release by excluding local development notes and tooling from version control.

## 0.1.0

- Initial public release.
