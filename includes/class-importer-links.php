<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rewrites internal links from the source site to local URLs.
 */
class WPRestI_Importer_Links {

	private string $source_site_url = '';

	public function set_source_url( string $url ): void {
		$this->source_site_url = trailingslashit( esc_url_raw( $url ) );
	}

	public function rewrite( string $content ): string {
		if ( ! $this->source_site_url || ! $content ) {
			return $content;
		}

		$source      = rtrim( $this->source_site_url, '/' );
		$destination = rtrim( get_site_url(), '/' );

		if ( $source === $destination ) {
			return $content;
		}

		$content = $this->rewrite_by_domain_swap( $content, $source, $destination );
		$content = $this->rewrite_by_source_mapping( $content, $source, $destination );

		return $content;
	}

	private function rewrite_by_domain_swap( string $content, string $source, string $destination ): string {
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

		foreach ( $host_variants as $host_variant ) {
			foreach ( [ 'https', 'http' ] as $scheme ) {
				$base    = $scheme . '://' . $host_variant;
				$content = str_replace( $base . '/', $destination . '/', $content );
				$content = str_replace( $base . '"', $destination . '"', $content );
				$content = str_replace( $base . "'", $destination . "'", $content );
			}
		}

		return $content;
	}

	private function rewrite_by_source_mapping( string $content, string $source, string $destination ): string {
		if ( ! preg_match_all( '#(?:https?://[^/"\']+)?(/[^"\'\s<>]+)#', $content, $matches ) ) {
			return $content;
		}

		$paths = array_unique( $matches[1] );
		foreach ( $paths as $path ) {
			$path = untrailingslashit( $path );
			if ( strlen( $path ) < 2 ) {
				continue;
			}

			$local_url = $this->resolve_local_url_for_path( $path, $source );
			if ( ! $local_url ) {
				continue;
			}

			$source_url = $source . $path;
			$content    = str_replace( $source_url, $local_url, $content );
			$content    = str_replace( $path . '"', wp_make_link_relative( $local_url ) . '"', $content );
			$content    = str_replace( $path . "'", wp_make_link_relative( $local_url ) . "'", $content );
		}

		return $content;
	}

	private function resolve_local_url_for_path( string $path, string $source ): string {
		global $wpdb;

		$source_url = untrailingslashit( $source ) . $path;
		$post_id    = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wpresti_source_url' AND meta_value = %s LIMIT 1",
				$source_url
			)
		);

		if ( $post_id > 0 ) {
			$permalink = get_permalink( $post_id );
			return $permalink ? $permalink : '';
		}

		$slug = sanitize_title( basename( $path ) );
		if ( '' === $slug ) {
			return '';
		}

		$posts = get_posts(
			[
				'name'           => $slug,
				'post_type'      => 'any',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_wpresti_source_id',
			]
		);

		if ( ! empty( $posts ) ) {
			$permalink = get_permalink( (int) $posts[0] );
			return $permalink ? $permalink : '';
		}

		return '';
	}
}
