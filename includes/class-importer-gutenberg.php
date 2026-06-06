<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gutenberg block import via parse_blocks() / serialize_blocks().
 *
 * Remaps media URLs and attachment IDs in block attributes and inner HTML
 * so imported posts render correctly in the block editor.
 */
class WPRestI_Importer_Gutenberg {

	/**
	 * Process raw block markup: sideload media, rewrite links, remap block attrs.
	 */
	public function process(
		string $content,
		WPRestI_Importer_Media $media,
		WPRestI_Importer_Links $links,
		int $post_id
	): string {
		if ( ! $this->is_block_content( $content ) ) {
			return $content;
		}

		$this->ensure_block_functions();

		$blocks = parse_blocks( $content );
		if ( empty( $blocks ) ) {
			return $content;
		}

		$blocks = $this->remap_blocks( $blocks, $media, $links, $post_id );

		return serialize_blocks( $blocks );
	}

	public function is_block_content( string $content ): bool {
		return strpos( $content, '<!-- wp:' ) !== false;
	}

	private function ensure_block_functions(): void {
		if ( ! function_exists( 'parse_blocks' ) ) {
			require_once ABSPATH . WPINC . '/blocks.php';
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $blocks
	 * @return array<int,array<string,mixed>>
	 */
	private function remap_blocks(
		array $blocks,
		WPRestI_Importer_Media $media,
		WPRestI_Importer_Links $links,
		int $post_id
	): array {
		foreach ( $blocks as $index => $block ) {
			$blocks[ $index ] = $this->remap_block( $block, $media, $links, $post_id );
		}

		return $blocks;
	}

	/**
	 * @param array<string,mixed> $block
	 * @return array<string,mixed>
	 */
	private function remap_block(
		array $block,
		WPRestI_Importer_Media $media,
		WPRestI_Importer_Links $links,
		int $post_id
	): array {
		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$block['innerBlocks'] = $this->remap_blocks( $block['innerBlocks'], $media, $links, $post_id );
		}

		if ( ! empty( $block['blockName'] ) ) {
			$block['attrs'] = $this->remap_block_attrs(
				is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [],
				(string) $block['blockName'],
				$block,
				$media,
				$links,
				$post_id
			);
		}

		if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) && '' !== $block['innerHTML'] ) {
			$block['innerHTML'] = $this->rewrite_html_chunk( $block['innerHTML'], $media, $links, $post_id );
		}

		if ( ! empty( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
			foreach ( $block['innerContent'] as $i => $chunk ) {
				if ( is_string( $chunk ) && '' !== $chunk ) {
					$block['innerContent'][ $i ] = $this->rewrite_html_chunk( $chunk, $media, $links, $post_id );
				}
			}
		}

		return $block;
	}

	/**
	 * @param array<string,mixed> $attrs
	 * @param array<string,mixed> $block
	 * @return array<string,mixed>
	 */
	private function remap_block_attrs(
		array $attrs,
		string $block_name,
		array $block,
		WPRestI_Importer_Media $media,
		WPRestI_Importer_Links $links,
		int $post_id
	): array {
		$media_blocks = [
			'core/image',
			'core/cover',
			'core/gallery',
			'core/media-text',
			'core/file',
			'core/video',
			'core/audio',
		];
		$is_media_block = in_array( $block_name, $media_blocks, true );

		$url_keys = [ 'url', 'mediaUrl', 'src', 'href' ];
		foreach ( $url_keys as $key ) {
			if ( empty( $attrs[ $key ] ) || ! is_string( $attrs[ $key ] ) ) {
				continue;
			}

			if ( $is_media_block || $this->is_attachment_url( $attrs[ $key ] ) ) {
				$mapped = $media->sideload_mapped( $attrs[ $key ], $post_id );
				if ( $mapped ) {
					$attrs[ $key ] = $mapped['url'];
					$attrs['id']   = $mapped['id'];
					if ( 'mediaUrl' === $key ) {
						$attrs['mediaId'] = $mapped['id'];
					}
				}
			} else {
				$attrs[ $key ] = $links->rewrite( $attrs[ $key ] );
			}
		}

		if ( ! empty( $attrs['mediaId'] ) && empty( $attrs['mediaUrl'] ) ) {
			$url = $this->extract_media_url_from_block( $block );
			if ( $url ) {
				$mapped = $media->sideload_mapped( $url, $post_id );
				if ( $mapped ) {
					$attrs['mediaId']  = $mapped['id'];
					$attrs['mediaUrl'] = $mapped['url'];
				}
			}
		}

		if ( ! empty( $attrs['id'] ) && empty( $attrs['url'] ) && empty( $attrs['href'] ) && empty( $attrs['src'] ) ) {
			$url = $this->extract_media_url_from_block( $block );
			if ( $url ) {
				$mapped = $media->sideload_mapped( $url, $post_id );
				if ( $mapped ) {
					$attrs['id']  = $mapped['id'];
					$attrs['url'] = $mapped['url'];
				}
			}
		}

		if ( ! empty( $attrs['ids'] ) && is_array( $attrs['ids'] ) ) {
			$new_ids = $this->remap_id_list_from_urls( $block, $media, $post_id );
			if ( ! empty( $new_ids ) ) {
				$attrs['ids'] = $new_ids;
			}
		}

		if ( ! empty( $attrs['images'] ) && is_array( $attrs['images'] ) ) {
			$attrs['images'] = $this->remap_gallery_images( $attrs['images'], $media, $post_id );
		}

		return $attrs;
	}

