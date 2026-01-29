<?php
/**
 * Content Sampler
 *
 * Provides stratified sampling strategies for content selection.
 *
 * @package DGWTaxonomyAudit\Core
 */

declare(strict_types=1);

namespace DGWTaxonomyAudit\Core;

/**
 * Samples posts using various strategies for representative content selection.
 */
class ContentSampler {

	/**
	 * Get stratified sample of post IDs across date ranges and categories.
	 *
	 * @param string        $post_type  Post type to sample.
	 * @param int           $limit      Total posts to return.
	 * @param array<string> $taxonomies Taxonomies to stratify by.
	 *
	 * @return array<int> Post IDs.
	 */
	public function getStratifiedSample( string $post_type, int $limit, array $taxonomies = [ 'category' ] ): array {
		// Get date-based strata.
		$date_strata = $this->getDateStrata( $post_type );

		// Get taxonomy-based strata.
		$taxonomy_strata = $this->getTaxonomyStrata( $post_type, $taxonomies );

		// Combine and balance samples.
		$sampled_ids = $this->balancedSample( $date_strata, $taxonomy_strata, $limit );

		return $sampled_ids;
	}

	/**
	 * Get posts grouped by date ranges (quarters).
	 *
	 * @param string $post_type Post type.
	 *
	 * @return array<string, array<int>> Post IDs grouped by quarter.
	 */
	private function getDateStrata( string $post_type ): array {
		global $wpdb;

		// Get date range of published posts.
		$date_range = $wpdb->get_row( $wpdb->prepare(
			"SELECT MIN(post_date) as min_date, MAX(post_date) as max_date
			FROM {$wpdb->posts}
			WHERE post_type = %s AND post_status = 'publish'",
			$post_type
		) );

		if ( ! $date_range || ! $date_range->min_date ) {
			return [];
		}

		$strata = [];

		// Group by quarter.
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, CONCAT(YEAR(post_date), '-Q', QUARTER(post_date)) as period
			FROM {$wpdb->posts}
			WHERE post_type = %s AND post_status = 'publish'
			ORDER BY post_date DESC",
			$post_type
		) );

		foreach ( $results as $row ) {
			if ( ! isset( $strata[ $row->period ] ) ) {
				$strata[ $row->period ] = [];
			}
			$strata[ $row->period ][] = (int) $row->ID;
		}

		return $strata;
	}

	/**
	 * Get posts grouped by taxonomy terms.
	 *
	 * @param string        $post_type  Post type.
	 * @param array<string> $taxonomies Taxonomies to group by.
	 *
	 * @return array<string, array<int>> Post IDs grouped by term.
	 */
	private function getTaxonomyStrata( string $post_type, array $taxonomies ): array {
		global $wpdb;

		$strata = [];

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$results = $wpdb->get_results( $wpdb->prepare(
				"SELECT p.ID, t.slug as term_slug
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
				INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
				WHERE p.post_type = %s
				AND p.post_status = 'publish'
				AND tt.taxonomy = %s",
				$post_type,
				$taxonomy
			) );

			foreach ( $results as $row ) {
				$key = $taxonomy . ':' . $row->term_slug;
				if ( ! isset( $strata[ $key ] ) ) {
					$strata[ $key ] = [];
				}
				$strata[ $key ][] = (int) $row->ID;
			}
		}

		// Also include uncategorized posts.
		$uncategorized = $this->getUncategorizedPosts( $post_type, $taxonomies );
		if ( ! empty( $uncategorized ) ) {
			$strata['uncategorized'] = $uncategorized;
		}

		return $strata;
	}

	/**
	 * Get posts without any terms in specified taxonomies.
	 *
	 * @param string        $post_type  Post type.
	 * @param array<string> $taxonomies Taxonomies to check.
	 *
	 * @return array<int> Post IDs.
	 */
	private function getUncategorizedPosts( string $post_type, array $taxonomies ): array {
		global $wpdb;

		if ( empty( $taxonomies ) ) {
			return [];
		}

		$taxonomy_placeholders = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$query = $wpdb->prepare(
			"SELECT p.ID
			FROM {$wpdb->posts} p
			WHERE p.post_type = %s
			AND p.post_status = 'publish'
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				WHERE tr.object_id = p.ID
				AND tt.taxonomy IN ({$taxonomy_placeholders})
			)",
			array_merge( [ $post_type ], $taxonomies )
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', $wpdb->get_col( $query ) );
	}

	/**
	 * Create balanced sample from multiple strata.
	 *
	 * @param array<string, array<int>> $date_strata     Date-based strata.
	 * @param array<string, array<int>> $taxonomy_strata Taxonomy-based strata.
	 * @param int                       $limit           Total posts to return.
	 *
	 * @return array<int> Sampled post IDs.
	 */
	private function balancedSample( array $date_strata, array $taxonomy_strata, int $limit ): array {
		$selected = [];

		// Allocate 50% to date-based sampling, 50% to taxonomy-based.
		$date_allocation = (int) ceil( $limit * 0.5 );
		$taxonomy_allocation = $limit - $date_allocation;

		// Sample from date strata.
		$date_sample = $this->sampleFromStrata( $date_strata, $date_allocation );
		$selected = array_merge( $selected, $date_sample );

		// Sample from taxonomy strata (excluding already selected).
		$taxonomy_sample = $this->sampleFromStrata( $taxonomy_strata, $taxonomy_allocation, $selected );
		$selected = array_merge( $selected, $taxonomy_sample );

		// Remove duplicates and limit.
		$selected = array_unique( $selected );

		// If we don't have enough, fill from any remaining posts.
		if ( count( $selected ) < $limit ) {
			$all_posts = array_unique( array_merge(
				...array_values( $date_strata ),
				...array_values( $taxonomy_strata )
			) );
			$remaining = array_diff( $all_posts, $selected );
			shuffle( $remaining );
			$selected = array_merge( $selected, array_slice( $remaining, 0, $limit - count( $selected ) ) );
		}

		return array_slice( $selected, 0, $limit );
	}

	/**
	 * Sample evenly from multiple strata.
	 *
	 * @param array<string, array<int>> $strata  Strata to sample from.
	 * @param int                       $limit   Total posts to return.
	 * @param array<int>                $exclude Post IDs to exclude.
	 *
	 * @return array<int> Sampled post IDs.
	 */
	private function sampleFromStrata( array $strata, int $limit, array $exclude = [] ): array {
		if ( empty( $strata ) ) {
			return [];
		}

		$selected = [];
		$strata_count = count( $strata );
		$per_stratum = max( 1, (int) ceil( $limit / $strata_count ) );

		foreach ( $strata as $stratum_posts ) {
			// Remove excluded posts.
			$available = array_diff( $stratum_posts, $exclude, $selected );

			if ( empty( $available ) ) {
				continue;
			}

			// Shuffle and take sample.
			$available = array_values( $available );
			shuffle( $available );
			$sample = array_slice( $available, 0, $per_stratum );
			$selected = array_merge( $selected, $sample );
		}

		// Shuffle final selection to avoid ordering bias.
		shuffle( $selected );

		return array_slice( $selected, 0, $limit );
	}

	/**
	 * Get sampling statistics for reporting.
	 *
	 * @param string        $post_type  Post type.
	 * @param array<string> $taxonomies Taxonomies.
	 *
	 * @return array{date_periods: int, taxonomy_terms: int, total_posts: int, uncategorized: int}
	 */
	public function getSamplingStats( string $post_type, array $taxonomies = [ 'category' ] ): array {
		$date_strata = $this->getDateStrata( $post_type );
		$taxonomy_strata = $this->getTaxonomyStrata( $post_type, $taxonomies );

		$all_posts = array_unique( array_merge(
			...array_values( $date_strata ),
			...( ! empty( $taxonomy_strata ) ? array_values( $taxonomy_strata ) : [ [] ] )
		) );

		$uncategorized = $taxonomy_strata['uncategorized'] ?? [];

		return [
			'date_periods'   => count( $date_strata ),
			'taxonomy_terms' => count( $taxonomy_strata ) - ( isset( $taxonomy_strata['uncategorized'] ) ? 1 : 0 ),
			'total_posts'    => count( $all_posts ),
			'uncategorized'  => count( $uncategorized ),
		];
	}
}
