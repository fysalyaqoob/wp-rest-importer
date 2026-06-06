# Changelog

Production release tracked as **1.0.0** (private distribution, not wordpress.org).

## [1.1.0] - 2026-06-06

### Added
- Gutenberg block processor using `parse_blocks()` / `serialize_blocks()` for faithful block import
- Full media migration: srcset, CSS backgrounds, Gutenberg JSON URLs, PDF/video/audio sideloading
- Featured image alt text, caption, and title preservation
- SEO social preview (OG) image import
- Metadata allowlist for private meta keys, serialized/array meta, ACF REST fields
- Page template and modified date import
- Term hierarchy and descriptions with source term ID mapping
- Internal link mapping via `_wpresti_source_url` lookup
- Dry-run preview mode, incremental sync (`modified_after` filter)
- Queue claim-after-success with retry and stale-item recovery
- HTTP retry with backoff for transient remote errors
- Configurable REST page cap (0 = unlimited)
- Action Scheduler support when available
- Author reassignment for all public post types (not just posts/pages)
- Media sideload failures logged in the import log

### Fixed
- Gutenberg posts/pages: block JSON attributes (`id`, `url`, `ids`, `images`, `mediaId`) and nested blocks now remap correctly
- Button and navigation link URLs in block attrs are rewritten without being sideloaded as media
- Queue items no longer deleted before import completes (prevents data loss on timeout)
- Fetch progress counter uses `fetched` count instead of `done`
- Duplicate completion email in background mode
- `last_error` and auth warnings surfaced in the UI
- Background import pauses after repeated consecutive errors

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