	/**
	 * @param array<int,array<string,mixed>> $images
	 * @return array<int,array<string,mixed>>
	 */
	private function remap_gallery_images( array $images, WPRestI_Importer_Media $media, int $post_id ): array {
		$out = [];

		foreach ( $images as $image ) {
			if ( ! is_array( $image ) ) {
				continue;
			}

			if ( ! empty( $image['url'] ) && is_string( $image['url'] ) ) {
				$mapped = $media->sideload_mapped( $image['url'], $post_id );
				if ( $mapped ) {
					$image['id']  = $mapped['id'];
					$image['url'] = $mapped['url'];
				}
			}

			$out[] = $image;
		}

		return $out;
	}

	/**
	 * @return int[]
	 */
	private function remap_id_list_from_urls( array $block, WPRestI_Importer_Media $media, int $post_id ): array {
		$urls    = $this->extract_all_media_urls_from_block( $block );
		$new_ids = [];

		foreach ( $urls as $url ) {
			$mapped = $media->sideload_mapped( $url, $post_id );
			if ( $mapped ) {
				$new_ids[] = $mapped['id'];
			}
		}

		return array_values( array_unique( $new_ids ) );
	}

	private function rewrite_html_chunk(
		string $html,
		WPRestI_Importer_Media $media,
		WPRestI_Importer_Links $links,
		int $post_id
	): string {
		$html = $media->rewrite_and_sideload( $html, $post_id );
		return $links->rewrite( $html );
	}

	/**
	 * @param array<string,mixed> $block
	 */
	private function extract_media_url_from_block( array $block ): string {
		$urls = $this->extract_all_media_urls_from_block( $block );
		return $urls[0] ?? '';
	}

	/**
	 * @param array<string,mixed> $block
	 * @return string[]
	 */
	private function extract_all_media_urls_from_block( array $block ): array {
		$html = (string) ( $block['innerHTML'] ?? '' );
		if ( '' === $html && ! empty( $block['innerContent'] ) ) {
			foreach ( $block['innerContent'] as $chunk ) {
				if ( is_string( $chunk ) ) {
					$html .= $chunk;
				}
			}
		}

		if ( '' === $html ) {
			return [];
		}

		$urls = [];

		if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $m ) ) {
			$urls = array_merge( $urls, $m[1] );
		}

		if ( preg_match_all( '/<(?:video|audio|source)[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $m ) ) {
			$urls = array_merge( $urls, $m[1] );
		}

		if ( preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $html, $m ) ) {
			$urls = array_merge( $urls, $m[1] );
		}

		if ( preg_match_all( '/url\s*\(\s*["\']?([^"\')\s]+)["\']?\s*\)/i', $html, $m ) ) {
			$urls = array_merge( $urls, $m[1] );
		}

		return array_values( array_unique( array_filter( $urls ) ) );
	}

	private function is_attachment_url( string $url ): bool {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '' === $path ) {
			return false;
		}

		if ( false !== strpos( $path, '/uploads/' ) || false !== strpos( $path, '/wp-content/uploads/' ) ) {
			return true;
		}

		return (bool) preg_match( '/\.(jpe?g|png|gif|webp|avif|svg|ico|pdf|docx?|xlsx?|zip|mp3|m4a|mp4|webm|ogg|wav)$/i', $path );
	}
}
