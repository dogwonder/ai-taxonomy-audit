<?php
/**
 * Gap Analyzer
 *
 * Analyzes taxonomy suggestions to identify gaps, unused terms, and coverage issues.
 *
 * @package DGWTaxonomyAudit\Core
 */

declare(strict_types=1);

namespace DGWTaxonomyAudit\Core;

/**
 * Analyzes classification suggestions against vocabulary to find gaps.
 */
class GapAnalyzer {

	/**
	 * Taxonomy extractor.
	 *
	 * @var TaxonomyExtractor
	 */
	private TaxonomyExtractor $extractor;

	/**
	 * Constructor.
	 *
	 * @param TaxonomyExtractor|null $extractor Taxonomy extractor instance.
	 */
	public function __construct( ?TaxonomyExtractor $extractor = null ) {
		$this->extractor = $extractor ?? new TaxonomyExtractor();
	}

	/**
	 * Run full gap analysis on suggestions.
	 *
	 * @param array<array{post_id: int, classifications: array}> $suggestions Classification results.
	 * @param array<string>                                       $taxonomies  Taxonomies to analyze.
	 *
	 * @return array{
	 *     suggested_new_terms: array,
	 *     unused_existing_terms: array,
	 *     ambiguous_terms: array,
	 *     uncovered_content: array,
	 *     summary: array
	 * }
	 */
	public function analyze( array $suggestions, array $taxonomies ): array {
		$vocabulary = $this->extractor->getVocabulary( $taxonomies );

		// Collect all suggested terms and their statistics.
		$term_stats = $this->collectTermStats( $suggestions, $taxonomies );

		// Find terms suggested but not in vocabulary.
		$suggested_new_terms = $this->findSuggestedNewTerms( $term_stats, $vocabulary );

		// Find existing terms never suggested.
		$unused_existing_terms = $this->findUnusedTerms( $term_stats, $vocabulary, $taxonomies );

		// Find terms with low average confidence.
		$ambiguous_terms = $this->findAmbiguousTerms( $term_stats );

		// Find content without good taxonomy coverage.
		$uncovered_content = $this->findUncoveredContent( $suggestions );

		return [
			'suggested_new_terms'   => $suggested_new_terms,
			'unused_existing_terms' => $unused_existing_terms,
			'ambiguous_terms'       => $ambiguous_terms,
			'uncovered_content'     => $uncovered_content,
			'summary'               => $this->generateSummary(
				$suggested_new_terms,
				$unused_existing_terms,
				$ambiguous_terms,
				$uncovered_content
			),
		];
	}

	/**
	 * Collect statistics about all suggested terms.
	 *
	 * @param array<array{post_id: int, classifications: array}> $suggestions Classification results.
	 * @param array<string>                                       $taxonomies  Taxonomies.
	 *
	 * @return array<string, array<string, array{count: int, total_confidence: float, post_ids: array<int>}>>
	 */
	private function collectTermStats( array $suggestions, array $taxonomies ): array {
		$stats = [];

		foreach ( $taxonomies as $taxonomy ) {
			$stats[ $taxonomy ] = [];
		}

		foreach ( $suggestions as $result ) {
			if ( empty( $result['classifications'] ) ) {
				continue;
			}

			foreach ( $result['classifications'] as $taxonomy => $terms ) {
				if ( ! isset( $stats[ $taxonomy ] ) ) {
					$stats[ $taxonomy ] = [];
				}

				foreach ( $terms as $term ) {
					$slug = $term['term'];
					$confidence = $term['confidence'] ?? 0.5;

					if ( ! isset( $stats[ $taxonomy ][ $slug ] ) ) {
						$stats[ $taxonomy ][ $slug ] = [
							'count'            => 0,
							'total_confidence' => 0.0,
							'post_ids'         => [],
						];
					}

					$stats[ $taxonomy ][ $slug ]['count']++;
					$stats[ $taxonomy ][ $slug ]['total_confidence'] += $confidence;
					$stats[ $taxonomy ][ $slug ]['post_ids'][] = $result['post_id'];
				}
			}
		}

		return $stats;
	}

