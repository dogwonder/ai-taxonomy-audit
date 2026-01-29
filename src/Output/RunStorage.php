<?php
/**
 * Run Storage
 *
 * @package DGWTaxonomyAudit\Output
 */

declare(strict_types=1);

namespace DGWTaxonomyAudit\Output;

/**
 * Manages structured storage of analysis runs.
 *
 * Each run is stored in a timestamped directory with a manifest
 * and associated result files (suggestions, gap-analysis, etc).
 */
class RunStorage {

	/**
	 * Base output directory.
	 *
	 * @var string
	 */
	private string $output_dir;

	/**
	 * Runs subdirectory.
	 *
	 * @var string
	 */
	private string $runs_dir;

	/**
	 * Applied changes subdirectory.
	 *
	 * @var string
	 */
	private string $applied_dir;

	/**
	 * Constructor.
	 *
	 * @param string|null $output_dir Base output directory path.
	 */
	public function __construct( ?string $output_dir = null ) {
		$this->output_dir  = $output_dir ?? \DGWTaxonomyAudit\get_output_dir();
		$this->runs_dir    = $this->output_dir . 'runs/';
		$this->applied_dir = $this->output_dir . 'applied/';
	}

	/**
	 * Create a new run.
	 *
	 * @param array<string, mixed> $config Run configuration.
	 * @param string               $notes  Optional notes.
	 *
	 * @return string Run ID (timestamp-based).
	 */
	public function createRun( array $config, string $notes = '' ): string {
		$run_id  = gmdate( 'Y-m-d\THis' );
		$run_dir = $this->runs_dir . $run_id . '/';

		if ( ! wp_mkdir_p( $run_dir ) ) {
			throw new \RuntimeException( "Failed to create run directory: {$run_dir}" );
		}

		$manifest = [
			'id'         => $run_id,
			'created_at' => gmdate( 'c' ),
			'config'     => $config,
			'files'      => [],
			'summary'    => [],
			'status'     => 'pending',
			'notes'      => $notes,
		];

		$this->saveManifest( $run_id, $manifest );

		return $run_id;
	}

	/**
	 * Add a file to a run.
	 *
	 * @param string               $run_id   Run ID.
	 * @param string               $type     File type (suggestions, gap-analysis, etc).
	 * @param array<string, mixed> $data     Data to save.
	 * @param array<string, mixed> $summary  Summary stats for manifest.
	 *
	 * @return string File path.
	 */
	public function addFile( string $run_id, string $type, array $data, array $summary = [] ): string {
		$run_dir = $this->getRunDir( $run_id );

		if ( ! is_dir( $run_dir ) ) {
			throw new \RuntimeException( "Run not found: {$run_id}" );
		}

		$filename = $type . '.json';
		$filepath = $run_dir . $filename;

		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( false === file_put_contents( $filepath, $json ) ) {
			throw new \RuntimeException( "Failed to write file: {$filepath}" );
		}

		// Update manifest.
		$manifest            = $this->loadManifest( $run_id );
		$manifest['files'][] = $filename;
		$manifest['files']   = array_unique( $manifest['files'] );

		if ( ! empty( $summary ) ) {
			$manifest['summary'] = array_merge( $manifest['summary'], $summary );
		}

		$this->saveManifest( $run_id, $manifest );

		return $filepath;
	}

	/**
	 * Mark a run as complete.
	 *
	 * @param string $run_id Run ID.
	 *
	 * @return void
	 */
	public function completeRun( string $run_id ): void {
		$manifest           = $this->loadManifest( $run_id );
		$manifest['status'] = 'complete';
		$this->saveManifest( $run_id, $manifest );
	}

	/**
	 * Mark a run as failed.
	 *
	 * @param string $run_id Run ID.
	 * @param string $error  Error message.
	 *
	 * @return void
	 */
	public function failRun( string $run_id, string $error ): void {
		$manifest           = $this->loadManifest( $run_id );
		$manifest['status'] = 'failed';
		$manifest['error']  = $error;
		$this->saveManifest( $run_id, $manifest );
	}

	/**
	 * List all runs.
	 *
	 * @return array<array{id: string, created_at: string, status: string, config: array, summary: array}>
	 */
	public function listRuns(): array {
		if ( ! is_dir( $this->runs_dir ) ) {
			return [];
		}

		$dirs = glob( $this->runs_dir . '*', GLOB_ONLYDIR );

		if ( ! $dirs ) {
			return [];
		}

		$runs = [];

		foreach ( $dirs as $dir ) {
			$run_id = basename( $dir );

			try {
				$manifest = $this->loadManifest( $run_id );
				$runs[]   = [
					'id'         => $run_id,
					'created_at' => $manifest['created_at'] ?? 'unknown',
					'status'     => $manifest['status'] ?? 'unknown',
					'config'     => $manifest['config'] ?? [],
					'summary'    => $manifest['summary'] ?? [],
					'files'      => $manifest['files'] ?? [],
				];
			} catch ( \Exception $e ) {
				// Skip invalid runs.
				continue;
			}
		}

		// Sort by date, newest first.
		usort( $runs, fn( $a, $b ) => strcmp( $b['created_at'], $a['created_at'] ) );

		return $runs;
	}

