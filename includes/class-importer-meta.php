<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post meta import: public fields, allowlisted private keys, ACF bridge, SEO.
 */
class WPRestI_Importer_Meta {

	private WPRestI_Importer_Links $links;

	public function __construct( WPRestI_Importer_Links $links ) {
		$this->links = $links;
	}

	public function import_all( int $post_id, array $item ): void {
		$this->import_page_template( $post_id, $item );
		$this->import_seo_meta( $post_id, $item );
		$this->import_custom_meta( $post_id, $item );
		$this->import_acf_meta( $post_id, $item );
		do_action( 'wpresti_import_post_meta', $post_id, $item );
	}

	public function import_author_meta( int $post_id, array $item ): void {
		$author = $item['_embedded']['author'][0] ?? null;

		$name  = '';
		$login = '';
		$email = '';

		if ( is_array( $author ) ) {
			$name  = sanitize_text_field( $author['name'] ?? '' );
			$login = sanitize_user( $author['slug'] ?? '', true );
			$email = sanitize_email( $author['email'] ?? '' );
		}

		update_post_meta( $post_id, '_original_author_name', $name );
		update_post_meta( $post_id, '_original_author_login', $login );
		update_post_meta( $post_id, '_original_author_email', $email );
	}

	private function import_page_template( int $post_id, array $item ): void {
		if ( ! WPRestI_Settings::get( 'import_page_template' ) ) {
			return;
		}

		$template = sanitize_file_name( $item['template'] ?? '' );
		if ( $template && 'default' !== $template ) {
			update_post_meta( $post_id, '_wp_page_template', $template );
		}
	}

	private function import_seo_meta( int $post_id, array $item ): void {
		$yoast = $item['yoast_head_json'] ?? [];
		if ( empty( $yoast ) || ! is_array( $yoast ) ) {
			return;
		}

		$description = sanitize_text_field( $yoast['description'] ?? '' );
		if ( $description ) {
			$description = $this->links->rewrite( $description );
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
			update_post_meta( $post_id, 'rank_math_facebook_title', $og_title );
		}
	}

	private function import_custom_meta( int $post_id, array $item ): void {
		$meta = $item['meta'] ?? [];
		if ( ! is_array( $meta ) || empty( $meta ) ) {
			return;
		}

		foreach ( $meta as $key => $value ) {
			$key = sanitize_key( $key );
			if ( '' === $key || ! $this->should_import_meta_key( $key ) ) {
				continue;
			}

			$stored = $this->normalize_meta_value( $value );
			if ( null === $stored ) {
				continue;
			}

			update_post_meta( $post_id, $key, $stored );
		}
	}

	private function import_acf_meta( int $post_id, array $item ): void {
		if ( ! WPRestI_Settings::get( 'import_acf_meta' ) || ! function_exists( 'update_field' ) ) {
			return;
		}

		$acf = $item['acf'] ?? [];
		if ( ! is_array( $acf ) || empty( $acf ) ) {
			return;
		}

		foreach ( $acf as $field_key => $value ) {
			$field_key = sanitize_key( $field_key );
			if ( '' === $field_key ) {
				continue;
			}
			update_field( $field_key, $value, $post_id );
		}
	}

	private function should_import_meta_key( string $key ): bool {
		if ( '_' !== $key[0] ) {
			return true;
		}

		if ( WPRestI_Settings::get( 'import_private_meta' ) ) {
			return true;
		}

		$allowlist = WPRestI_Settings::meta_allowlist();
		foreach ( $allowlist as $pattern ) {
			if ( $pattern === $key ) {
				return true;
			}
			if ( '*' === substr( $pattern, -1 ) && 0 === strpos( $key, rtrim( $pattern, '*' ) ) ) {
				return true;
			}
		}

		return (bool) apply_filters( 'wpresti_import_meta_key', false, $key );
	}

	/**
	 * @param mixed $value
	 * @return mixed|null
	 */
	private function normalize_meta_value( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			if ( WPRestI_Settings::get( 'import_serialized_meta' ) ) {
				return $value;
			}
			return null;
		}

		if ( is_string( $value ) && is_serialized( $value ) ) {
			if ( WPRestI_Settings::get( 'import_serialized_meta' ) ) {
				return maybe_unserialize( $value );
			}
			return null;
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		return wp_kses_post( (string) $value );
	}
}
