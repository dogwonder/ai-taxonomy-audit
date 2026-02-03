<?php
/**
 * CSV Handler
 *
 * @package DGWTaxonomyAudit\Output
 */

declare(strict_types=1);

namespace DGWTaxonomyAudit\Output;

/**
 * Handles CSV export and import of classification suggestions.
 */
class CSVHandler {

	/**
	 * CSV headers.
	 *
	 * @var array<string>
	 */
	private const HEADERS = [
		'post_id',
		'post_title',
		'post_url',
		'taxonomy',
		'existing_terms',
		'suggested_term',
		'confidence',
		'reason',
		'status',
		'in_vocabulary',
		'approved',
	];

	/**
	 * Status constants for comparison.
	 */
	private const STATUS_CONFIRM = 'CONFIRM';  // Term already applied and LLM agrees.
	private const STATUS_ADD     = 'ADD';      // Term not applied, LLM suggests adding.
	private const STATUS_NEW     = 'NEW';      // Term doesn't exist in vocabulary (audit mode).

	/**
	 * Export classification results to CSV.
	 *
	 * @param array<array{post_id: int, post_title: string, classifications: array}> $results Classification results.
	 * @param string|null                                                            $file    Output file path.
	 *
	 * @return string File path or CSV content.
	 */
	public function export( array $results, ?string $file = null ): string {
		$rows = $this->flattenResults( $results );

		if ( $file ) {
			$this->writeToFile( $file, $rows );
			return $file;
		}

		return $this->toString( $rows );
	}

	/**
	 * Flatten classification results to CSV rows.
	 *
	 * Includes existing terms for comparison and calculates status:
	 * - CONFIRM: Term already applied and LLM agrees
	 * - ADD: Term not applied, LLM suggests adding from existing vocabulary
	 * - NEW: Term doesn't exist in vocabulary (audit mode suggestion)
	 *
	 * @param array<array{post_id: int, post_title: string, classifications: array, existing_terms?: array}> $results Results.
	 *
	 * @return array<array<string>>
	 */
	private function flattenResults( array $results ): array {
		$rows = [];

		foreach ( $results as $result ) {
			if ( empty( $result['classifications'] ) ) {
				continue;
			}

			// Get existing terms for this post (keyed by taxonomy).
			$existing_terms = $result['existing_terms'] ?? [];

			foreach ( $result['classifications'] as $taxonomy => $terms ) {
				// Get existing terms for this specific taxonomy.
				$taxonomy_existing = $existing_terms[ $taxonomy ] ?? [];
				$existing_display  = ! empty( $taxonomy_existing ) ? implode( ', ', $taxonomy_existing ) : '';

				foreach ( $terms as $term ) {
					$term_slug     = $term['term'];
					$in_vocabulary = $term['in_vocabulary'] ?? null;

					// Calculate status based on comparison.
					$status = $this->calculateStatus( $term_slug, $taxonomy_existing, $in_vocabulary );

					$rows[] = [
						'post_id'        => (string) $result['post_id'],
						'post_title'     => $result['post_title'],
						'post_url'       => $result['post_url'] ?? '',
						'taxonomy'       => $taxonomy,
						'existing_terms' => $existing_display,
						'suggested_term' => $term_slug,
						'confidence'     => (string) round( $term['confidence'], 2 ),
						'reason'         => $term['reason'] ?? '',
						'status'         => $status,
						'in_vocabulary'  => null === $in_vocabulary ? '' : ( $in_vocabulary ? 'TRUE' : 'FALSE' ),
						'approved'       => '',
					];
				}
			}
		}

		return $rows;
	}

