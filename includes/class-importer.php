<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPRestI_Importer {

	private $source_domain       = '';
	private int $assign_author_id = 0;

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
	 * @param string $post_type 'post' or 'page'
	 * @return array { id, title, type, status, action, format }
	 */
	public function import_item( array $item, string $post_type ): array {
		$slug   = sanitize_title( $item['slug'] ?? '' );
		$title  = wp_strip_all_tags( $item['title']['rendered'] ?? '' );
		$status = sanitize_key( $item['status'] ?? 'publish' );
		$date   = sanitize_text_field( $item['date_gmt'] ?? current_time( 'mysql', true ) );

		$raw_content      = isset( $item['content']['raw'] ) && is_string( $item['content']['raw'] )
							? $item['content']['raw']
							: '';
		$rendered_content = $item['content']['rendered'] ?? '';
		$raw_excerpt      = $item['excerpt']['rendered'] ?? '';

		$this->attachment_url_to_id = [];

		// Determine content type and the source string to sideload/save.
		if ( $raw_content !== '' ) {
			// Authenticated path — raw block markup available.
			$is_gutenberg   = $this->is_gutenberg_content( $raw_content );
			$content_type   = $is_gutenberg ? 'gutenberg-raw' : 'classic';
			$source_content = $is_gutenberg ? $raw_content : $rendered_content;
		} else {
			// Unauthenticated path — detect from rendered HTML fingerprints.
			$is_gutenberg   = $this->detect_gutenberg_from_rendered( $rendered_content );
			$content_type   = $is_gutenberg ? 'gutenberg-rendered' : 'classic';
			$source_content = $rendered_content;
		}

		// First-pass sideload (post_id=0 so attachments are unparented initially).
		$post_content = $source_content;
		if ( $this->source_domain ) {
			$post_content = $this->rewrite_and_sideload_images( $source_content, 0 );
		}

		// Raw Gutenberg content already has block markup; everything else goes in an HTML block.
		if ( 'gutenberg-raw' !== $content_type ) {
			$post_content = '<!-- wp:html -->' . "\n" . $post_content . "\n" . '<!-- /wp:html -->';
		}

		// Find existing post by slug to decide create vs update.
		$existing    = get_page_by_path( $slug, OBJECT, $post_type );
		$existing_id = $existing ? (int) $existing->ID : 0;

		$post_data = [
			'post_title'    => $title,
			'post_name'     => $slug,
			'post_status'   => $status,
			'post_type'     => $post_type,
			'post_content'  => $post_content,
			'post_excerpt'  => $raw_excerpt,
			'post_date_gmt' => $date,
			'post_date'     => get_date_from_gmt( $date ),
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

		// Second-pass: re-sideload images with the real post ID, then fix block IDs.
		if ( $this->source_domain ) {
			$final_content = $this->rewrite_and_sideload_images( $source_content, $post_id );
			if ( 'gutenberg-raw' === $content_type ) {
				$final_content = $this->update_gutenberg_block_ids( $final_content );
			} else {
				$final_content = '<!-- wp:html -->' . "\n" . $final_content . "\n" . '<!-- /wp:html -->';
			}
			wp_update_post( [ 'ID' => $post_id, 'post_content' => $final_content ] );
		}

		$this->import_terms( $post_id, $item );
		$this->import_featured_image( $post_id, $item );
		$this->import_seo_meta( $post_id, $item );
		$this->import_author_meta( $post_id, $item );

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

	public function set_assign_author( int $user_id ): void {
		$this->assign_author_id = $user_id;
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
			if ( strpos( $src, $this->source_domain ) === false ) {
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
	 * Sideload an image, skipping if already imported by source URL.
	 *
	 * @return int|WP_Error Attachment ID or error.
	 */
	private function sideload_image( string $image_url, int $post_id ) {
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

		$attachment_id = media_sideload_image( $image_url, $post_id, '', 'id' );

		if ( ! is_wp_error( $attachment_id ) ) {
			update_post_meta( (int) $attachment_id, '_source_url', $image_url );
			$local_url = wp_get_attachment_url( (int) $attachment_id );
			if ( $local_url ) {
				$this->attachment_url_to_id[ $local_url ] = (int) $attachment_id;
			}
		}

		return $attachment_id;
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
			'alignfull',
			'alignwide',
			'has-background',
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

		return preg_replace_callback(
			'/(<!--\s*wp:(?:' . $block_types . ')\s*{[^}]*"id"\s*:\s*\d+[^}]*}\s*-->)(.*?)(<!--\s*\/wp:(?:' . $block_types . ')\s*-->)/s',
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

	private function import_seo_meta( int $post_id, array $item ): void {
		$yoast = $item['yoast_head_json'] ?? [];
		if ( empty( $yoast ) || ! is_array( $yoast ) ) {
			return;
		}

		$description = sanitize_text_field( $yoast['description'] ?? '' );
		if ( ! $description ) {
			return;
		}

		update_post_meta( $post_id, '_yoast_wpseo_metadesc', $description );
		update_post_meta( $post_id, 'rank_math_description', $description );
	}
}
