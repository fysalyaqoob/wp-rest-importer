<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Media sideloading: images, files, srcset, CSS backgrounds, Gutenberg block URLs.
 */
class WPRestI_Importer_Media {

	/** @var callable|null */
	private $log_failure;

	private string $source_domain = '';

	/** Maps local attachment URL → local attachment ID; rebuilt per item. */
	public array $attachment_url_to_id = [];

	public function __construct() {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	public function set_source_domain( string $domain ): void {
		$this->source_domain = $domain;
	}

	/**
	 * @param callable $callback function( string $url, string $error ): void
	 */
	public function set_failure_logger( callable $callback ): void {
		$this->log_failure = $callback;
	}

	public function reset_url_map(): void {
		$this->attachment_url_to_id = [];
	}

	/**
	 * Discover and sideload all media URLs in content, rewriting references.
	 */
	public function rewrite_and_sideload( string $content, int $post_id ): string {
		if ( ! $this->source_domain || ! $content ) {
			return $content;
		}

		$urls = $this->collect_media_urls( $content );

		foreach ( $urls as $url ) {
			if ( ! $this->should_sideload_url( $url ) ) {
				continue;
			}

			$new_id = $this->sideload( $url, $post_id );
			if ( is_wp_error( $new_id ) ) {
				$this->log_media_failure( $url, $new_id->get_error_message() );
				continue;
			}

			if ( $new_id > 0 ) {
				$new_url = wp_get_attachment_url( $new_id );
				if ( $new_url ) {
					$content = str_replace( $url, $new_url, $content );
				}
			}
		}

		return $content;
	}

	/**
	 * @return string[]
	 */
	public function collect_media_urls( string $content ): array {
		$urls = [];

		if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $m ) ) {
			$urls = array_merge( $urls, $m[1] );
		}

		if ( preg_match_all( '/\bsrcset=["\']([^"\']+)["\']/i', $content, $m ) ) {
			foreach ( $m[1] as $srcset ) {
				foreach ( preg_split( '/\s*,\s*/', $srcset ) as $part ) {
					$part = trim( preg_replace( '/\s+\d+[wx]$/i', '', trim( $part ) ) );
					if ( $part ) {
						$urls[] = $part;
					}
				}
			}
		}

		if ( preg_match_all( '/url\s*\(\s*["\']?([^"\')\s]+)["\']?\s*\)/i', $content, $m ) ) {
			$urls = array_merge( $urls, $m[1] );
		}

		if ( preg_match_all( '/"(?:url|src|href)"\s*:\s*"([^"]+)"/i', $content, $m ) ) {
			$urls = array_merge( $urls, $m[1] );
		}

		if ( WPRestI_Settings::get( 'import_media_files' ) ) {
			if ( preg_match_all( '#href=["\']([^"\']+\.(?:pdf|doc|docx|xls|xlsx|zip|mp3|mp4|webm|ogg|wav))["\']#i', $content, $m ) ) {
				$urls = array_merge( $urls, $m[1] );
			}
		}

