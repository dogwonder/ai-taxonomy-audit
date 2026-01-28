<?php
/**
 * Taxonomy Extractor
 *
 * @package DGWTaxonomyAudit\Core
 */

declare(strict_types=1);

namespace DGWTaxonomyAudit\Core;

/**
 * Extracts taxonomy terms to build controlled vocabulary.
 */
class TaxonomyExtractor {

	/**
	 * Get vocabulary for specified taxonomies.
	 *
	 * @param array<string> $taxonomy_names Taxonomy names to extract.
	 *
	 * @return array<string, array<array{slug: string, name: string, description: string, term_id: int, parent: int}>>
	 */
	public function getVocabulary( array $taxonomy_names ): array {
		$vocabulary = [];

		foreach ( $taxonomy_names as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$terms = $this->getTermsForTaxonomy( $taxonomy );
			if ( ! empty( $terms ) ) {
				$vocabulary[ $taxonomy ] = $terms;
			}
		}

		return $vocabulary;
	}

	/**
	 * Get all terms for a single taxonomy.
	 *
	 * @param string $taxonomy Taxonomy name.
	 *
	 * @return array<array{slug: string, name: string, description: string, term_id: int, parent: int}>
	 */
	public function getTermsForTaxonomy( string $taxonomy ): array {
		$terms = get_terms( [
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		] );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return [];
		}

		return array_map( function ( $term ) {
			return [
				'term_id'     => $term->term_id,
				'slug'        => $term->slug,
				'name'        => $term->name,
				'description' => $term->description ?? '',
				'parent'      => $term->parent,
			];
		}, $terms );
	}

	/**
	 * Export vocabulary to JSON format.
	 *
	 * @param array<string> $taxonomy_names Taxonomy names.
	 *
	 * @return string JSON string.
	 */
	public function exportToJson( array $taxonomy_names ): string {
		$vocabulary = $this->getVocabulary( $taxonomy_names );

		return wp_json_encode( $vocabulary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Get vocabulary in SKOS-like format for better LLM understanding.
	 *
	 * @param array<string> $taxonomy_names Taxonomy names.
	 *
	 * @return array<string, array<array{prefLabel: string, altLabel: string, definition: string, broader: string|null}>>
	 */
	public function getSkosVocabulary( array $taxonomy_names ): array {
		$vocabulary = [];

		foreach ( $taxonomy_names as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$terms = get_terms( [
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			] );

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			$terms_by_id = [];
			foreach ( $terms as $term ) {
				$terms_by_id[ $term->term_id ] = $term;
			}

			$vocabulary[ $taxonomy ] = array_map( function ( $term ) use ( $terms_by_id ) {
				$broader = null;
				if ( $term->parent > 0 && isset( $terms_by_id[ $term->parent ] ) ) {
					$broader = $terms_by_id[ $term->parent ]->slug;
				}

				return [
					'prefLabel'  => $term->name,
					'altLabel'   => $term->slug,
					'definition' => $term->description ?? '',
					'broader'    => $broader,
				];
			}, $terms );
		}

		return $vocabulary;
	}

	/**
	 * Get flat list of term slugs for a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy name.
	 *
	 * @return array<string> List of slugs.
	 */
	public function getTermSlugs( string $taxonomy ): array {
		$terms = $this->getTermsForTaxonomy( $taxonomy );

		return array_column( $terms, 'slug' );
	}

	/**
	 * Get all public taxonomies attached to a post type.
	 *
	 * @param string $post_type Post type name.
	 *
	 * @return array<string> Taxonomy names.
	 */
	public function getTaxonomiesForPostType( string $post_type ): array {
		$taxonomies = get_object_taxonomies( $post_type, 'objects' );

		$public_taxonomies = array_filter( $taxonomies, function ( $tax ) {
			return $tax->public && ! $tax->_builtin || in_array( $tax->name, [ 'category', 'post_tag' ], true );
		} );

		return array_keys( $public_taxonomies );
	}

	/**
	 * Validate that all specified taxonomies exist.
	 *
	 * @param array<string> $taxonomy_names Taxonomy names to validate.
	 *
	 * @return array{valid: array<string>, invalid: array<string>}
	 */
	public function validateTaxonomies( array $taxonomy_names ): array {
		$valid = [];
		$invalid = [];

		foreach ( $taxonomy_names as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				$valid[] = $taxonomy;
			} else {
				$invalid[] = $taxonomy;
			}
		}

		return [
			'valid'   => $valid,
			'invalid' => $invalid,
		];
	}

	/**
	 * Get term by slug within a taxonomy.
	 *
	 * @param string $slug     Term slug.
	 * @param string $taxonomy Taxonomy name.
	 *
	 * @return \WP_Term|null Term object or null.
	 */
	public function getTermBySlug( string $slug, string $taxonomy ): ?\WP_Term {
		$term = get_term_by( 'slug', $slug, $taxonomy );

		if ( ! $term instanceof \WP_Term ) {
			return null;
		}

		return $term;
	}
}
