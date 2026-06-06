<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPRestI_Importer {

	private const META_SOURCE_ID = '_wpresti_source_id';

	private WPRestI_Importer_Media $media;
	private WPRestI_Importer_Meta $meta;
	private WPRestI_Importer_Terms $terms;
	private WPRestI_Importer_Links $links;
	private WPRestI_Importer_Gutenberg $gutenberg;

	private string $source_domain = '';
	private string $source_site_url = '';
	private int $assign_author_id = 0;
	private string $import_mode = 'overwrite';
	private bool $dry_run = false;

	/** @var callable|null */
	private $media_failure_logger;

	public function __construct() {
		$this->links     = new WPRestI_Importer_Links();
		$this->media     = new WPRestI_Importer_Media();
		$this->meta      = new WPRestI_Importer_Meta( $this->links );
		$this->terms     = new WPRestI_Importer_Terms();
		$this->gutenberg = new WPRestI_Importer_Gutenberg();
	}

	/**
	 * @param array  $item       Decoded REST API item (with _embedded).
	 * @param string $post_type  Post type to create/update on this site.
	 * @param string $source_type Original type on the source site.
	 * @return array { id, title, type, status, action, format }
	 */
	public function import_item( array $item, string $post_type, string $source_type = '' ): array {
		if ( '' === $source_type ) {
			$source_type = $post_type;
		}

		$slug  = sanitize_title( $item['slug'] ?? '' );
		$title = wp_strip_all_tags( $item['title']['rendered'] ?? '' );

		$source_id       = (int) ( $item['id'] ?? 0 );
		$source_parent   = (int) ( $item['parent'] ?? 0 );
		$local_parent_id = $this->resolve_local_parent_id( $source_parent, $post_type );
		$existing_id     = $this->find_existing_post_id( $item, $post_type, $slug, $local_parent_id );

		if ( apply_filters( 'wpresti_skip_item', false, $item, $post_type, $existing_id ) ) {
			return $this->result_row( $title, $post_type, 'Skipped (filter)', 'Skipped', '' );
		}

		if ( 'new_only' === $this->import_mode && $existing_id > 0 ) {
			return $this->result_row( $title, $post_type, 'Skipped (exists)', 'Skipped', '' );
		}

		if ( 'update_only' === $this->import_mode && 0 === $existing_id ) {
			return $this->result_row( $title, $post_type, 'Skipped (new)', 'Skipped', '' );
		}

		do_action( 'wpresti_before_import_item', $item, $post_type, $existing_id );

		if ( $this->dry_run ) {
			$action = $existing_id ? 'Would update' : 'Would create';
			return $this->result_row( $title, $post_type, $action, 'Dry-run', '' );
		}

		$status = sanitize_key( $item['status'] ?? 'publish' );
		$status = in_array( $status, [ 'publish', 'future', 'draft', 'pending', 'private' ], true ) ? $status : 'publish';
		$date   = sanitize_text_field( $item['date_gmt'] ?? current_time( 'mysql', true ) );
		$modified_gmt = sanitize_text_field( $item['modified_gmt'] ?? '' );

		$raw_content      = isset( $item['content']['raw'] ) && is_string( $item['content']['raw'] )
							? $item['content']['raw']
							: '';
		$rendered_content = $item['content']['rendered'] ?? '';

		$this->media->reset_url_map();

		$raw_excerpt = $item['excerpt']['rendered'] ?? '';
		if ( $this->source_domain ) {
			$raw_excerpt = $this->media->rewrite_and_sideload( $raw_excerpt, 0 );
		}
		$raw_excerpt = $this->links->rewrite( $raw_excerpt );

		if ( $raw_content !== '' ) {
			$is_gutenberg   = $this->is_gutenberg_content( $raw_content );
			$content_type   = $is_gutenberg ? 'gutenberg-raw' : 'classic';
			$source_content = $is_gutenberg ? $raw_content : $rendered_content;
		} else {
			$is_gutenberg   = $this->detect_gutenberg_from_rendered( $rendered_content );
			$content_type   = $is_gutenberg ? 'gutenberg-reconstructed' : 'classic';
			$source_content = $rendered_content;
		}

		if ( 'classic' === $content_type && $raw_content !== '' ) {
			$source_content = $raw_content;
		}

		$post_content = $this->prepare_content_for_save( $source_content, $content_type, 0 );

		$post_data = [
			'post_title'     => $title,
			'post_name'      => $slug,
			'post_status'    => $status,
			'post_type'      => $post_type,
			'post_content'   => $post_content,
			'post_excerpt'   => $raw_excerpt,
			'post_date_gmt'  => $date,
			'post_date'      => get_date_from_gmt( $date ),
			'post_parent'    => $local_parent_id,
			'menu_order'     => (int) ( $item['menu_order'] ?? 0 ),
			'comment_status' => sanitize_key( $item['comment_status'] ?? 'open' ),
			'ping_status'    => sanitize_key( $item['ping_status'] ?? 'open' ),
		];

		if ( $modified_gmt ) {
			$post_data['post_modified_gmt'] = $modified_gmt;
			$post_data['post_modified']     = get_date_from_gmt( $modified_gmt );
		}

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
			return $this->result_row( $title, $post_type, 'Error: ' . $post_id->get_error_message(), 'Error', '' );
		}

		if ( $this->source_domain || 'gutenberg-raw' === $content_type ) {
			$final_content = $this->prepare_content_for_save( $source_content, $content_type, $post_id );

			$raw_excerpt_final = $raw_excerpt;
			if ( $raw_excerpt !== '' ) {
				$raw_excerpt_final = $this->media->rewrite_and_sideload( $item['excerpt']['rendered'] ?? '', $post_id );
				$raw_excerpt_final = $this->links->rewrite( $raw_excerpt_final );
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
				return $this->result_row( $title, $post_type, 'Error: ' . $update_result->get_error_message(), 'Error', '' );
			}
		}

		if ( $source_id > 0 ) {
			update_post_meta( $post_id, self::META_SOURCE_ID, $source_id );
		}

		if ( $source_type !== $post_type ) {
			update_post_meta( $post_id, '_wpresti_source_post_type', $source_type );
		}

		if ( ! empty( $item['link'] ) ) {
			update_post_meta( $post_id, '_wpresti_source_url', esc_url_raw( $item['link'] ) );
		}

		update_post_meta( $post_id, '_wpresti_content_type', $content_type );

		if ( 'post' === $post_type ) {
			if ( ! empty( $item['sticky'] ) ) {
				stick_post( $post_id );
			} elseif ( is_sticky( $post_id ) ) {
				unstick_post( $post_id );
			}

			$format = sanitize_key( $item['format'] ?? 'standard' );
			if ( $format && 'standard' !== $format ) {
				set_post_format( $post_id, $format );
			}
		}

		$this->terms->import_terms( $post_id, $item );
		$this->media->import_featured_image( $post_id, $item );
		$this->meta->import_all( $post_id, $item );
		$this->meta->import_author_meta( $post_id, $item );

		if ( WPRestI_Settings::get( 'import_og_image' ) ) {
			$og_image = $item['yoast_head_json']['og_image'] ?? '';
			if ( is_string( $og_image ) && $og_image ) {
				$this->media->import_og_image( $post_id, $og_image );
			}
		}

		return [
			'id'     => $post_id,
			'title'  => $title,
			'type'   => $post_type,
			'status' => $action,
			'action' => $action,
			'format' => $content_type,
		];
	}

	public function set_source_domain( string $domain ): void {
		$this->source_domain = $domain;
		$this->media->set_source_domain( $domain );
	}

	public function set_source_url( string $url ): void {
		$this->source_site_url = trailingslashit( esc_url_raw( $url ) );
		$this->links->set_source_url( $url );
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

	public function set_dry_run( bool $dry_run ): void {
		$this->dry_run = $dry_run;
	}

	/**
	 * @param callable $callback function( string $url, string $error ): void
	 */
	public function set_media_failure_logger( callable $callback ): void {
		$this->media_failure_logger = $callback;
		$this->media->set_failure_logger( $callback );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function result_row( string $title, string $type, string $status, string $action, string $format ): array {
		return [
			'title'  => $title,
			'type'   => $type,
			'status' => $status,
			'action' => $action,
			'format' => $format,
		];
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

	private function prepare_content_for_save( string $source_content, string $content_type, int $post_id ): string {
		if ( 'gutenberg-raw' === $content_type ) {
			return $this->gutenberg->process( $source_content, $this->media, $this->links, $post_id );
		}

		$content = $source_content;
		if ( $this->source_domain ) {
			$content = $this->media->rewrite_and_sideload( $content, $post_id );
		}
		$content = $this->links->rewrite( $content );

		if ( 'gutenberg-reconstructed' === $content_type ) {
			return $this->reconstruct_gutenberg_blocks( $content );
		}

		if ( 'classic' === $content_type ) {
			return '<!-- wp:html -->' . "\n" . $content . "\n" . '<!-- /wp:html -->';
		}

		return $content;
	}

	private function is_gutenberg_content( string $content ): bool {
		return $this->gutenberg->is_block_content( $content );
	}

	private function detect_gutenberg_from_rendered( string $content ): bool {
		$signals = [
			'wp-block-', 'is-layout-flex', 'is-layout-flow', 'wp-container-',
			'wp-block-group', 'wp-block-columns', 'wp-block-image', 'wp-block-heading',
			'wp-block-paragraph', 'wp-block-list', 'wp-block-quote', 'wp-block-separator',
			'wp-block-buttons', 'wp-block-cover', 'wp-block-media-text', 'wp-block-table',
		];

		foreach ( $signals as $signal ) {
			if ( strpos( $content, $signal ) !== false ) {
				return true;
			}
		}

		return false;
	}

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

		$children = [];
		foreach ( $body->childNodes as $child ) {
			$children[] = $child;
		}

		$blocks = '';
		foreach ( $children as $node ) {
			if ( XML_TEXT_NODE === $node->nodeType && '' === trim( $node->nodeValue ) ) {
				continue;
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

		if ( in_array( $tag, [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ], true ) ) {
			$level = (int) substr( $tag, 1 );
			$html  = $dom->saveHTML( $node );
			return "<!-- wp:heading {\"level\":{$level}} -->\n{$html}\n<!-- /wp:heading -->\n\n";
		}

		if ( 'p' === $tag ) {
			$html = $dom->saveHTML( $node );
			return "<!-- wp:paragraph -->\n{$html}\n<!-- /wp:paragraph -->\n\n";
		}

		if ( 'hr' === $tag ) {
			$html = $dom->saveHTML( $node );
			return "<!-- wp:separator -->\n{$html}\n<!-- /wp:separator -->\n\n";
		}

		if ( 'blockquote' === $tag ) {
			$html = $dom->saveHTML( $node );
			return "<!-- wp:quote -->\n{$html}\n<!-- /wp:quote -->\n\n";
		}

		if ( 'ul' === $tag ) {
			$html = $dom->saveHTML( $node );
			return "<!-- wp:list -->\n{$html}\n<!-- /wp:list -->\n\n";
		}

		if ( 'ol' === $tag ) {
			$html = $dom->saveHTML( $node );
			return "<!-- wp:list {\"ordered\":true} -->\n{$html}\n<!-- /wp:list -->\n\n";
		}

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

		if ( 'figure' === $tag ) {
			$html = $dom->saveHTML( $node );
			if ( false !== strpos( $classes, 'wp-block-table' ) ) {
				return "<!-- wp:table -->\n{$html}\n<!-- /wp:table -->\n\n";
			}
			if ( false !== strpos( $classes, 'wp-block-pullquote' ) ) {
				return "<!-- wp:pullquote -->\n{$html}\n<!-- /wp:pullquote -->\n\n";
			}
		}

		if ( 'div' === $tag ) {
			if ( false !== strpos( $classes, 'wp-block-image' ) ) {
				return $this->image_div_to_block( $dom, $node );
			}

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

		$html = $dom->saveHTML( $node );
		return "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->\n\n";
	}

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
}
