<?php
/**
 * Content Exporter
 *
 * @package DGWTaxonomyAudit\Core
 */

declare(strict_types=1);

namespace DGWTaxonomyAudit\Core;

/**
 * Exports post content for LLM classification.
 */
class ContentExporter {

	/**
	 * Maximum content length for LLM.
	 *
	 * @var int
	 */
	private int $max_content_length;

	/**
	 * Constructor.
	 *
	 * @param int $max_content_length Maximum content length.
	 */
	public function __construct( int $max_content_length = 1500 ) {
		$this->max_content_length = $max_content_length;
	}

	/**
	 * Get posts for classification.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return array<array{post_id: int, title: string, excerpt: string, content: string, url: string, existing_terms: array}>
	 */
	public function getPosts( array $args = [] ): array {
		$defaults = [
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 10,
			'orderby'        => 'date',
			'order'          => 'DESC',
		];

		$query_args = array_merge( $defaults, $args );
		$posts = get_posts( $query_args );

		return array_map( [ $this, 'formatPost' ], $posts );
	}

	/**
	 * Get a single post for classification.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array{post_id: int, title: string, excerpt: string, content: string, url: string, existing_terms: array}|null
	 */
	public function getPost( int $post_id ): ?array {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		return $this->formatPost( $post );
	}

	/**
	 * Format a post for classification.
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return array{post_id: int, title: string, excerpt: string, content: string, url: string, existing_terms: array}
	 */
	private function formatPost( \WP_Post $post ): array {
		$content = $this->prepareContent( $post );

		return [
			'post_id'        => $post->ID,
			'title'          => $post->post_title,
			'excerpt'        => $this->getExcerpt( $post ),
			'content'        => $content,
			'url'            => get_permalink( $post->ID ),
			'existing_terms' => $this->getExistingTerms( $post ),
		];
	}

	/**
	 * Prepare content for LLM (strip HTML, truncate).
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return string Prepared content.
	 */
	private function prepareContent( \WP_Post $post ): string {
		$content = $post->post_content;

		// Parse blocks if using Gutenberg.
		if ( has_blocks( $content ) ) {
			$content = do_blocks( $content );
		}

		// Strip shortcodes.
		$content = strip_shortcodes( $content );

		// Strip HTML tags.
		$content = wp_strip_all_tags( $content );

		// Normalize whitespace.
		$content = preg_replace( '/\s+/', ' ', $content );
		$content = trim( $content );

		// Truncate to max length.
		if ( strlen( $content ) > $this->max_content_length ) {
			$content = substr( $content, 0, $this->max_content_length );
			// Try to end at a word boundary.
			$last_space = strrpos( $content, ' ' );
			if ( $last_space !== false && $last_space > $this->max_content_length - 100 ) {
				$content = substr( $content, 0, $last_space );
			}
			$content .= '...';
		}

		return $content;
	}

	/**
	 * Get post excerpt.
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return string Excerpt.
	 */
	private function getExcerpt( \WP_Post $post ): string {
		if ( ! empty( $post->post_excerpt ) ) {
			return wp_strip_all_tags( $post->post_excerpt );
		}

		// Generate excerpt from content.
		$content = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
		$excerpt = wp_trim_words( $content, 55, '...' );

		return $excerpt;
	}

	/**
	 * Get existing taxonomy terms for a post.
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return array<string, array<string>> Terms keyed by taxonomy.
	 */
	private function getExistingTerms( \WP_Post $post ): array {
		$taxonomies = get_object_taxonomies( $post->post_type, 'names' );
		$existing = [];

		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_post_terms( $post->ID, $taxonomy, [ 'fields' => 'slugs' ] );

			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				$existing[ $taxonomy ] = $terms;
			}
		}

		return $existing;
	}

	/**
	 * Build LLM prompt content for a post.
	 *
	 * @param array{post_id: int, title: string, excerpt: string, content: string, url: string, existing_terms: array} $post_data Post data.
	 *
	 * @return string Formatted content for LLM.
	 */
	public function buildPromptContent( array $post_data ): string {
		$parts = [];

		$parts[] = sprintf( 'TITLE: %s', $post_data['title'] );

		if ( ! empty( $post_data['excerpt'] ) ) {
			$parts[] = sprintf( 'EXCERPT: %s', $post_data['excerpt'] );
		}

		$parts[] = sprintf( 'CONTENT: %s', $post_data['content'] );

		if ( ! empty( $post_data['existing_terms'] ) ) {
			$parts[] = 'EXISTING TERMS:';
			foreach ( $post_data['existing_terms'] as $taxonomy => $terms ) {
				$parts[] = sprintf( '  %s: %s', $taxonomy, implode( ', ', $terms ) );
			}
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Get posts that have no terms in specified taxonomies.
	 *
	 * @param string        $post_type  Post type.
	 * @param array<string> $taxonomies Taxonomies to check.
	 * @param int           $limit      Maximum posts to return.
	 *
	 * @return array<array{post_id: int, title: string, excerpt: string, content: string, url: string, existing_terms: array}>
	 */
	public function getUnclassifiedPosts( string $post_type, array $taxonomies, int $limit = 10 ): array {
		global $wpdb;

		// Build query to find posts without terms in any of the taxonomies.
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
			)
			ORDER BY p.post_date DESC
			LIMIT %d",
			array_merge( [ $post_type ], $taxonomies, [ $limit ] )
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$post_ids = $wpdb->get_col( $query );

		if ( empty( $post_ids ) ) {
			return [];
		}

		return $this->getPosts( [
			'post__in'       => array_map( 'intval', $post_ids ),
			'posts_per_page' => $limit,
			'orderby'        => 'post__in',
		] );
	}

	/**
	 * Count posts by classification status.
	 *
	 * @param string        $post_type  Post type.
	 * @param array<string> $taxonomies Taxonomies to check.
	 *
	 * @return array{total: int, classified: int, unclassified: int}
	 */
	public function getClassificationStats( string $post_type, array $taxonomies ): array {
		global $wpdb;

		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
			$post_type
		) );

		$taxonomy_placeholders = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$classified = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID)
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
			INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			WHERE p.post_type = %s
			AND p.post_status = 'publish'
			AND tt.taxonomy IN ({$taxonomy_placeholders})",
			array_merge( [ $post_type ], $taxonomies )
		) );

		return [
			'total'        => $total,
			'classified'   => $classified,
			'unclassified' => $total - $classified,
		];
	}
}
