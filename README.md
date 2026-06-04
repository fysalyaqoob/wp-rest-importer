# WP REST Importer
by Faisal Yaqoob — https://fysalyaqoob.com

Import posts, pages, and custom post types from any public WordPress site via the WP REST API.

## Features

- Full-site or slug-based import (multiple slugs supported)
- Import modes: overwrite, new only, update only
- Date range, category slug, and status filters
- Optional custom post type REST base
- Background import via WP-Cron (close the browser tab)
- Test connection before importing
- Cancel import / clear session
- Queue and log stored in custom DB tables (scalable for large sites)
- Pipelined fetch → import (configurable batch and page size)
- Load more + download log as CSV
- Gutenberg / classic detection with Application Password support
- Page hierarchy, sticky posts, formats, menu order
- Yoast / Rank Math SEO fields (title, description, OG)
- Author reassignment tool
- Plugin settings tab
- Developer hooks: `wpresti_skip_item`, `wpresti_before_import_item`, `wpresti_after_import_item`, `wpresti_item_data`, `wpresti_rest_query_args`

## Requirements

- WordPress 5.8+
- PHP 7.4+
- Source site REST API publicly reachable (Application Password recommended)

## Installation

1. Upload plugin to `wp-content/plugins/wp-rest-importer`
2. Activate
3. Go to **Tools → WP REST Importer**

## Usage

1. Enter source URL → **Test Connection**
2. Choose import type, mode, filters, optional slug(s) or CPT base
3. Enable **Run in background** for large sites
4. **Start Import** — monitor progress or leave it to WP-Cron
5. **Download log CSV** when finished
6. **Reassign Authors** after creating matching local users

## Settings

**Settings** tab: batch size, REST page size, default import mode, SSL verify, rate limit, completion email.

## Uninstall

Removing the plugin deletes custom tables and options (`uninstall.php`).

## License

GPL2 — https://www.gnu.org/licenses/gpl-2.0.html
