# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Added dedicated WordPress page `/3d` with template **VP 3D Navigation** (`page-3d.php`) and local assets (`page-3d.css`, `page-3d.js`) for 3D/navigation scenarios.
- Added project documentation for QR routing flow and acceptance checklist in `docs/scan-routing.md`.

### Changed
- Updated `/scan` flow to route by QR type after lookup:
  - `instruction/product/service/manual` → `/instruction?code=...`
  - `3d/navigation/nav/location` → `/3d?code=...`
  - unknown type remains on `/scan` with a user-friendly message.
- Updated README with the new `/3d` route and routing summary.

### Fixed
- Frontend 3D/navigation handling now degrades gracefully when fields are missing in lookup payload (no client-side crash, fallback message shown).