	/**
	 * Calculate the status for a suggested term.
	 *
	 * @param string      $term_slug         The suggested term slug.
	 * @param array       $existing_terms    Existing terms for this taxonomy on this post.
	 * @param bool|null   $in_vocabulary     Whether term exists in vocabulary (null = unknown/benchmark mode).
	 *
	 * @return string Status: CONFIRM, ADD, or NEW.
	 */
	private function calculateStatus( string $term_slug, array $existing_terms, ?bool $in_vocabulary ): string {
		// If explicitly marked as not in vocabulary, it's a new term suggestion.
		if ( false === $in_vocabulary ) {
			return self::STATUS_NEW;
		}

		// Check if term is already applied to this post.
		if ( in_array( $term_slug, $existing_terms, true ) ) {
			return self::STATUS_CONFIRM;
		}

		// Term exists in vocabulary but not applied to post.
		return self::STATUS_ADD;
	}

	/**
	 * Write rows to CSV file.
	 *
	 * @param string              $file File path.
	 * @param array<array<string>> $rows Data rows.
	 *
	 * @return void
	 */
	private function writeToFile( string $file, array $rows ): void {
		$handle = fopen( $file, 'w' );

		if ( ! $handle ) {
			throw new \RuntimeException( "Cannot open file for writing: {$file}" );
		}

		// Write BOM for Excel compatibility.
		fwrite( $handle, "\xEF\xBB\xBF" );

		// Write headers.
		fputcsv( $handle, self::HEADERS );

		// Write data rows.
		foreach ( $rows as $row ) {
			fputcsv( $handle, array_values( $row ) );
		}

		fclose( $handle );
	}

	/**
	 * Convert rows to CSV string.
	 *
	 * @param array<array<string>> $rows Data rows.
	 *
	 * @return string CSV content.
	 */
	private function toString( array $rows ): string {
		$output = fopen( 'php://memory', 'r+' );

		fputcsv( $output, self::HEADERS );

		foreach ( $rows as $row ) {
			fputcsv( $output, array_values( $row ) );
		}

		rewind( $output );
		$csv = stream_get_contents( $output );
		fclose( $output );

		return $csv;
	}

	/**
	 * Import suggestions from CSV file.
	 *
	 * @param string $file          File path.
	 * @param bool   $approved_only Only return approved rows.
	 *
	 * @return array<array{post_id: int, taxonomy: string, term: string, confidence: float, reason: string}>
	 */
	public function import( string $file, bool $approved_only = false ): array {
		if ( ! file_exists( $file ) ) {
			throw new \RuntimeException( "File not found: {$file}" );
		}

		$handle = fopen( $file, 'r' );

		if ( ! $handle ) {
			throw new \RuntimeException( "Cannot open file: {$file}" );
		}

		// Skip BOM if present.
		$bom = fread( $handle, 3 );
		if ( $bom !== "\xEF\xBB\xBF" ) {
			rewind( $handle );
		}

		// Read headers.
		$headers = fgetcsv( $handle );

		if ( ! $headers ) {
			fclose( $handle );
			throw new \RuntimeException( 'Invalid CSV: no headers found' );
		}

		// Normalize headers.
		$headers = array_map( 'trim', $headers );
		$headers = array_map( 'strtolower', $headers );

		$suggestions = [];

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			$data = array_combine( $headers, $row );

			if ( ! $data ) {
				continue;
			}

			// Skip unapproved if filtering.
			if ( $approved_only ) {
				$approved = strtolower( trim( $data['approved'] ?? '' ) );
				if ( ! in_array( $approved, [ 'true', 'yes', '1', 'x' ], true ) ) {
					continue;
				}
			}

			// Parse in_vocabulary field.
			$in_vocab_raw = strtolower( trim( $data['in_vocabulary'] ?? '' ) );
			$in_vocabulary = in_array( $in_vocab_raw, [ 'true', 'yes', '1' ], true ) ? true : ( in_array( $in_vocab_raw, [ 'false', 'no', '0' ], true ) ? false : null );

			$suggestions[] = [
				'post_id'        => (int) $data['post_id'],
				'post_title'     => $data['post_title'] ?? '',
				'taxonomy'       => $data['taxonomy'],
				'existing_terms' => $data['existing_terms'] ?? '',
				'term'           => $data['suggested_term'],
				'confidence'     => (float) $data['confidence'],
				'reason'         => $data['reason'] ?? '',
				'status'         => $data['status'] ?? '',
				'in_vocabulary'  => $in_vocabulary,
			];
		}

