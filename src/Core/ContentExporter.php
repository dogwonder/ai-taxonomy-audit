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
 *
 * Supports both standard post_content and ACF field content,
 * configured via wp-to-file export profiles.
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
	 * Uses wp-to-file export profile configuration to determine content source:
	 * - If acf_fields.content is configured, builds content from those ACF fields
	 * - If exclude_content is true, skips post_content
	 * - Falls back to post_content for standard posts
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return string Prepared content.
	 */
	private function prepareContent( \WP_Post $post ): string {
		$post_type      = $post->post_type;
		$content_fields = ExportConfigLoader::getContentFields( $post_type );
		$exclude_content = ExportConfigLoader::shouldExcludeContent( $post_type );

		$content_parts = [];

		// If ACF content fields are configured, use them.
		if ( ! empty( $content_fields ) ) {
			foreach ( $content_fields as $field_key ) {
				$field_content = $this->getACFFieldContent( $post->ID, $field_key );
				if ( ! empty( $field_content ) ) {
					// Use a readable label for the field.
					$label           = $this->getFieldLabel( $field_key );
					$content_parts[] = sprintf( "[%s]\n%s", $label, $field_content );
				}
			}
		}

		// Include post_content if not excluded and either no ACF fields or ACF fields are empty.
		if ( ! $exclude_content && ( empty( $content_fields ) || empty( $content_parts ) ) ) {
			$post_content = $this->processPostContent( $post->post_content );
			if ( ! empty( $post_content ) ) {
				$content_parts[] = $post_content;
			}
		}

		// Combine all content.
		$content = implode( "\n\n", $content_parts );

		// Final cleanup and truncation.
		$content = $this->normalizeAndTruncate( $content );

		return $content;
	}

	/**
	 * Process standard post_content (blocks, shortcodes, HTML).
	 *
	 * @param string $content Raw post content.
	 *
	 * @return string Processed content.
	 */
	private function processPostContent( string $content ): string {
		// Parse blocks if using Gutenberg.
		if ( has_blocks( $content ) ) {
			$content = do_blocks( $content );
		}

		// Strip shortcodes.
		$content = strip_shortcodes( $content );

		// Strip HTML tags.
		$content = wp_strip_all_tags( $content );

		return trim( $content );
	}

	/**
	 * Get ACF field content for a post.
	 *
	 * Handles nested field keys like "clause_fields.clause_content".
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $field_key ACF field key (may be dot-notation).
	 *
	 * @return string Field content (HTML stripped).
	 */
	private function getACFFieldContent( int $post_id, string $field_key ): string {
		// ACF's get_field() handles nested group fields with dot notation.
		// But we may need to try both the full key and without group prefix.
		$value = null;

		// Try the field key as-is first (handles group.subfield format).
		if ( function_exists( 'get_field' ) ) {
			$value = get_field( $field_key, $post_id );

			// If not found, try extracting subfield from group.
			if ( null === $value && strpos( $field_key, '.' ) !== false ) {
				$parts      = explode( '.', $field_key );
				$group_name = $parts[0];
				$field_name = $parts[1] ?? '';

				$group = get_field( $group_name, $post_id );
				if ( is_array( $group ) && isset( $group[ $field_name ] ) ) {
					$value = $group[ $field_name ];
				}
			}
		}

		// Fallback to get_post_meta if ACF not available.
		if ( null === $value ) {
			// Try with underscore prefix (ACF stores with underscore).
			$meta_key = str_replace( '.', '_', $field_key );
			$value    = get_post_meta( $post_id, $meta_key, true );

			if ( empty( $value ) ) {
				$value = get_post_meta( $post_id, '_' . $meta_key, true );
			}
		}

		if ( empty( $value ) || ! is_string( $value ) ) {
			return '';
		}

		// Strip HTML and normalize.
		$content = wp_strip_all_tags( $value );
		return trim( $content );
	}

	/**
	 * Get a human-readable label for an ACF field key.
	 *
	 * @param string $field_key ACF field key.
	 *
	 * @return string Readable label.
	 */
	private function getFieldLabel( string $field_key ): string {
		// Remove group prefix if present.
		if ( strpos( $field_key, '.' ) !== false ) {
			$parts     = explode( '.', $field_key );
			$field_key = end( $parts );
		}

		// Convert snake_case/kebab-case to Title Case.
		$label = str_replace( [ '_', '-' ], ' ', $field_key );
		$label = ucwords( $label );

		// Clean up common prefixes.
		$label = preg_replace( '/^(Clause|Guide|Post)\s+/i', '', $label );

		return $label;
	}

	/**
	 * Normalize whitespace and truncate content.
	 *
	 * @param string $content Content to process.
	 *
	 * @return string Normalized and truncated content.
	 */
	private function normalizeAndTruncate( string $content ): string {
		// Normalize whitespace (but preserve paragraph breaks).
		$content = preg_replace( '/[ \t]+/', ' ', $content );
		$content = preg_replace( '/\n{3,}/', "\n\n", $content );
		$content = trim( $content );

		// Truncate to max length.
		if ( mb_strlen( $content ) > $this->max_content_length ) {
			$content = mb_substr( $content, 0, $this->max_content_length );
			// Try to end at a word boundary.
			$last_space = mb_strrpos( $content, ' ' );
			if ( $last_space !== false && $last_space > $this->max_content_length - 100 ) {
				$content = mb_substr( $content, 0, $last_space );
			}
			$content .= '...';
		}

		return $content;
	}

	/**
	 * Get post excerpt.
	 *
	 * Uses ACF summary/description fields when configured,
	 * falls back to post_excerpt or generated excerpt.
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return string Excerpt.
	 */
	private function getExcerpt( \WP_Post $post ): string {
		// Use manual excerpt if set.
		if ( ! empty( $post->post_excerpt ) ) {
			return wp_strip_all_tags( $post->post_excerpt );
		}

		// Try ACF summary field if content fields are configured.
		$content_fields = ExportConfigLoader::getContentFields( $post->post_type );
		if ( ! empty( $content_fields ) ) {
			// Look for summary/description fields first.
			$summary_patterns = [ 'summary', 'description', 'excerpt', 'intro' ];
			foreach ( $content_fields as $field_key ) {
				foreach ( $summary_patterns as $pattern ) {
					if ( stripos( $field_key, $pattern ) !== false ) {
						$summary = $this->getACFFieldContent( $post->ID, $field_key );
						if ( ! empty( $summary ) ) {
							return wp_trim_words( $summary, 55, '...' );
						}
					}
				}
			}

			// Fall back to first ACF content field.
			$first_field = $this->getACFFieldContent( $post->ID, $content_fields[0] );
			if ( ! empty( $first_field ) ) {
				return wp_trim_words( $first_field, 55, '...' );
			}
		}

		// Generate excerpt from post_content.
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