	/**
	 * Get a specific run.
	 *
	 * @param string $run_id Run ID.
	 *
	 * @return array<string, mixed> Run manifest.
	 */
	public function getRun( string $run_id ): array {
		return $this->loadManifest( $run_id );
	}

	/**
	 * Get the latest run.
	 *
	 * @return array<string, mixed>|null Run manifest or null.
	 */
	public function getLatestRun(): ?array {
		$runs = $this->listRuns();

		if ( empty( $runs ) ) {
			return null;
		}

		return $this->loadManifest( $runs[0]['id'] );
	}

	/**
	 * Load a file from a run.
	 *
	 * @param string $run_id Run ID.
	 * @param string $type   File type.
	 *
	 * @return array<string, mixed> File data.
	 */
	public function loadFile( string $run_id, string $type ): array {
		$filepath = $this->getRunDir( $run_id ) . $type . '.json';

		if ( ! file_exists( $filepath ) ) {
			throw new \RuntimeException( "File not found: {$type}.json in run {$run_id}" );
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
	 * Compare two runs.
	 *
	 * @param string $run_id_a First run ID.
	 * @param string $run_id_b Second run ID.
	 *
	 * @return array<string, mixed> Comparison results.
	 */
	public function compareRuns( string $run_id_a, string $run_id_b ): array {
		$run_a = $this->loadManifest( $run_id_a );
		$run_b = $this->loadManifest( $run_id_b );

		$comparison = [
			'run_a'   => $run_id_a,
			'run_b'   => $run_id_b,
			'config'  => $this->diffArrays( $run_a['config'] ?? [], $run_b['config'] ?? [] ),
			'summary' => $this->diffArrays( $run_a['summary'] ?? [], $run_b['summary'] ?? [] ),
			'files'   => [
				'only_in_a' => array_diff( $run_a['files'] ?? [], $run_b['files'] ?? [] ),
				'only_in_b' => array_diff( $run_b['files'] ?? [], $run_a['files'] ?? [] ),
				'in_both'   => array_intersect( $run_a['files'] ?? [], $run_b['files'] ?? [] ),
			],
		];

		// Compare suggestions if both have them.
		if ( in_array( 'suggestions.json', $run_a['files'] ?? [], true )
			&& in_array( 'suggestions.json', $run_b['files'] ?? [], true ) ) {
			$comparison['suggestions'] = $this->compareSuggestions( $run_id_a, $run_id_b );
		}

		return $comparison;
	}

	/**
	 * Delete a run.
	 *
	 * @param string $run_id Run ID.
	 *
	 * @return bool Success.
	 */
	public function deleteRun( string $run_id ): bool {
		$run_dir = $this->getRunDir( $run_id );

		if ( ! is_dir( $run_dir ) ) {
			return false;
		}

		// Safety check: only delete directories in runs dir.
		$real_run_dir  = realpath( $run_dir );
		$real_runs_dir = realpath( $this->runs_dir );

		if ( false === $real_run_dir || false === $real_runs_dir ) {
			return false;
		}

		if ( strpos( $real_run_dir, $real_runs_dir ) !== 0 ) {
			throw new \RuntimeException( 'Cannot delete directories outside runs directory' );
		}

		// Delete all files in directory.
		$files = glob( $run_dir . '*' );

		if ( $files ) {
			foreach ( $files as $file ) {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
		}

		return rmdir( $run_dir );
	}

	/**
	 * Record applied changes.
	 *
	 * @param string               $run_id  Source run ID.
	 * @param array<string, mixed> $changes Applied changes.
	 *
	 * @return string File path.
	 */
	public function recordApplied( string $run_id, array $changes ): string {
		if ( ! wp_mkdir_p( $this->applied_dir ) ) {
			throw new \RuntimeException( "Failed to create applied directory: {$this->applied_dir}" );
		}

		$filepath = $this->applied_dir . $run_id . '.json';

		$data = [
			'applied_at' => gmdate( 'c' ),
			'source_run' => $run_id,
			'changes'    => $changes,
		];

		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( false === file_put_contents( $filepath, $json ) ) {
			throw new \RuntimeException( "Failed to write file: {$filepath}" );
		}

		return $filepath;
	}

	/**
	 * Get path to run directory.
	 *
	 * @param string $run_id Run ID.
	 *
	 * @return string Directory path (with trailing slash).
	 */
	public function getRunDir( string $run_id ): string {
		return $this->runs_dir . $run_id . '/';
	}

	/**
	 * Get path to runs directory.
	 *
	 * @return string Directory path (with trailing slash).
	 */
	public function getRunsDir(): string {
		return $this->runs_dir;
	}

	/**
	 * Load manifest for a run.
	 *
	 * @param string $run_id Run ID.
	 *
	 * @return array<string, mixed> Manifest data.
	 */
	private function loadManifest( string $run_id ): array {
		$filepath = $this->getRunDir( $run_id ) . 'manifest.json';

		if ( ! file_exists( $filepath ) ) {
			throw new \RuntimeException( "Manifest not found for run: {$run_id}" );
		}

		$content = file_get_contents( $filepath );

		if ( false === $content ) {
			throw new \RuntimeException( "Failed to read manifest: {$filepath}" );
		}

		$data = json_decode( $content, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new \RuntimeException( 'Invalid manifest JSON: ' . json_last_error_msg() );
		}

		return $data;
	}

	/**
	 * Save manifest for a run.
	 *
	 * @param string               $run_id   Run ID.
	 * @param array<string, mixed> $manifest Manifest data.
	 *
	 * @return void
	 */
	private function saveManifest( string $run_id, array $manifest ): void {
		$filepath = $this->getRunDir( $run_id ) . 'manifest.json';

		$json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( false === file_put_contents( $filepath, $json ) ) {
			throw new \RuntimeException( "Failed to write manifest: {$filepath}" );
		}
	}

	/**
	 * Diff two arrays for comparison.
	 *
	 * @param array<string, mixed> $a First array.
	 * @param array<string, mixed> $b Second array.
	 *
	 * @return array<string, array{a: mixed, b: mixed}> Differences.
	 */
	private function diffArrays( array $a, array $b ): array {
		$diff = [];
		$keys = array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) );

		foreach ( $keys as $key ) {
			$val_a = $a[ $key ] ?? null;
			$val_b = $b[ $key ] ?? null;

			if ( $val_a !== $val_b ) {
				$diff[ $key ] = [
					'a' => $val_a,
					'b' => $val_b,
				];
			}
		}

		return $diff;
	}

	/**
	 * Compare suggestions between two runs.
	 *
	 * @param string $run_id_a First run ID.
	 * @param string $run_id_b Second run ID.
	 *
	 * @return array<string, mixed> Suggestion comparison.
	 */
	private function compareSuggestions( string $run_id_a, string $run_id_b ): array {
		$suggestions_a = $this->loadFile( $run_id_a, 'suggestions' );
		$suggestions_b = $this->loadFile( $run_id_b, 'suggestions' );

		$posts_a = $this->indexByPostId( $suggestions_a['results'] ?? [] );
		$posts_b = $this->indexByPostId( $suggestions_b['results'] ?? [] );

		$all_post_ids = array_unique( array_merge( array_keys( $posts_a ), array_keys( $posts_b ) ) );

		$only_in_a       = [];
		$only_in_b       = [];
		$in_both         = [];
		$changed         = [];
		$unchanged       = [];

		foreach ( $all_post_ids as $post_id ) {
			$in_a = isset( $posts_a[ $post_id ] );
			$in_b = isset( $posts_b[ $post_id ] );

			if ( $in_a && ! $in_b ) {
				$only_in_a[] = $post_id;
			} elseif ( ! $in_a && $in_b ) {
				$only_in_b[] = $post_id;
			} else {
				$in_both[] = $post_id;

				// Check if classifications changed.
				$class_a = $posts_a[ $post_id ]['classifications'] ?? [];
				$class_b = $posts_b[ $post_id ]['classifications'] ?? [];

				if ( $class_a !== $class_b ) {
					$changed[] = $post_id;
				} else {
					$unchanged[] = $post_id;
				}
			}
		}

		return [
			'posts_only_in_a' => $only_in_a,
			'posts_only_in_b' => $only_in_b,
			'posts_in_both'   => count( $in_both ),
			'posts_changed'   => $changed,
			'posts_unchanged' => count( $unchanged ),
		];
	}

	/**
	 * Index results by post ID.
	 *
	 * @param array<array{post_id: int}> $results Results.
	 *
	 * @return array<int, array> Indexed results.
	 */
	private function indexByPostId( array $results ): array {
		$indexed = [];

		foreach ( $results as $result ) {
			if ( isset( $result['post_id'] ) ) {
				$indexed[ $result['post_id'] ] = $result;
			}
		}

		return $indexed;
	}
}
