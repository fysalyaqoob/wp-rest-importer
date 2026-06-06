<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Taxonomy term import with parent hierarchy support.
 */
class WPRestI_Importer_Terms {

	private const META_SOURCE_TERM_ID = '_wpresti_source_term_id';

	public function import_terms( int $post_id, array $item ): void {
		$embedded_terms = $item['_embedded']['wp:term'] ?? [];
		$all_terms      = [];

		foreach ( $embedded_terms as $term_group ) {
			if ( ! is_array( $term_group ) ) {
				continue;
			}
			foreach ( $term_group as $term_data ) {
				if ( is_array( $term_data ) ) {
					$all_terms[] = $term_data;
				}
			}
		}

		usort(
			$all_terms,
			static function ( $a, $b ) {
				return (int) ( $a['parent'] ?? 0 ) <=> (int) ( $b['parent'] ?? 0 );
			}
		);

		$by_taxonomy = [];
		foreach ( $all_terms as $term_data ) {
			$taxonomy = sanitize_key( $term_data['taxonomy'] ?? '' );
			if ( ! $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$term_id = $this->ensure_term( $term_data, $taxonomy );
			if ( $term_id > 0 ) {
				$by_taxonomy[ $taxonomy ][] = $term_id;
			}
		}

		foreach ( $by_taxonomy as $taxonomy => $term_ids ) {
			wp_set_object_terms( $post_id, array_unique( $term_ids ), $taxonomy );
		}
	}

	private function ensure_term( array $term_data, string $taxonomy ): int {
		$source_id   = (int) ( $term_data['id'] ?? 0 );
		$name        = sanitize_text_field( $term_data['name'] ?? '' );
		$slug        = sanitize_title( $term_data['slug'] ?? '' );
		$description = wp_kses_post( $term_data['description'] ?? '' );
		$parent_src  = (int) ( $term_data['parent'] ?? 0 );

		if ( ! $name ) {
			return 0;
		}

		if ( $source_id > 0 ) {
			$mapped_id = $this->find_term_by_source_id( $source_id, $taxonomy );
			if ( $mapped_id > 0 ) {
				return $mapped_id;
			}
		}

		$existing = get_term_by( 'slug', $slug, $taxonomy );
		if ( $existing ) {
			$term_id = (int) $existing->term_id;
		} else {
			$parent_id = 0;
			if ( $parent_src > 0 ) {
				$parent_id = $this->find_term_by_source_id( $parent_src, $taxonomy );
			}

			$new_term = wp_insert_term(
				$name,
				$taxonomy,
				[
					'slug'        => $slug,
					'description' => $description,
					'parent'      => $parent_id,
				]
			);

			if ( is_wp_error( $new_term ) ) {
				return 0;
			}

			$term_id = (int) $new_term['term_id'];
		}

		if ( $source_id > 0 ) {
			update_term_meta( $term_id, self::META_SOURCE_TERM_ID, $source_id );
		}

		if ( $description ) {
			wp_update_term(
				$term_id,
				$taxonomy,
				[ 'description' => $description ]
			);
		}

		return $term_id;
	}

	private function find_term_by_source_id( int $source_id, string $taxonomy ): int {
		global $wpdb;

		$term_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT tm.term_id FROM {$wpdb->termmeta} tm
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
				WHERE tm.meta_key = %s AND tm.meta_value = %d AND tt.taxonomy = %s
				LIMIT 1",
				self::META_SOURCE_TERM_ID,
				$source_id,
				$taxonomy
			)
		);

		return $term_id > 0 ? $term_id : 0;
	}
}
