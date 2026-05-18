# WP REST Importer
by Faisal Yaqoob — https://fysalyaqoob.com

Import posts, pages, media, categories, tags and authors from any public WordPress site via the WP REST API. No scraping, no CSV.

## Features
- Imports posts and/or pages
- Creates categories and tags automatically
- Sideloads featured and in-content images to Media Library
- Rewrites image URLs in post content to new site
- Preserves original publish dates and post status
- Overwrites existing posts by slug (safe to re-run)
- Author assignment with reassignment tool
- Clean minimal admin UI using WordPress Dashicons

## Requirements
- WordPress 5.8+
- PHP 7.4+
- Source site must have WP REST API publicly accessible

## Installation
1. Download zip
2. Upload via Plugins > Add New > Upload Plugin
3. Activate
4. Go to Tools > WP REST Importer

## Usage
1. Enter source WordPress site URL
2. Select import type: Posts / Pages / Both
3. Select user to assign imported posts to
4. Click Start Import
5. Monitor live progress and log
6. Use Reassign Authors tab after creating matching users

## Changelog
### 1.0.0
- Initial release

## License
GPL2 — https://www.gnu.org/licenses/gpl-2.0.html