		return array_values( array_unique( array_filter( $urls ) ) );
	}

	public function import_featured_image( int $post_id, array $item ): void {
		$featured_media = $item['_embedded']['wp:featuredmedia'][0] ?? null;
		if ( ! $featured_media ) {
			return;
		}

		$image_url = esc_url_raw( $featured_media['source_url'] ?? '' );
		if ( ! $image_url ) {
			return;
		}

		$attachment_id = $this->sideload( $image_url, $post_id );
		if ( is_wp_error( $attachment_id ) ) {
			$this->log_media_failure( $image_url, $attachment_id->get_error_message() );
			return;
		}

		if ( $attachment_id > 0 ) {
			set_post_thumbnail( $post_id, $attachment_id );

			$alt = sanitize_text_field( $featured_media['alt_text'] ?? '' );
			if ( $alt ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
			}

			$caption = wp_kses_post( $featured_media['caption']['rendered'] ?? '' );
			if ( $caption ) {
				wp_update_post(
					[
						'ID'           => $attachment_id,
						'post_excerpt' => $caption,
					]
				);
			}

			$title = sanitize_text_field( $featured_media['title']['rendered'] ?? '' );
			if ( $title ) {
				wp_update_post(
					[
						'ID'         => $attachment_id,
						'post_title' => $title,
					]
				);
			}
		}
	}

	public function import_og_image( int $post_id, string $og_image_url ): void {
		$og_image_url = esc_url_raw( $og_image_url );
		if ( ! $og_image_url ) {
			return;
		}

		$attachment_id = $this->sideload( $og_image_url, $post_id );
		if ( is_wp_error( $attachment_id ) ) {
			$this->log_media_failure( $og_image_url, $attachment_id->get_error_message() );
			return;
		}

		if ( $attachment_id > 0 ) {
			update_post_meta( $post_id, '_yoast_wpseo_opengraph-image', wp_get_attachment_url( $attachment_id ) );
			update_post_meta( $post_id, '_yoast_wpseo_opengraph-image-id', $attachment_id );
			update_post_meta( $post_id, 'rank_math_facebook_image', wp_get_attachment_url( $attachment_id ) );
			update_post_meta( $post_id, 'rank_math_facebook_image_id', $attachment_id );
		}
	}

	/**
	 * Sideload a URL and return local attachment id + url.
	 *
	 * @return array{id:int,url:string}|null
	 */
	public function sideload_mapped( string $url, int $post_id ): ?array {
		$attachment_id = $this->sideload( $url, $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			$this->log_media_failure( $url, $attachment_id->get_error_message() );
			return null;
		}

		if ( $attachment_id < 1 ) {
			return null;
		}

		$local_url = wp_get_attachment_url( $attachment_id );

		return [
			'id'  => (int) $attachment_id,
			'url' => $local_url ? $local_url : '',
		];
	}

	/**
	 * @return int|WP_Error
	 */
	public function sideload( string $url, int $post_id ) {
		if ( ! $this->is_safe_url( $url ) ) {
			return new WP_Error( 'blocked_url', 'URL resolves to a private or reserved address.' );
		}

		$existing = get_posts(
			[
				'post_type'      => 'attachment',
				'meta_key'       => '_source_url',
				'meta_value'     => $url,
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

		$attachment_id = $this->sideload_with_date( $url, $post_id );

		if ( ! is_wp_error( $attachment_id ) ) {
			update_post_meta( (int) $attachment_id, '_source_url', $url );
			$local_url = wp_get_attachment_url( (int) $attachment_id );
			if ( $local_url ) {
				$this->attachment_url_to_id[ $local_url ] = (int) $attachment_id;
			}
		}

		return $attachment_id;
	}

	private function should_sideload_url( string $url ): bool {
		if ( ! $url || ! $this->source_domain ) {
			return false;
		}

		if ( false !== strpos( $url, $this->source_domain ) ) {
			return true;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( $host ) {
			if ( $host === $this->source_domain ) {
				return true;
			}
			$suffix = '.' . $this->source_domain;
			if ( strlen( $host ) > strlen( $suffix ) && substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( is_string( $path ) && false !== strpos( $path, '/uploads/' ) ) {
			return true;
		}

		return (bool) apply_filters( 'wpresti_should_sideload_url', false, $url, $this->source_domain );
	}

	/**
	 * @return int|WP_Error
	 */
	private function sideload_with_date( string $url, int $post_id ) {
		$ym     = $this->date_from_url( $url );
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

		$tmp = download_url( $url );

		if ( is_wp_error( $tmp ) ) {
			if ( $filter ) {
				remove_filter( 'upload_dir', $filter );
			}
			return $tmp;
		}

		$url_path   = (string) wp_parse_url( $url, PHP_URL_PATH );
		$file_array = [
			'name'     => wp_basename( '' !== $url_path ? $url_path : $url ),
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
	 * @return array{0:string,1:string}|null
	 */
	private function date_from_url( string $url ): ?array {
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

	private function log_media_failure( string $url, string $error ): void {
		if ( $this->log_failure ) {
			call_user_func( $this->log_failure, $url, $error );
		}
	}
}
