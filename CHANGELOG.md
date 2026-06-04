# Changelog

Production release tracked as **1.0.0** (private distribution, not wordpress.org).

## [1.0.1] - 2026-06-05

### Fixed
- Sideload images served from CDN/multisite hosts (any `/uploads/` path, e.g. `cdn.carrot.com/uploads/sites/<id>/...`), not only the source domain or `/wp-content/uploads/`
- Preserve each image's original year/month folder and attachment date when sideloading (parsed from the source URL)
- Slug imports now search both posts and pages (plus any CPT base) regardless of the "Remote content type" selector, so a page slug is no longer missed while that selector is on "Posts"

## [1.0.0] - 2026-06-04

### Added
- Import by slug(s), import modes, date/category/status filters
- Custom post type REST base support
- Background import (WP-Cron every 30s)
- Test connection, cancel import, clear session
- Custom tables for queue and log; pipelined import
- Settings tab, download log CSV, load more log entries
- Extended SEO meta, post format, sticky, menu order
- Developer hooks and `uninstall.php` cleanup
- Email notification on background completion (optional)

### Security
- SSRF checks on outbound URLs
- Per-user AJAX rate limiting
- Session credentials stored in short-lived transients only