		fclose( $handle );

		return $suggestions;
	}

	/**
	 * Group imported suggestions by post ID.
	 *
	 * @param array<array{post_id: int, taxonomy: string, term: string}> $suggestions Flat suggestions.
	 *
	 * @return array<int, array{post_id: int, post_title: string, taxonomies: array<string, array<string>>>>
	 */
	public function groupByPost( array $suggestions ): array {
		$grouped = [];

		foreach ( $suggestions as $suggestion ) {
			$post_id = $suggestion['post_id'];

			if ( ! isset( $grouped[ $post_id ] ) ) {
				$grouped[ $post_id ] = [
					'post_id'    => $post_id,
					'post_title' => $suggestion['post_title'],
					'taxonomies' => [],
				];
			}

			$taxonomy = $suggestion['taxonomy'];

			if ( ! isset( $grouped[ $post_id ]['taxonomies'][ $taxonomy ] ) ) {
				$grouped[ $post_id ]['taxonomies'][ $taxonomy ] = [];
			}

			$grouped[ $post_id ]['taxonomies'][ $taxonomy ][] = $suggestion['term'];
		}

		return $grouped;
	}

	/**
	 * Validate CSV structure.
	 *
	 * @param string $file File path.
	 *
	 * @return array{valid: bool, errors: array<string>, row_count: int}
	 */
	public function validate( string $file ): array {
		$errors = [];
		$row_count = 0;

		if ( ! file_exists( $file ) ) {
			return [
				'valid'     => false,
				'errors'    => [ 'File not found' ],
				'row_count' => 0,
			];
		}

		$handle = fopen( $file, 'r' );

		if ( ! $handle ) {
			return [
				'valid'     => false,
				'errors'    => [ 'Cannot open file' ],
				'row_count' => 0,
			];
		}

		// Skip BOM.
		$bom = fread( $handle, 3 );
		if ( $bom !== "\xEF\xBB\xBF" ) {
			rewind( $handle );
		}

		$headers = fgetcsv( $handle );

		if ( ! $headers ) {
			fclose( $handle );
			return [
				'valid'     => false,
				'errors'    => [ 'No headers found' ],
				'row_count' => 0,
			];
		}

		$headers = array_map( fn( $h ) => strtolower( trim( $h ) ), $headers );

		// Check required columns.
		$required = [ 'post_id', 'taxonomy', 'suggested_term' ];
		$missing = array_diff( $required, $headers );

		if ( ! empty( $missing ) ) {
			$errors[] = 'Missing required columns: ' . implode( ', ', $missing );
		}

		// Count and validate rows.
		$line_num = 1;
		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			$line_num++;

			if ( count( $row ) !== count( $headers ) ) {
				$errors[] = "Row {$line_num}: column count mismatch";
				continue;
			}

			$data = array_combine( $headers, $row );

			if ( empty( $data['post_id'] ) || ! is_numeric( $data['post_id'] ) ) {
				$errors[] = "Row {$line_num}: invalid post_id";
			}

			if ( empty( $data['taxonomy'] ) ) {
				$errors[] = "Row {$line_num}: missing taxonomy";
			}

			if ( empty( $data['suggested_term'] ) ) {
				$errors[] = "Row {$line_num}: missing suggested_term";
			}

			$row_count++;
		}

		fclose( $handle );

		return [
			'valid'     => empty( $errors ),
			'errors'    => $errors,
			'row_count' => $row_count,
		];
	}

	/**
	 * Generate output filename.
	 *
	 * @param string $prefix File prefix.
	 *
	 * @return string Filename.
	 */
	public function generateFilename( string $prefix = 'suggestions' ): string {
		return sprintf( '%s-%s.csv', $prefix, gmdate( 'Y-m-d-His' ) );
	}
}
