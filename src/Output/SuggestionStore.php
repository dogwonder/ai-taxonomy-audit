<?php
/**
 * Suggestion Store
 *
 * @package DGWTaxonomyAudit\Output
 */

declare(strict_types=1);

namespace DGWTaxonomyAudit\Output;

/**
 * Stores and retrieves classification suggestions in JSON format.
 */
class SuggestionStore {

	/**
	 * Output directory.
	 *
	 * @var string
	 */
	private string $output_dir;

	/**
	 * Constructor.
	 *
	 * @param string|null $output_dir Output directory path.
	 */
	public function __construct( ?string $output_dir = null ) {
		$this->output_dir = $output_dir ?? \DGWTaxonomyAudit\get_output_dir();
	}

	/**
	 * Save classification results to JSON file.
	 *
	 * @param array<array{post_id: int, post_title: string, classifications: array}> $results   Results to save.
	 * @param array<string, mixed>                                                   $metadata  Additional metadata.
	 *
	 * @return string File path.
	 */
	public function save( array $results, array $metadata = [] ): string {
		$filename = $this->generateFilename();
		$filepath = $this->output_dir . $filename;

		$data = [
			'generated_at' => gmdate( 'c' ),
			'version'      => DGW_TAXONOMY_AUDIT_VERSION,
			'metadata'     => $metadata,
			'results'      => $results,
			'summary'      => $this->generateSummary( $results ),
		];

		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( false === file_put_contents( $filepath, $json ) ) {
			throw new \RuntimeException( "Failed to write file: {$filepath}" );
		}

		return $filepath;
	}

	/**
	 * Load suggestions from JSON file.
	 *
	 * @param string $filepath File path.
	 *
	 * @return array{generated_at: string, results: array, summary: array}
	 */
	public function load( string $filepath ): array {
		if ( ! file_exists( $filepath ) ) {
			throw new \RuntimeException( "File not found: {$filepath}" );
		}

		$content = file_get_contents( $filepath );

		if ( false === $content ) {
			throw new \RuntimeException( "Failed to read file: {$filepath}" );
		}

		$data = json_decode( $content, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new \RuntimeException( 'Invalid JSON: ' . json_last_error_msg() );
		}

		return $data;
	}

	/**
	 * List all saved suggestion files.
	 *
	 * @return array<array{filename: string, filepath: string, generated_at: string, post_count: int}>
	 */
	public function listFiles(): array {
		$files = glob( $this->output_dir . 'suggestions-*.json' );

		if ( ! $files ) {
			return [];
		}

		$result = [];

		foreach ( $files as $filepath ) {
			try {
				$data = $this->load( $filepath );
				$result[] = [
					'filename'     => basename( $filepath ),
					'filepath'     => $filepath,
					'generated_at' => $data['generated_at'] ?? 'unknown',
					'post_count'   => count( $data['results'] ?? [] ),
				];
			} catch ( \Exception $e ) {
				// Skip invalid files.
				continue;
			}
		}

		// Sort by date, newest first.
		usort( $result, fn( $a, $b ) => strcmp( $b['generated_at'], $a['generated_at'] ) );

		return $result;
	}

	/**
	 * Get the most recent suggestion file.
	 *
	 * @return string|null File path or null if none.
	 */
	public function getLatest(): ?string {
		$files = $this->listFiles();

		if ( empty( $files ) ) {
			return null;
		}

		return $files[0]['filepath'];
	}

	/**
	 * Delete a suggestion file.
	 *
	 * @param string $filepath File path.
	 *
	 * @return bool Success.
	 */
	public function delete( string $filepath ): bool {
		if ( ! file_exists( $filepath ) ) {
			return false;
		}

		// Safety check: only delete files in output dir.
		if ( strpos( realpath( $filepath ), realpath( $this->output_dir ) ) !== 0 ) {
			throw new \RuntimeException( 'Cannot delete files outside output directory' );
		}

		return unlink( $filepath );
	}

	/**
	 * Generate summary statistics.
	 *
	 * @param array<array{post_id: int, classifications: array}> $results Results.
	 *
	 * @return array{total_posts: int, posts_with_suggestions: int, total_suggestions: int, by_taxonomy: array}
	 */
	private function generateSummary( array $results ): array {
		$total_posts = count( $results );
		$posts_with_suggestions = 0;
		$total_suggestions = 0;
		$by_taxonomy = [];

		foreach ( $results as $result ) {
			if ( ! empty( $result['classifications'] ) ) {
				$posts_with_suggestions++;

				foreach ( $result['classifications'] as $taxonomy => $terms ) {
					$count = count( $terms );
					$total_suggestions += $count;

					if ( ! isset( $by_taxonomy[ $taxonomy ] ) ) {
						$by_taxonomy[ $taxonomy ] = 0;
					}
					$by_taxonomy[ $taxonomy ] += $count;
				}
			}
		}

		return [
			'total_posts'            => $total_posts,
			'posts_with_suggestions' => $posts_with_suggestions,
			'total_suggestions'      => $total_suggestions,
			'by_taxonomy'            => $by_taxonomy,
		];
	}

	/**
	 * Generate unique filename.
	 *
	 * @return string Filename.
	 */
	private function generateFilename(): string {
		return sprintf( 'suggestions-%s.json', gmdate( 'Y-m-d-His' ) );
	}

	/**
	 * Merge multiple result sets.
	 *
	 * @param array<string> $filepaths File paths to merge.
	 *
	 * @return array<array{post_id: int, post_title: string, classifications: array}>
	 */
	public function merge( array $filepaths ): array {
		$merged = [];
		$seen_posts = [];

		foreach ( $filepaths as $filepath ) {
			$data = $this->load( $filepath );

			foreach ( $data['results'] as $result ) {
				$post_id = $result['post_id'];

				// Skip duplicates, keep first occurrence.
				if ( isset( $seen_posts[ $post_id ] ) ) {
					continue;
				}

				$seen_posts[ $post_id ] = true;
				$merged[] = $result;
			}
		}

		return $merged;
	}

	/**
	 * Filter results by post IDs.
	 *
	 * @param array<array{post_id: int}> $results  Results.
	 * @param array<int>                 $post_ids Post IDs to keep.
	 *
	 * @return array<array{post_id: int}>
	 */
	public function filterByPostIds( array $results, array $post_ids ): array {
		return array_filter( $results, fn( $r ) => in_array( $r['post_id'], $post_ids, true ) );
	}

	/**
	 * Filter results by taxonomy.
	 *
	 * @param array<array{classifications: array}> $results   Results.
	 * @param string                               $taxonomy  Taxonomy to filter.
	 *
	 * @return array<array{classifications: array}>
	 */
	public function filterByTaxonomy( array $results, string $taxonomy ): array {
		return array_filter( $results, function ( $result ) use ( $taxonomy ) {
			return isset( $result['classifications'][ $taxonomy ] )
				&& ! empty( $result['classifications'][ $taxonomy ] );
		} );
	}

	/**
	 * Get output directory path.
	 *
	 * @return string Directory path.
	 */
	public function getOutputDir(): string {
		return $this->output_dir;
	}
}