	/**
	 * Find terms that were suggested but don't exist in vocabulary.
	 *
	 * @param array<string, array<string, array>> $term_stats Term statistics.
	 * @param array<string, array>                $vocabulary Existing vocabulary.
	 *
	 * @return array<array{term: string, taxonomy: string, suggested_count: int, avg_confidence: float, post_ids: array}>
	 */
	private function findSuggestedNewTerms( array $term_stats, array $vocabulary ): array {
		$new_terms = [];

		foreach ( $term_stats as $taxonomy => $terms ) {
			$existing_slugs = [];
			if ( isset( $vocabulary[ $taxonomy ] ) ) {
				$existing_slugs = array_column( $vocabulary[ $taxonomy ], 'slug' );
			}

			foreach ( $terms as $slug => $stats ) {
				if ( ! in_array( $slug, $existing_slugs, true ) ) {
					$avg_confidence = $stats['count'] > 0
						? $stats['total_confidence'] / $stats['count']
						: 0.0;

					$new_terms[] = [
						'term'            => $slug,
						'taxonomy'        => $taxonomy,
						'suggested_count' => $stats['count'],
						'avg_confidence'  => round( $avg_confidence, 2 ),
						'post_ids'        => $stats['post_ids'],
					];
				}
			}
		}

		// Sort by suggested count descending.
		usort( $new_terms, fn( $a, $b ) => $b['suggested_count'] <=> $a['suggested_count'] );

		return $new_terms;
	}

	/**
	 * Find existing terms that were never suggested.
	 *
	 * @param array<string, array<string, array>> $term_stats Term statistics from suggestions.
	 * @param array<string, array>                $vocabulary Existing vocabulary.
	 * @param array<string>                       $taxonomies Taxonomies to analyze.
	 *
	 * @return array<array{term: string, taxonomy: string, name: string, post_count: int}>
	 */
	private function findUnusedTerms( array $term_stats, array $vocabulary, array $taxonomies ): array {
		$unused = [];

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! isset( $vocabulary[ $taxonomy ] ) ) {
				continue;
			}

			$suggested_slugs = array_keys( $term_stats[ $taxonomy ] ?? [] );

