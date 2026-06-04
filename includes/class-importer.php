<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPRestI_Importer {

	private const META_SOURCE_ID = '_wpresti_source_id';

	private string $source_domain = '';
	private string $source_site_url = '';
	private int $assign_author_id = 0;
	private string $import_mode = 'overwrite';

	/** Maps local attachment URL → local attachment ID; rebuilt per item. */
	private array $attachment_url_to_id = [];

	public function __construct() {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	/**
	 * Import a single post/page item from the REST API response.
	 *
	 * @param array  $item      Decoded REST API item (with _embedded).
	 * @param string $post_type      Post type to create/update on this site.
	 * @param string $source_type    Original type on the source site (optional, for meta).
	 * @return array { id, title, type, status, action, format }
	 */
	public function import_item( array $item, string $post_type, string $source_type = '' ): array {
		if ( '' === $source_type ) {
			$source_type = $post_type;
		}
		$slug   = sanitize_title( $item['slug'] ?? '' );
		$title  = wp_strip_all_tags( $item['title']['rendered'] ?? '' );

		$source_id       = (int) ( $item['id'] ?? 0 );
		$source_parent   = (int) ( $item['parent'] ?? 0 );
		$local_parent_id = $this->resolve_local_parent_id( $source_parent, $post_type );
		$existing_id     = $this->find_existing_post_id( $item, $post_type, $slug, $local_parent_id );

		if ( apply_filters( 'wpresti_skip_item', false, $item, $post_type, $existing_id ) ) {
			return [
				'title'  => $title,
				'type'   => $post_type,
				'status' => 'Skipped (filter)',
				'action' => 'Skipped',
				'format' => '',
			];
		}

		if ( 'new_only' === $this->import_mode && $existing_id > 0 ) {
			return [
				'title'  => $title,
				'type'   => $post_type,
				'status' => 'Skipped (exists)',
				'action' => 'Skipped',
				'format' => '',
			];
		}

		if ( 'update_only' === $this->import_mode && 0 === $existing_id ) {
			return [
				'title'  => $title,
				'type'   => $post_type,
				'status' => 'Skipped (new)',
				'action' => 'Skipped',
				'format' => '',
			];
		}

		do_action( 'wpresti_before_import_item', $item, $post_type, $existing_id );
		$status = sanitize_key( $item['status'] ?? 'publish' );
		$status = in_array( $status, [ 'publish', 'future', 'draft', 'pending', 'private' ], true ) ? $status : 'publish';
		$date   = sanitize_text_field( $item['date_gmt'] ?? current_time( 'mysql', true ) );

		$raw_content      = isset( $item['content']['raw'] ) && is_string( $item['content']['raw'] )
							? $item['content']['raw']
							: '';
		$rendered_content = $item['content']['rendered'] ?? '';

		// Reset map before any sideloading so excerpt and content entries are both retained.
		$this->attachment_url_to_id = [];

		$raw_excerpt = $item['excerpt']['rendered'] ?? '';
		if ( $this->source_domain ) {
			$raw_excerpt = $this->rewrite_and_sideload_images( $raw_excerpt, 0 );
		}
		$raw_excerpt = $this->rewrite_internal_links( $raw_excerpt );

		// Determine content type and the source string to sideload/save.
		if ( $raw_content !== '' ) {
			// Authenticated path — raw block markup available.
			$is_gutenberg   = $this->is_gutenberg_content( $raw_content );
			$content_type   = $is_gutenberg ? 'gutenberg-raw' : 'classic';
			$source_content = $is_gutenberg ? $raw_content : $rendered_content;
		} else {
			// Unauthenticated path — detect from rendered HTML fingerprints.
			$is_gutenberg   = $this->detect_gutenberg_from_rendered( $rendered_content );
			$content_type   = $is_gutenberg ? 'gutenberg-reconstructed' : 'classic';
			$source_content = $rendered_content;
		}

		if ( 'classic' === $content_type && $raw_content !== '' ) {
			$source_content = $raw_content;
		}

		// First-pass sideload (post_id=0 so attachments are unparented initially).
		$post_content = $source_content;
		if ( $this->source_domain ) {
			$post_content = $this->rewrite_and_sideload_images( $source_content, 0 );
		}
		$post_content = $this->rewrite_internal_links( $post_content );

		// Wrap / reconstruct block markup depending on content type.
		if ( 'gutenberg-raw' === $content_type ) {
			// Raw block markup is already correct — nothing to do.
		} elseif ( 'gutenberg-reconstructed' === $content_type ) {
			$post_content = $this->reconstruct_gutenberg_blocks( $post_content );
		} else {
			$post_content = '<!-- wp:html -->' . "\n" . $post_content . "\n" . '<!-- /wp:html -->';
		}

		$post_data = [
			'post_title'        => $title,
			'post_name'         => $slug,
			'post_status'       => $status,
			'post_type'         => $post_type,
			'post_content'      => $post_content,
			'post_excerpt'      => $raw_excerpt,
			'post_date_gmt'     => $date,
			'post_date'         => get_date_from_gmt( $date ),
			'post_parent'       => $local_parent_id,
			'menu_order'        => (int) ( $item['menu_order'] ?? 0 ),
			'comment_status'    => sanitize_key( $item['comment_status'] ?? 'open' ),
			'ping_status'       => sanitize_key( $item['ping_status'] ?? 'open' ),
		];

		if ( $this->assign_author_id > 0 ) {
			$post_data['post_author'] = $this->assign_author_id;
		}

		if ( $existing_id ) {
			$post_data['ID'] = $existing_id;
			$post_id         = wp_update_post( $post_data, true );
			$action          = 'Updated';
		} else {
			$post_id = wp_insert_post( $post_data, true );
			$action  = 'Created';
		}

		if ( is_wp_error( $post_id ) ) {
			return [
				'title'  => $title,
				'type'   => $post_type,
				'status' => 'Error: ' . $post_id->get_error_message(),
				'action' => 'Error',
			];
		}

		// Second-pass: re-sideload images with the real post ID, then rewrite links and fix block IDs.
		if ( $this->source_domain ) {
			$final_content = $this->rewrite_and_sideload_images( $source_content, $post_id );
			$final_content = $this->rewrite_internal_links( $final_content );
			if ( 'gutenberg-raw' === $content_type ) {
				$final_content = $this->update_gutenberg_block_ids( $final_content );
			} elseif ( 'gutenberg-reconstructed' === $content_type ) {
				$final_content = $this->reconstruct_gutenberg_blocks( $final_content );
			} else {
				$final_content = '<!-- wp:html -->' . "\n" . $final_content . "\n" . '<!-- /wp:html -->';
			}

			$raw_excerpt_final = $raw_excerpt;
			if ( $raw_excerpt !== '' ) {
				$raw_excerpt_final = $this->rewrite_and_sideload_images( $item['excerpt']['rendered'] ?? '', $post_id );
				$raw_excerpt_final = $this->rewrite_internal_links( $raw_excerpt_final );
			}

			$update_result = wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => $final_content,
					'post_excerpt' => $raw_excerpt_final,
				],
				true
			);
			if ( is_wp_error( $update_result ) ) {
				return [
					'title'  => $title,
					'type'   => $post_type,
					'status' => 'Error: ' . $update_result->get_error_message(),
					'action' => 'Error',
				];
			}
		}

		if ( $source_id > 0 ) {
			update_post_meta( $post_id, self::META_SOURCE_ID, $source_id );
		}

		if ( $source_type !== $post_type ) {
			update_post_meta( $post_id, '_wpresti_source_post_type', $source_type );
		}

		if ( 'post' === $post_type ) {
			if ( ! empty( $item['sticky'] ) ) {
				stick_post( $post_id );
			} else {
				if ( is_sticky( $post_id ) ) {
					unstick_post( $post_id );
				}
			}

			$format = sanitize_key( $item['format'] ?? 'standard' );
			if ( $format && 'standard' !== $format ) {
				set_post_format( $post_id, $format );
			}
		}

		$this->import_terms( $post_id, $item );
		$this->import_featured_image( $post_id, $item );
		$this->import_seo_meta( $post_id, $item );
		$this->import_author_meta( $post_id, $item );
		$this->import_custom_meta( $post_id, $item );

		if ( ! empty( $item['link'] ) ) {
			update_post_meta( $post_id, '_wpresti_source_url', esc_url_raw( $item['link'] ) );
		}

		update_post_meta( $post_id, '_wpresti_content_type', $content_type );

		return [
			'id'     => $post_id,
			'title'  => $title,
			'type'   => $post_type,
			'status' => $action,
			'action' => $action,
			'format' => $content_type,
		];
	}

	/**
	 * Set the source domain so content images can be identified and sideloaded.
	 */
	public function set_source_domain( string $domain ): void {
		$this->source_domain = $domain;
	}

	public function set_source_url( string $url ): void {
		$this->source_site_url = trailingslashit( esc_url_raw( $url ) );
	}

	public function set_assign_author( int $user_id ): void {
		if ( $user_id > 0 && get_user_by( 'id', $user_id ) ) {
			$this->assign_author_id = $user_id;
		} else {
			$this->assign_author_id = 0;
		}
	}

	public function set_import_mode( string $mode ): void {
		if ( in_array( $mode, [ 'overwrite', 'new_only', 'update_only' ], true ) ) {
			$this->import_mode = $mode;
		}
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	private function import_terms( int $post_id, array $item ): void {
		$embedded_terms = $item['_embedded']['wp:term'] ?? [];

		foreach ( $embedded_terms as $term_group ) {
			if ( ! is_array( $term_group ) || empty( $term_group ) ) {
				continue;
			}

			$taxonomy = sanitize_key( $term_group[0]['taxonomy'] ?? '' );
			if ( ! $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$term_ids = [];
			foreach ( $term_group as $term_data ) {
				$name = sanitize_text_field( $term_data['name'] ?? '' );
				$slug = sanitize_title( $term_data['slug'] ?? '' );

				if ( ! $name ) {
					continue;
				}

				$existing_term = get_term_by( 'slug', $slug, $taxonomy );
				if ( $existing_term ) {
					$term_ids[] = (int) $existing_term->term_id;
				} else {
					$new_term = wp_insert_term( $name, $taxonomy, [ 'slug' => $slug ] );
					if ( ! is_wp_error( $new_term ) ) {
						$term_ids[] = (int) $new_term['term_id'];
					}
				}
			}

			if ( ! empty( $term_ids ) ) {
				wp_set_object_terms( $post_id, $term_ids, $taxonomy );
			}
		}
	}

	private function import_featured_image( int $post_id, array $item ): void {
		$featured_media = $item['_embedded']['wp:featuredmedia'][0] ?? null;
		if ( ! $featured_media ) {
			return;
		}

		$image_url = esc_url_raw( $featured_media['source_url'] ?? '' );
		if ( ! $image_url ) {
			return;
		}

		$attachment_id = $this->sideload_image( $image_url, $post_id );
		if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}
	}

	private function rewrite_and_sideload_images( string $content, int $post_id ): string {
		if ( ! $this->source_domain || ! $content ) {
			return $content;
		}

		preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches );

		if ( empty( $matches[1] ) ) {
			return $content;
		}

		foreach ( $matches[1] as $src ) {
			if ( ! $this->should_sideload_image_url( $src ) ) {
				continue;
			}

			$new_id = $this->sideload_image( $src, $post_id );
			if ( $new_id && ! is_wp_error( $new_id ) ) {
				$new_url = wp_get_attachment_url( $new_id );
				if ( $new_url ) {
					$content = str_replace( $src, $new_url, $content );
				}
			}
		}

		return $content;
	}

	/**
	 * Whether an image URL from remote content should be sideloaded.
	 */
	private function should_sideload_image_url( string $url ): bool {
		if ( ! $url ) {
			return false;
		}

		if ( $this->source_domain && false !== strpos( $url, $this->source_domain ) ) {
			return true;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( $host && $this->source_domain ) {
			if ( $host === $this->source_domain ) {
				return true;
			}
			$suffix = '.' . $this->source_domain;
			if ( strlen( $host ) > strlen( $suffix ) && substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		// Any WordPress-style uploads path, including multisite/CDN hosts such as
		// cdn.carrot.com/uploads/sites/<id>/<year>/<month>/file.ext.
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( is_string( $path ) && false !== strpos( $path, '/uploads/' ) ) {
			return true;
		}

		return false;
	}

	private function find_existing_post_id( array $item, string $post_type, string $slug, int $local_parent_id ): int {
		$source_id = (int) ( $item['id'] ?? 0 );

		if ( $source_id > 0 ) {
			$by_source = get_posts(
				[
					'post_type'      => $post_type,
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => self::META_SOURCE_ID,
					'meta_value'     => $source_id,
				]
			);

			if ( ! empty( $by_source ) ) {
				return (int) $by_source[0];
			}
		}

		if ( '' === $slug ) {
			return 0;
		}

		if ( $local_parent_id > 0 ) {
			$children = get_posts(
				[
					'post_type'      => $post_type,
					'post_status'    => 'any',
					'post_parent'    => $local_parent_id,
					'name'           => $slug,
					'posts_per_page' => 1,
					'fields'         => 'ids',
				]
			);

			if ( ! empty( $children ) ) {
				return (int) $children[0];
			}

			return 0;
		}

		$existing = get_page_by_path( $slug, OBJECT, $post_type );

		return $existing ? (int) $existing->ID : 0;
	}

	private function resolve_local_parent_id( int $source_parent_id, string $post_type ): int {
		if ( $source_parent_id <= 0 ) {
			return 0;
		}

		$local_parents = get_posts(
			[
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::META_SOURCE_ID,
				'meta_value'     => $source_parent_id,
			]
		);

		return ! empty( $local_parents ) ? (int) $local_parents[0] : 0;
	}

	private function is_safe_url( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}
		$ip = gethostbyname( $host );
		return (bool) filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	/**
	 * Sideload an image, skipping if already imported by source URL.
	 *
	 * @return int|WP_Error Attachment ID or error.
	 */
	private function sideload_image( string $image_url, int $post_id ) {
		if ( ! $this->is_safe_url( $image_url ) ) {
			return new WP_Error( 'blocked_url', 'Image URL resolves to a private or reserved address.' );
		}

		$existing = get_posts(
			[
				'post_type'      => 'attachment',
				'meta_key'       => '_source_url',
				'meta_value'     => $image_url,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			]
		);

		if ( ! empty( $existing ) ) {
			$attachment_id = (int) $existing[0];
			$local_url     = wp_get_attachment_url( $attachment_id );
			if ( $local_url ) {
				$this->attachment_url_to_id[ $local_url ] = $attachment_id;
			}
			return $attachment_id;
		}

		$attachment_id = $this->sideload_with_date( $image_url, $post_id );

		if ( ! is_wp_error( $attachment_id ) ) {
			update_post_meta( (int) $attachment_id, '_source_url', $image_url );
			$local_url = wp_get_attachment_url( (int) $attachment_id );
			if ( $local_url ) {
				$this->attachment_url_to_id[ $local_url ] = (int) $attachment_id;
			}
		}

		return $attachment_id;
	}

	/**
	 * Download and attach an image, preserving the source's year/month folder
	 * (e.g. .../2024/10/file.png → uploads/2024/10/file.png) and dating the
	 * attachment to match. Falls back to the default upload folder when the URL
	 * carries no date segment.
	 *
	 * @return int|WP_Error Attachment ID or error.
	 */
	private function sideload_with_date( string $image_url, int $post_id ) {
		$ym     = $this->date_from_image_url( $image_url );
		$filter = null;

		if ( $ym ) {
			$subdir = '/' . $ym[0] . '/' . $ym[1];
			$filter = static function ( array $dirs ) use ( $subdir ): array {
				if ( '' === ( $dirs['error'] ?? '' ) || empty( $dirs['error'] ) ) {
					$dirs['subdir'] = $subdir;
					$dirs['path']   = $dirs['basedir'] . $subdir;
					$dirs['url']    = $dirs['baseurl'] . $subdir;
				}
				return $dirs;
			};
			add_filter( 'upload_dir', $filter );
		}

		$tmp = download_url( $image_url );

		if ( is_wp_error( $tmp ) ) {
			if ( $filter ) {
				remove_filter( 'upload_dir', $filter );
			}
			return $tmp;
		}

		$url_path   = (string) wp_parse_url( $image_url, PHP_URL_PATH );
		$file_array = [
			'name'     => wp_basename( '' !== $url_path ? $url_path : $image_url ),
			'tmp_name' => $tmp,
		];

		$post_data = [];
		if ( $ym ) {
			$date                       = $ym[0] . '-' . $ym[1] . '-01 00:00:00';
			$post_data['post_date']     = $date;
			$post_data['post_date_gmt'] = get_gmt_from_date( $date );
		}

		$attachment_id = media_handle_sideload( $file_array, $post_id, null, $post_data );

		if ( $filter ) {
			remove_filter( 'upload_dir', $filter );
		}

		if ( is_wp_error( $attachment_id ) && file_exists( $file_array['tmp_name'] ) ) {
			wp_delete_file( $file_array['tmp_name'] );
		}

		return $attachment_id;
	}

	/**
	 * Extract a [year, month] pair from a WordPress uploads URL, if present.
	 *
	 * @return array{0:string,1:string}|null
	 */
	private function date_from_image_url( string $url ): ?array {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( preg_match( '#/(\d{4})/(\d{2})/#', $path, $m ) ) {
			$year  = (int) $m[1];
			$month = (int) $m[2];
			if ( $year >= 1970 && $year <= 2100 && $month >= 1 && $month <= 12 ) {
				return [ $m[1], $m[2] ];
			}
		}

		return null;
	}

	private function import_author_meta( int $post_id, array $item ): void {
		$author = $item['_embedded']['author'][0] ?? null;

		$name  = '';
		$login = '';
		$email = '';

		if ( is_array( $author ) ) {
			$name  = sanitize_text_field( $author['name'] ?? '' );
			$login = sanitize_user( $author['slug'] ?? '', true );
			$email = sanitize_email( $author['email'] ?? '' );
		}

		update_post_meta( $post_id, '_original_author_name',  $name );
		update_post_meta( $post_id, '_original_author_login', $login );
		update_post_meta( $post_id, '_original_author_email', $email );
	}

	/** Detect Gutenberg from raw content (block comment markers). */
	private function is_gutenberg_content( string $content ): bool {
		return strpos( $content, '<!-- wp:' ) !== false;
	}

	/**
	 * Detect Gutenberg from rendered HTML when raw content is unavailable.
	 * Checks for CSS classes that Gutenberg's block renderer always emits.
	 */
	private function detect_gutenberg_from_rendered( string $content ): bool {
		$signals = [
			'wp-block-',
			'is-layout-flex',
			'is-layout-flow',
			'wp-container-',
			'wp-block-group',
			'wp-block-columns',
			'wp-block-image',
			'wp-block-heading',
			'wp-block-paragraph',
			'wp-block-list',
			'wp-block-quote',
			'wp-block-separator',
			'wp-block-buttons',
			'wp-block-cover',
			'wp-block-media-text',
			'wp-block-table',
		];

		foreach ( $signals as $signal ) {
			if ( strpos( $content, $signal ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Update block-comment attachment IDs after images have been sideloaded.
	 *
	 * Gutenberg stores the local attachment ID inside block comment JSON
	 * (e.g. <!-- wp:image {"id":123} -->). After sideloading, the IDs refer
	 * to the source site, so we replace them with the new local IDs matched
	 * by looking up each block's img src in $this->attachment_url_to_id.
	 */
	private function update_gutenberg_block_ids( string $content ): string {
		if ( empty( $this->attachment_url_to_id ) ) {
			return $content;
		}

		$block_types = 'image|gallery|cover|media-text';
		$close_tag   = '<!--\s*\/wp:(?:' . $block_types . ')\s*-->';

		return preg_replace_callback(
			'/(<!--\s*wp:(?:' . $block_types . ')\s*\{[^}]*"id"\s*:\s*\d+[^}]*\}\s*-->)'
			. '((?:(?!' . $close_tag . ')[\s\S])*)'
			. '(' . $close_tag . ')/u',
			function ( $m ) {
				$opening = $m[1];
				$inner   = $m[2];
				$closing = $m[3];

				if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $inner, $img_m ) ) {
					$src = $img_m[1];
					if ( isset( $this->attachment_url_to_id[ $src ] ) ) {
						$new_id  = $this->attachment_url_to_id[ $src ];
						$opening = preg_replace( '/"id"\s*:\s*\d+/', '"id":' . $new_id, $opening );
					}
				}

				return $opening . $inner . $closing;
			},
			$content
		);
	}

	/**
	 * Parse rendered HTML and wrap each top-level element in its Gutenberg block comment.
	 * Falls back to wp:html for elements with no matching core block.
	 */
	private function reconstruct_gutenberg_blocks( string $html ): string {
		if ( ! $html ) {
			return '';
		}

		$dom = new DOMDocument( '1.0', 'utf-8' );
		$previous_libxml_state = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_libxml_state );

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) {
			return '<!-- wp:html -->' . "\n" . $html . "\n" . '<!-- /wp:html -->';
		}

		// Snapshot to a plain array so the list stays stable during attribute mutations.
		$children = [];
		foreach ( $body->childNodes as $child ) {
			$children[] = $child;
		}

		$blocks = '';
		foreach ( $children as $node ) {
			if ( XML_TEXT_NODE === $node->nodeType ) {
				if ( '' === trim( $node->nodeValue ) ) {
					continue;
				}
			}
			if ( XML_ELEMENT_NODE !== $node->nodeType ) {
				continue;
			}
			/** @var DOMElement $node */
			$blocks .= $this->element_to_block( $dom, $node );
		}

		return rtrim( $blocks );
	}

	private function element_to_block( DOMDocument $dom, DOMElement $node ): string {
		$tag     = strtolower( $node->nodeName );
		$classes = $node->getAttribute( 'class' );

		// Heading.
		if ( in_array( $tag, [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ], true ) ) {
			$level = (int) substr( $tag, 1 );
			$html  = $dom->saveHTML( $node );
			return "<!-- wp:heading {\"level\":{$level}} -->\n{$html}\n<!-- /wp:heading -->\n\n";
		}

		// Paragraph.
		if ( 'p' === $tag ) {
			$html = $dom->saveHTML( $node );
			return "<!-- wp:paragraph -->\n{$html}\n<!-- /wp:paragraph -->\n\n";
		}

		// Separator.
		if ( 'hr' === $tag ) {
			$html = $dom->saveHTML( $node );
			return "<!-- wp:separator -->\n{$html}\n<!-- /wp:separator -->\n\n";
		}

		// Quote.
		if ( 'blockquote' === $tag ) {
			$html = $dom->saveHTML( $node );
			return "<!-- wp:quote -->\n{$html}\n<!-- /wp:quote -->\n\n";
		}

		// Lists.
		if ( 'ul' === $tag ) {
			$html = $dom->saveHTML( $node );
			return "<!-- wp:list -->\n{$html}\n<!-- /wp:list -->\n\n";
		}
		if ( 'ol' === $tag ) {
			$html = $dom->saveHTML( $node );
			return "<!-- wp:list {\"ordered\":true} -->\n{$html}\n<!-- /wp:list -->\n\n";
		}

		// Pre-based blocks.
		if ( 'pre' === $tag ) {
			$html = $dom->saveHTML( $node );
			if ( false !== strpos( $classes, 'wp-block-code' ) ) {
				return "<!-- wp:code -->\n{$html}\n<!-- /wp:code -->\n\n";
			}
			if ( false !== strpos( $classes, 'wp-block-verse' ) ) {
				return "<!-- wp:verse -->\n{$html}\n<!-- /wp:verse -->\n\n";
			}
			if ( false !== strpos( $classes, 'wp-block-preformatted' ) ) {
				return "<!-- wp:preformatted -->\n{$html}\n<!-- /wp:preformatted -->\n\n";
			}
		}

		// Figure-based blocks.
		if ( 'figure' === $tag ) {
			$html = $dom->saveHTML( $node );
			if ( false !== strpos( $classes, 'wp-block-table' ) ) {
				return "<!-- wp:table -->\n{$html}\n<!-- /wp:table -->\n\n";
			}
			if ( false !== strpos( $classes, 'wp-block-pullquote' ) ) {
				return "<!-- wp:pullquote -->\n{$html}\n<!-- /wp:pullquote -->\n\n";
			}
		}

		// Div-based blocks — most specific class checked first.
		if ( 'div' === $tag ) {
			// Image wrapper div → transforms inner figure.
			if ( false !== strpos( $classes, 'wp-block-image' ) ) {
				return $this->image_div_to_block( $dom, $node );
			}

			// Spacer — extract height and add aria-hidden.
			if ( false !== strpos( $classes, 'wp-block-spacer' ) ) {
				$style  = $node->getAttribute( 'style' );
				$height = '100px';
				if ( preg_match( '/height\s*:\s*([^;]+)/i', $style, $m ) ) {
					$height = trim( $m[1] );
				}
				$node->setAttribute( 'aria-hidden', 'true' );
				$html = $dom->saveHTML( $node );
				return "<!-- wp:spacer {\"height\":\"{$height}\"} -->\n{$html}\n<!-- /wp:spacer -->\n\n";
			}

			// Buttons (plural) before button (singular) — "wp-block-buttons" is longer.
			if ( false !== strpos( $classes, 'wp-block-buttons' ) ) {
				$html = $dom->saveHTML( $node );
				return "<!-- wp:buttons -->\n{$html}\n<!-- /wp:buttons -->\n\n";
			}

			if ( false !== strpos( $classes, 'wp-block-button' ) ) {
				$html = $dom->saveHTML( $node );
				return "<!-- wp:button -->\n{$html}\n<!-- /wp:button -->\n\n";
			}

			if ( false !== strpos( $classes, 'wp-block-columns' ) ) {
				$html = $dom->saveHTML( $node );
				return "<!-- wp:columns -->\n{$html}\n<!-- /wp:columns -->\n\n";
			}

			if ( false !== strpos( $classes, 'wp-block-group' ) ) {
				$html = $dom->saveHTML( $node );
				return "<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n{$html}\n<!-- /wp:group -->\n\n";
			}

			if ( false !== strpos( $classes, 'wp-block-media-text' ) ) {
				$html = $dom->saveHTML( $node );
				return "<!-- wp:media-text -->\n{$html}\n<!-- /wp:media-text -->\n\n";
			}

			if ( false !== strpos( $classes, 'wp-block-cover' ) ) {
				$html = $dom->saveHTML( $node );
				return "<!-- wp:cover -->\n{$html}\n<!-- /wp:cover -->\n\n";
			}
		}

		// Fallback for unknown / third-party blocks.
		$html = $dom->saveHTML( $node );
		return "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->\n\n";
	}

	/**
	 * Transform a wp-block-image wrapper div into a wp:image block.
	 * Extracts the inner figure, copies the size class, and outputs the
	 * figure directly (dropping the outer div) as Gutenberg expects.
	 */
	private function image_div_to_block( DOMDocument $dom, DOMElement $div ): string {
		$figures = $div->getElementsByTagName( 'figure' );
		if ( 0 === $figures->length ) {
			$html = $dom->saveHTML( $div );
			return "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->\n\n";
		}

		$figure    = $figures->item( 0 );
		$fig_class = $figure->getAttribute( 'class' );
		$size      = 'full';
		if ( preg_match( '/\bsize-(\S+)\b/', $fig_class, $m ) ) {
			$size = sanitize_key( $m[1] );
			if ( '' === $size ) {
				$size = 'full';
			}
		}

		$figure->setAttribute( 'class', 'wp-block-image size-' . $size );
		$html = $dom->saveHTML( $figure );

		return "<!-- wp:image {\"sizeSlug\":\"{$size}\",\"linkDestination\":\"none\"} -->\n{$html}\n<!-- /wp:image -->\n\n";
	}

	/**
	 * Replace all URLs pointing to the source site with the destination site URL.
	 * Handles http/https variants and www/non-www variants. External links are untouched.
	 */
	private function rewrite_internal_links( string $content ): string {
		if ( ! $this->source_site_url || ! $content ) {
			return $content;
		}

		$source      = rtrim( $this->source_site_url, '/' );
		$destination = rtrim( get_site_url(), '/' );

		if ( $source === $destination ) {
			return $content;
		}

		$source_host = wp_parse_url( $source, PHP_URL_HOST ) ?? '';
		if ( ! $source_host ) {
			return $content;
		}

		$host_variants = [ $source_host ];
		if ( strpos( $source_host, 'www.' ) === 0 ) {
			$host_variants[] = substr( $source_host, 4 );
		} else {
			$host_variants[] = 'www.' . $source_host;
		}

		$schemes = [ 'https', 'http' ];
		foreach ( $host_variants as $host_variant ) {
			foreach ( $schemes as $scheme ) {
				$base    = $scheme . '://' . $host_variant;
				$content = str_replace( $base . '/', $destination . '/', $content );
				$content = str_replace( $base . '"', $destination . '"', $content );
				$content = str_replace( $base . "'", $destination . "'", $content );
			}
		}

		return $content;
	}

	private function import_seo_meta( int $post_id, array $item ): void {
		$yoast = $item['yoast_head_json'] ?? [];
		if ( empty( $yoast ) || ! is_array( $yoast ) ) {
			return;
		}

		$description = sanitize_text_field( $yoast['description'] ?? '' );
		if ( $description ) {
			$description = $this->rewrite_internal_links( $description );
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $description );
			update_post_meta( $post_id, 'rank_math_description', $description );
		}

		$title = sanitize_text_field( $yoast['title'] ?? '' );
		if ( $title ) {
			update_post_meta( $post_id, '_yoast_wpseo_title', $title );
			update_post_meta( $post_id, 'rank_math_title', $title );
		}

		$og_title = sanitize_text_field( $yoast['og_title'] ?? '' );
		if ( $og_title ) {
			update_post_meta( $post_id, '_yoast_wpseo_opengraph-title', $og_title );
		}
	}

	private function import_custom_meta( int $post_id, array $item ): void {
		$meta = $item['meta'] ?? [];
		if ( ! is_array( $meta ) || empty( $meta ) ) {
			return;
		}

		foreach ( $meta as $key => $value ) {
			$key = sanitize_key( $key );
			if ( '' === $key || '_' === $key[0] ) {
				continue;
			}
			if ( is_array( $value ) || is_object( $value ) ) {
				continue;
			}
			update_post_meta( $post_id, $key, sanitize_text_field( (string) $value ) );
		}

		do_action( 'wpresti_import_post_meta', $post_id, $item );
	}
}