			foreach ( $vocabulary[ $taxonomy ] as $term ) {
				if ( ! in_array( $term['slug'], $suggested_slugs, true ) ) {
					// Get actual post count for this term.
					$post_count = $this->getTermPostCount( $term['term_id'], $taxonomy );

					$unused[] = [
						'term'       => $term['slug'],
						'taxonomy'   => $taxonomy,
						'name'       => $term['name'],
						'post_count' => $post_count,
					];
				}
			}
		}

		// Sort by post count ascending (terms with 0 posts first).
		usort( $unused, fn( $a, $b ) => $a['post_count'] <=> $b['post_count'] );

		return $unused;
	}

	/**
	 * Find terms with low average confidence (ambiguous).
	 *
	 * @param array<string, array<string, array>> $term_stats  Term statistics.
	 * @param float                               $threshold   Confidence threshold (default 0.6).
	 * @param int                                 $min_samples Minimum samples required (default 2).
	 *
	 * @return array<array{term: string, taxonomy: string, avg_confidence: float, post_count: int}>
	 */
	private function findAmbiguousTerms( array $term_stats, float $threshold = 0.6, int $min_samples = 2 ): array {
		$ambiguous = [];

		foreach ( $term_stats as $taxonomy => $terms ) {
			foreach ( $terms as $slug => $stats ) {
				if ( $stats['count'] < $min_samples ) {
					continue;
				}

				$avg_confidence = $stats['total_confidence'] / $stats['count'];

				if ( $avg_confidence < $threshold ) {
					$ambiguous[] = [
						'term'           => $slug,
						'taxonomy'       => $taxonomy,
						'avg_confidence' => round( $avg_confidence, 2 ),
						'post_count'     => $stats['count'],
					];
				}
			}
		}

		// Sort by confidence ascending (most ambiguous first).
		usort( $ambiguous, fn( $a, $b ) => $a['avg_confidence'] <=> $b['avg_confidence'] );

		return $ambiguous;
	}

	/**
	 * Find content without adequate taxonomy coverage.
	 *
	 * @param array<array{post_id: int, classifications: array}> $suggestions Classification results.
	 * @param int                                                 $min_terms   Minimum terms expected (default 1).
	 *
	 * @return array<array{post_id: int, post_title: string, suggested_terms: array, coverage_gap: bool}>
	 */
	private function findUncoveredContent( array $suggestions, int $min_terms = 1 ): array {
		$uncovered = [];

		foreach ( $suggestions as $result ) {
			$total_terms = 0;
			$suggested_terms = [];

			if ( ! empty( $result['classifications'] ) ) {
				foreach ( $result['classifications'] as $taxonomy => $terms ) {
					$total_terms += count( $terms );
					foreach ( $terms as $term ) {
						$suggested_terms[] = $taxonomy . ':' . $term['term'];
					}
				}
			}

			if ( $total_terms < $min_terms ) {
				$uncovered[] = [
					'post_id'         => $result['post_id'],
					'post_title'      => $result['post_title'] ?? '',
					'post_url'        => $result['post_url'] ?? '',
					'suggested_terms' => $suggested_terms,
					'coverage_gap'    => true,
				];
			}
		}

		return $uncovered;
	}

	/**
	 * Get actual post count for a term.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy name.
	 *
	 * @return int Post count.
	 */
	private function getTermPostCount( int $term_id, string $taxonomy ): int {
		$term = get_term( $term_id, $taxonomy );

		if ( is_wp_error( $term ) || ! $term ) {
			return 0;
		}

		return (int) $term->count;
	}

	/**
	 * Get terms with zero posts in the database.
	 *
	 * @param array<string> $taxonomies Taxonomies to check.
	 *
	 * @return array<array{term: string, taxonomy: string, name: string, term_id: int}>
	 */
	public function getEmptyTerms( array $taxonomies ): array {
		$empty = [];

		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_terms( [
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			] );

			if ( is_wp_error( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				if ( 0 === (int) $term->count ) {
					$empty[] = [
						'term'     => $term->slug,
						'taxonomy' => $taxonomy,
						'name'     => $term->name,
						'term_id'  => $term->term_id,
					];
				}
			}
		}

		return $empty;
	}

	/**
	 * Find terms that exist but don't semantically match any analyzed content.
	 *
	 * These are terms in the vocabulary that:
	 * 1. Have posts assigned to them in the database
	 * 2. Were never suggested by the LLM for the analyzed content
	 *
	 * @param array<string, array<string, array>> $term_stats Term statistics from suggestions.
	 * @param array<string>                       $taxonomies Taxonomies to analyze.
	 * @param float                               $threshold  Minimum post percentage to flag (default 0.3).
	 *
	 * @return array<array{term: string, taxonomy: string, name: string, assigned_count: int, suggested_count: int}>
	 */
	public function findMismatchedTerms( array $term_stats, array $taxonomies, float $threshold = 0.3 ): array {
		$mismatched = [];

		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_terms( [
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			] );

			if ( is_wp_error( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$assigned_count = (int) $term->count;
				$suggested_count = $term_stats[ $taxonomy ][ $term->slug ]['count'] ?? 0;

				// Term has posts but was rarely/never suggested.
				if ( $assigned_count > 0 && $suggested_count === 0 ) {
					$mismatched[] = [
						'term'            => $term->slug,
						'taxonomy'        => $taxonomy,
						'name'            => $term->name,
						'assigned_count'  => $assigned_count,
						'suggested_count' => $suggested_count,
						'mismatch_ratio'  => 1.0,
					];
				}
			}
		}

		// Sort by assigned count descending.
		usort( $mismatched, fn( $a, $b ) => $b['assigned_count'] <=> $a['assigned_count'] );

		return $mismatched;
	}

	/**
	 * Generate analysis summary.
	 *
	 * @param array $suggested_new_terms   New term suggestions.
	 * @param array $unused_existing_terms Unused existing terms.
	 * @param array $ambiguous_terms       Ambiguous terms.
	 * @param array $uncovered_content     Uncovered content.
	 *
	 * @return array{
	 *     new_term_candidates: int,
	 *     unused_terms: int,
	 *     ambiguous_terms: int,
	 *     uncovered_posts: int,
	 *     health_score: float
	 * }
	 */
	private function generateSummary(
		array $suggested_new_terms,
		array $unused_existing_terms,
		array $ambiguous_terms,
		array $uncovered_content
	): array {
		$new_count = count( $suggested_new_terms );
		$unused_count = count( $unused_existing_terms );
		$ambiguous_count = count( $ambiguous_terms );
		$uncovered_count = count( $uncovered_content );

		// Calculate a simple health score (0-100).
		// Lower is worse: penalties for unused terms and uncovered content.
		$total_issues = $unused_count + $ambiguous_count + $uncovered_count;
		$health_score = max( 0, 100 - ( $total_issues * 5 ) );

		return [
			'new_term_candidates' => $new_count,
			'unused_terms'        => $unused_count,
			'ambiguous_terms'     => $ambiguous_count,
			'uncovered_posts'     => $uncovered_count,
			'health_score'        => (float) $health_score,
		];
	}
}
