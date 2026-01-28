<?php
/**
 * WP-CLI Commands
 *
 * @package DGWTaxonomyAudit\CLI
 */

declare(strict_types=1);

namespace DGWTaxonomyAudit\CLI;

use DGWTaxonomyAudit\Core\Classifier;
use DGWTaxonomyAudit\Core\ContentExporter;
use DGWTaxonomyAudit\Core\LLMClientInterface;
use DGWTaxonomyAudit\Core\OllamaClient;
use DGWTaxonomyAudit\Core\OpenAIClient;
use DGWTaxonomyAudit\Core\OpenRouterClient;
use DGWTaxonomyAudit\Core\TaxonomyExtractor;
use DGWTaxonomyAudit\Output\CSVHandler;
use DGWTaxonomyAudit\Output\ScriptGenerator;
use DGWTaxonomyAudit\Output\SuggestionStore;
use WP_CLI;

/**
 * AI-powered taxonomy classification using LLM (Ollama or OpenAI).
 *
 * ## EXAMPLES
 *
 *     # Export vocabulary for a taxonomy
 *     wp taxonomy-audit export-vocab --taxonomies=category,post_tag
 *
 *     # Classify posts using local Ollama
 *     wp taxonomy-audit classify --post_type=post --limit=10
 *
 *     # Classify posts using OpenAI
 *     wp taxonomy-audit classify --provider=openai --model=gpt-4o-mini --limit=10
 *
 *     # Apply suggestions from CSV
 *     wp taxonomy-audit apply --file=suggestions.csv
 *
 *     # Generate shell script from suggestions
 *     wp taxonomy-audit generate-script --file=suggestions.json
 */
class Commands {

	/**
	 * Classify posts against taxonomy vocabularies using LLM.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<type>]
	 * : Post type to classify.
	 * ---
	 * default: post
	 * ---
	 *
	 * [--post-ids=<ids>]
	 * : Comma-separated list of specific post IDs to classify.
	 *
	 * [--limit=<number>]
	 * : Maximum number of posts to process.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--taxonomies=<list>]
	 * : Comma-separated list of taxonomies to classify against.
	 * ---
	 * default: category,post_tag
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format (csv, json, terminal).
	 * ---
	 * default: csv
	 * options:
	 *   - csv
	 *   - json
	 *   - terminal
	 * ---
	 *
	 * [--prefix=<prefix>]
	 * : WP-CLI command prefix for generated scripts.
	 * ---
	 * default: ddev wp
	 * ---
	 *
	 * [--provider=<provider>]
	 * : LLM provider to use.
	 * ---
	 * default: ollama
	 * options:
	 *   - ollama
	 *   - openai
	 * ---
	 *
	 * [--model=<model>]
	 * : Model to use. Defaults: ollama=gemma3:27b, openai=gpt-4o-mini.
	 *
	 * [--min-confidence=<threshold>]
	 * : Minimum confidence threshold (0-1).
	 * ---
	 * default: 0.7
	 * ---
	 *
	 * [--dry-run]
	 * : Show what would be processed without calling LLM.
	 *
	 * [--unclassified-only]
	 * : Only process posts without any taxonomy terms.
	 *
	 * ## EXAMPLES
	 *
	 *     wp taxonomy-audit classify --post_type=post --limit=10 --format=csv
	 *     wp taxonomy-audit classify --post-ids=123,456,789
	 *     wp taxonomy-audit classify --taxonomies=topic,category --model=qwen2.5:14b
	 *     wp taxonomy-audit classify --provider=openai --model=gpt-4o-mini --limit=5
	 *
	 * @param array<string> $args       Positional arguments.
	 * @param array<string> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function classify( array $args, array $assoc_args ): void {
		$post_type = $assoc_args['post_type'] ?? 'post';
		$limit = (int) ( $assoc_args['limit'] ?? 10 );
		$taxonomies = array_map( 'trim', explode( ',', $assoc_args['taxonomies'] ?? 'category,post_tag' ) );
		$format = $assoc_args['format'] ?? 'csv';
		$prefix = $assoc_args['prefix'] ?? 'ddev wp';
		$provider = $assoc_args['provider'] ?? 'ollama';
		$model = $assoc_args['model'] ?? null;
		$min_confidence = (float) ( $assoc_args['min-confidence'] ?? 0.7 );
		$dry_run = isset( $assoc_args['dry-run'] );
		$unclassified_only = isset( $assoc_args['unclassified-only'] );

		// Validate taxonomies.
		$extractor = new TaxonomyExtractor();
		$validation = $extractor->validateTaxonomies( $taxonomies );

		if ( ! empty( $validation['invalid'] ) ) {
			WP_CLI::error( sprintf(
				'Invalid taxonomies: %s',
				implode( ', ', $validation['invalid'] )
			) );
		}

		$taxonomies = $validation['valid'];

		// Create LLM client based on provider.
		try {
			$client = $this->createClient( $provider, $model );
		} catch ( \Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		if ( ! $dry_run && ! $client->isAvailable() ) {
			if ( 'openai' === $provider ) {
				WP_CLI::error( 'OpenAI API is not available. Check your API key (OPENAI_API_KEY constant).' );
			} else {
				WP_CLI::error( 'Ollama is not available. Is it running?' );
			}
		}

		// Get posts to process.
		if ( ! empty( $assoc_args['post-ids'] ) ) {
			$post_ids = array_map( 'intval', explode( ',', $assoc_args['post-ids'] ) );
		} elseif ( $unclassified_only ) {
			$exporter = new ContentExporter();
			$posts = $exporter->getUnclassifiedPosts( $post_type, $taxonomies, $limit );
			$post_ids = array_column( $posts, 'post_id' );
		} else {
			$posts = get_posts( [
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
			] );
			$post_ids = $posts;
		}

		if ( empty( $post_ids ) ) {
			WP_CLI::warning( 'No posts found to classify.' );
			return;
		}

		WP_CLI::log( sprintf( 'Found %d posts to classify.', count( $post_ids ) ) );
		WP_CLI::log( sprintf( 'Taxonomies: %s', implode( ', ', $taxonomies ) ) );
		WP_CLI::log( sprintf( 'Provider: %s', $client->getProvider() ) );
		WP_CLI::log( sprintf( 'Model: %s', $client->getModel() ) );
		WP_CLI::log( '' );

		if ( $dry_run ) {
			$this->showDryRun( $post_ids );
			return;
		}

		// Create classifier.
		$classifier = new Classifier( $client, $extractor );
		$classifier->setMinConfidence( $min_confidence );

		// Create progress bar.
		$progress = \WP_CLI\Utils\make_progress_bar( 'Classifying posts', count( $post_ids ) );

		$results = [];
		foreach ( $post_ids as $post_id ) {
			$result = $classifier->classifyPost( $post_id, $taxonomies );
			$results[] = $result;

			if ( $result['error'] ) {
				WP_CLI::warning( sprintf( 'Post %d: %s', $post_id, $result['error'] ) );
			}

			$progress->tick();
		}

		$progress->finish();

		// Output results.
		$this->outputResults( $results, $format, $prefix );
	}

	/**
	 * Export taxonomy vocabulary.
	 *
	 * ## OPTIONS
	 *
	 * [--taxonomies=<list>]
	 * : Comma-separated list of taxonomies.
	 * ---
	 * default: category,post_tag
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - table
	 * ---
	 *
	 * [--file=<path>]
	 * : Output file path.
	 *
	 * ## EXAMPLES
	 *
	 *     wp taxonomy-audit export-vocab --taxonomies=category
	 *     wp taxonomy-audit export-vocab --format=table
	 *
	 * @param array<string> $args       Positional arguments.
	 * @param array<string> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function export_vocab( array $args, array $assoc_args ): void {
		$taxonomies = array_map( 'trim', explode( ',', $assoc_args['taxonomies'] ?? 'category,post_tag' ) );
		$format = $assoc_args['format'] ?? 'json';
		$file = $assoc_args['file'] ?? null;

		$extractor = new TaxonomyExtractor();
		$vocabulary = $extractor->getVocabulary( $taxonomies );

		if ( empty( $vocabulary ) ) {
			WP_CLI::warning( 'No terms found in specified taxonomies.' );
			return;
		}

		if ( 'table' === $format ) {
			foreach ( $vocabulary as $taxonomy => $terms ) {
				WP_CLI::log( sprintf( "\n=== %s ===", strtoupper( $taxonomy ) ) );
				WP_CLI\Utils\format_items( 'table', $terms, [ 'slug', 'name', 'description' ] );
			}
			return;
		}

		$json = wp_json_encode( $vocabulary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( $file ) {
			file_put_contents( $file, $json );
			WP_CLI::success( sprintf( 'Vocabulary exported to: %s', $file ) );
		} else {
			WP_CLI::log( $json );
		}
	}

	/**
	 * Apply taxonomy suggestions from CSV or JSON file.
	 *
	 * ## OPTIONS
	 *
	 * --file=<path>
	 * : Path to CSV or JSON file with suggestions.
	 *
	 * [--prefix=<prefix>]
	 * : WP-CLI command prefix.
	 * ---
	 * default: ddev wp
	 * ---
	 *
	 * [--approved-only]
	 * : Only apply rows marked as approved in CSV.
	 *
	 * [--dry-run]
	 * : Display commands without executing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp taxonomy-audit apply --file=suggestions.csv
	 *     wp taxonomy-audit apply --file=suggestions.csv --approved-only --dry-run
	 *
	 * @param array<string> $args       Positional arguments.
	 * @param array<string> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function apply( array $args, array $assoc_args ): void {
		$file = $assoc_args['file'] ?? '';
		$prefix = $assoc_args['prefix'] ?? 'ddev wp';
		$approved_only = isset( $assoc_args['approved-only'] );
		$dry_run = isset( $assoc_args['dry-run'] );

		if ( empty( $file ) ) {
			WP_CLI::error( 'Please specify a file with --file' );
		}

		if ( ! file_exists( $file ) ) {
			WP_CLI::error( sprintf( 'File not found: %s', $file ) );
		}

		$extension = pathinfo( $file, PATHINFO_EXTENSION );

		if ( 'csv' === $extension ) {
			$this->applyFromCSV( $file, $prefix, $approved_only, $dry_run );
		} elseif ( 'json' === $extension ) {
			$this->applyFromJSON( $file, $prefix, $dry_run );
		} else {
			WP_CLI::error( 'Unsupported file format. Use .csv or .json' );
		}
	}

	/**
	 * Generate shell script from suggestions file.
	 *
	 * ## OPTIONS
	 *
	 * --file=<path>
	 * : Path to CSV or JSON file with suggestions.
	 *
	 * [--output=<path>]
	 * : Output script path.
	 *
	 * [--prefix=<prefix>]
	 * : WP-CLI command prefix.
	 * ---
	 * default: ddev wp
	 * ---
	 *
	 * [--approved-only]
	 * : Only include approved rows from CSV.
	 *
	 * ## EXAMPLES
	 *
	 *     wp taxonomy-audit generate-script --file=suggestions.csv --output=apply.sh
	 *     wp taxonomy-audit generate-script --file=suggestions.json
	 *
	 * @param array<string> $args       Positional arguments.
	 * @param array<string> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function generate_script( array $args, array $assoc_args ): void {
		$file = $assoc_args['file'] ?? '';
		$output = $assoc_args['output'] ?? null;
		$prefix = $assoc_args['prefix'] ?? 'ddev wp';
		$approved_only = isset( $assoc_args['approved-only'] );

		if ( empty( $file ) ) {
			WP_CLI::error( 'Please specify a file with --file' );
		}

		if ( ! file_exists( $file ) ) {
			WP_CLI::error( sprintf( 'File not found: %s', $file ) );
		}

		$generator = new ScriptGenerator( $prefix );
		$extension = pathinfo( $file, PATHINFO_EXTENSION );

		if ( 'csv' === $extension ) {
			$csv_handler = new CSVHandler();
			$suggestions = $csv_handler->import( $file, $approved_only );
			$script = $generator->generateFromSuggestions( $suggestions, $output );
		} elseif ( 'json' === $extension ) {
			$store = new SuggestionStore();
			$data = $store->load( $file );
			$script = $generator->generate( $data['results'], $output );
		} else {
			WP_CLI::error( 'Unsupported file format. Use .csv or .json' );
		}

		if ( $output ) {
			WP_CLI::success( sprintf( 'Script generated: %s', $output ) );
		} else {
			WP_CLI::log( $script );
		}
	}

	/**
	 * Check provider status and available models.
	 *
	 * ## EXAMPLES
	 *
	 *     wp taxonomy-audit status
	 *
	 * @param array<string> $args       Positional arguments.
	 * @param array<string> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function status( array $args, array $assoc_args ): void {
		$config = \DGWTaxonomyAudit\get_config();

		WP_CLI::log( '=== AI Taxonomy Audit Status ===' );
		WP_CLI::log( '' );

		// Check Ollama.
		WP_CLI::log( '--- Ollama (Local) ---' );
		try {
			$ollama = new OllamaClient();
			if ( $ollama->isAvailable() ) {
				WP_CLI::log( WP_CLI::colorize( '%GStatus: Connected%n' ) );
				$models = $ollama->getModels();
				WP_CLI::log( sprintf( 'Available models: %s', implode( ', ', $models ) ?: 'none' ) );
				WP_CLI::log( sprintf( 'Default model: %s', $config['ollama']['model'] ) );

				if ( ! empty( $models ) && ! in_array( $config['ollama']['model'], $models, true ) ) {
					WP_CLI::log( WP_CLI::colorize( '%YNote: Default model not installed. Pull with: ollama pull ' . $config['ollama']['model'] . '%n' ) );
				}
			} else {
				WP_CLI::log( WP_CLI::colorize( '%RStatus: Not available%n' ) );
				WP_CLI::log( 'Start with: ollama serve' );
			}
		} catch ( \Exception $e ) {
			WP_CLI::log( WP_CLI::colorize( '%RStatus: Error - ' . $e->getMessage() . '%n' ) );
		}

		WP_CLI::log( '' );

		// Check OpenAI.
		WP_CLI::log( '--- OpenAI (API) ---' );
		if ( defined( 'OPENAI_API_KEY' ) && ! empty( OPENAI_API_KEY ) ) {
			try {
				$openai = new OpenAIClient();
				if ( $openai->isAvailable() ) {
					WP_CLI::log( WP_CLI::colorize( '%GStatus: Connected%n' ) );
					WP_CLI::log( sprintf( 'Default model: %s', $config['openai']['model'] ?? 'gpt-4o-mini' ) );
				} else {
					WP_CLI::log( WP_CLI::colorize( '%RStatus: API key invalid%n' ) );
				}
			} catch ( \Exception $e ) {
				WP_CLI::log( WP_CLI::colorize( '%RStatus: Error - ' . $e->getMessage() . '%n' ) );
			}
		} else {
			WP_CLI::log( WP_CLI::colorize( '%YStatus: Not configured%n' ) );
			WP_CLI::log( 'Add to wp-config.php: define( \'OPENAI_API_KEY\', \'sk-...\' );' );
		}

		WP_CLI::log( '' );

		// Show configuration.
		WP_CLI::log( '=== Classification Settings ===' );
		WP_CLI::log( sprintf( 'Max content length: %d chars', $config['classification']['max_content_length'] ) );
		WP_CLI::log( sprintf( 'Min confidence: %.1f', $config['classification']['min_confidence_threshold'] ) );
	}

	/**
	 * List saved suggestion files.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp taxonomy-audit list
	 *
	 * @param array<string> $args       Positional arguments.
	 * @param array<string> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function list( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'table';

		$store = new SuggestionStore();
		$files = $store->listFiles();

		if ( empty( $files ) ) {
			WP_CLI::log( 'No suggestion files found.' );
			WP_CLI::log( sprintf( 'Output directory: %s', $store->getOutputDir() ) );
			return;
		}

		WP_CLI\Utils\format_items( $format, $files, [ 'filename', 'generated_at', 'post_count' ] );
	}

	/**
	 * Show classification statistics.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<type>]
	 * : Post type to analyze.
	 * ---
	 * default: post
	 * ---
	 *
	 * [--taxonomies=<list>]
	 * : Comma-separated list of taxonomies.
	 * ---
	 * default: category,post_tag
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp taxonomy-audit stats
	 *     wp taxonomy-audit stats --post_type=page
	 *
	 * @param array<string> $args       Positional arguments.
	 * @param array<string> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function stats( array $args, array $assoc_args ): void {
		$post_type = $assoc_args['post_type'] ?? 'post';
		$taxonomies = array_map( 'trim', explode( ',', $assoc_args['taxonomies'] ?? 'category,post_tag' ) );

		$exporter = new ContentExporter();
		$stats = $exporter->getClassificationStats( $post_type, $taxonomies );

		WP_CLI::log( sprintf( '=== Classification Stats for "%s" ===', $post_type ) );
		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Total posts:        %d', $stats['total'] ) );
		WP_CLI::log( sprintf( 'With terms:         %d', $stats['classified'] ) );
		WP_CLI::log( sprintf( 'Without terms:      %d', $stats['unclassified'] ) );

		if ( $stats['total'] > 0 ) {
			$percentage = round( ( $stats['classified'] / $stats['total'] ) * 100, 1 );
			WP_CLI::log( sprintf( 'Coverage:           %.1f%%', $percentage ) );
		}
	}

	/**
	 * Show dry run information.
	 *
	 * @param array<int> $post_ids Post IDs.
	 *
	 * @return void
	 */
	private function showDryRun( array $post_ids ): void {
		WP_CLI::log( '=== DRY RUN ===' );
		WP_CLI::log( 'Would process the following posts:' );
		WP_CLI::log( '' );

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( $post ) {
				WP_CLI::log( sprintf( '  [%d] %s', $post_id, $post->post_title ) );
			}
		}

		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Total: %d posts', count( $post_ids ) ) );
		WP_CLI::log( '' );
		WP_CLI::log( 'Remove --dry-run to process these posts.' );
	}

	/**
	 * Output classification results.
	 *
	 * @param array<array{post_id: int, post_title: string, classifications: array}> $results Results.
	 * @param string                                                                 $format  Output format.
	 * @param string                                                                 $prefix  Command prefix.
	 *
	 * @return void
	 */
	private function outputResults( array $results, string $format, string $prefix ): void {
		// Count suggestions.
		$total_suggestions = 0;
		$posts_with_suggestions = 0;

		foreach ( $results as $result ) {
			if ( ! empty( $result['classifications'] ) ) {
				$posts_with_suggestions++;
				foreach ( $result['classifications'] as $terms ) {
					$total_suggestions += count( $terms );
				}
			}
		}

		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Posts with suggestions: %d', $posts_with_suggestions ) );
		WP_CLI::log( sprintf( 'Total suggestions: %d', $total_suggestions ) );

		if ( 0 === $total_suggestions ) {
			WP_CLI::warning( 'No suggestions generated.' );
			return;
		}

		switch ( $format ) {
			case 'csv':
				$csv_handler = new CSVHandler();
				$filename = $csv_handler->generateFilename();
				$filepath = \DGWTaxonomyAudit\get_output_dir() . $filename;
				$csv_handler->export( $results, $filepath );
				WP_CLI::success( sprintf( 'CSV exported: %s', $filepath ) );
				break;

			case 'json':
				$store = new SuggestionStore();
				$filepath = $store->save( $results );
				WP_CLI::success( sprintf( 'JSON saved: %s', $filepath ) );
				break;

			case 'terminal':
				$this->outputTerminal( $results, $prefix );
				break;
		}
	}

	/**
	 * Output results to terminal (copyable commands).
	 *
	 * @param array<array{post_id: int, post_title: string, classifications: array}> $results Results.
	 * @param string                                                                 $prefix  Command prefix.
	 *
	 * @return void
	 */
	private function outputTerminal( array $results, string $prefix ): void {
		$generator = new ScriptGenerator( $prefix );

		WP_CLI::log( '' );
		WP_CLI::log( '=== Commands to run ===' );
		WP_CLI::log( '(Copy and paste these commands)' );
		WP_CLI::log( '' );

		foreach ( $results as $result ) {
			if ( empty( $result['classifications'] ) ) {
				continue;
			}

			WP_CLI::log( sprintf( '# %s (ID: %d)', $result['post_title'], $result['post_id'] ) );

			foreach ( $result['classifications'] as $taxonomy => $terms ) {
				foreach ( $terms as $term ) {
					$command = sprintf(
						'%s post term add %d %s %s',
						$prefix,
						$result['post_id'],
						escapeshellarg( $taxonomy ),
						escapeshellarg( $term['term'] )
					);

					WP_CLI::log( $command );

					if ( ! empty( $term['reason'] ) ) {
						WP_CLI::log( sprintf( '  # Confidence: %.0f%% - %s', $term['confidence'] * 100, $term['reason'] ) );
					}
				}
			}

			WP_CLI::log( '' );
		}
	}

	/**
	 * Apply suggestions from CSV file.
	 *
	 * @param string $file          File path.
	 * @param string $prefix        Command prefix.
	 * @param bool   $approved_only Only apply approved.
	 * @param bool   $dry_run       Dry run mode.
	 *
	 * @return void
	 */
	private function applyFromCSV( string $file, string $prefix, bool $approved_only, bool $dry_run ): void {
		$csv_handler = new CSVHandler();

		// Validate first.
		$validation = $csv_handler->validate( $file );

		if ( ! $validation['valid'] ) {
			WP_CLI::error( sprintf( 'CSV validation failed: %s', implode( ', ', $validation['errors'] ) ) );
		}

		$suggestions = $csv_handler->import( $file, $approved_only );

		if ( empty( $suggestions ) ) {
			WP_CLI::warning( 'No suggestions to apply.' );
			return;
		}

		WP_CLI::log( sprintf( 'Found %d term assignments to apply.', count( $suggestions ) ) );

		if ( $dry_run ) {
			WP_CLI::log( '' );
			WP_CLI::log( '=== DRY RUN ===' );

			foreach ( $suggestions as $suggestion ) {
				WP_CLI::log( sprintf(
					'Would set: Post %d → %s: %s',
					$suggestion['post_id'],
					$suggestion['taxonomy'],
					$suggestion['term']
				) );
			}
			return;
		}

		// Apply terms.
		$progress = \WP_CLI\Utils\make_progress_bar( 'Applying terms', count( $suggestions ) );
		$errors = [];
		$success = 0;

		foreach ( $suggestions as $suggestion ) {
			$result = wp_set_post_terms(
				$suggestion['post_id'],
				[ $suggestion['term'] ],
				$suggestion['taxonomy'],
				true // Append.
			);

			if ( is_wp_error( $result ) ) {
				$errors[] = sprintf(
					'Post %d: %s',
					$suggestion['post_id'],
					$result->get_error_message()
				);
			} else {
				$success++;
			}

			$progress->tick();
		}

		$progress->finish();

		WP_CLI::success( sprintf( 'Applied %d term assignments.', $success ) );

		if ( ! empty( $errors ) ) {
			WP_CLI::warning( sprintf( '%d errors occurred:', count( $errors ) ) );
			foreach ( $errors as $error ) {
				WP_CLI::log( '  ' . $error );
			}
		}
	}

	/**
	 * Apply suggestions from JSON file.
	 *
	 * @param string $file    File path.
	 * @param string $prefix  Command prefix.
	 * @param bool   $dry_run Dry run mode.
	 *
	 * @return void
	 */
	private function applyFromJSON( string $file, string $prefix, bool $dry_run ): void {
		$store = new SuggestionStore();
		$data = $store->load( $file );
		$results = $data['results'] ?? [];

		if ( empty( $results ) ) {
			WP_CLI::warning( 'No suggestions in file.' );
			return;
		}

		// Count total assignments.
		$total = 0;
		foreach ( $results as $result ) {
			foreach ( $result['classifications'] ?? [] as $terms ) {
				$total += count( $terms );
			}
		}

		WP_CLI::log( sprintf( 'Found %d term assignments to apply.', $total ) );

		if ( $dry_run ) {
			WP_CLI::log( '' );
			WP_CLI::log( '=== DRY RUN ===' );

			foreach ( $results as $result ) {
				foreach ( $result['classifications'] ?? [] as $taxonomy => $terms ) {
					foreach ( $terms as $term ) {
						WP_CLI::log( sprintf(
							'Would set: Post %d → %s: %s',
							$result['post_id'],
							$taxonomy,
							$term['term']
						) );
					}
				}
			}
			return;
		}

		// Apply terms.
		$progress = \WP_CLI\Utils\make_progress_bar( 'Applying terms', $total );
		$errors = [];
		$success = 0;

		foreach ( $results as $result ) {
			foreach ( $result['classifications'] ?? [] as $taxonomy => $terms ) {
				foreach ( $terms as $term ) {
					$wp_result = wp_set_post_terms(
						$result['post_id'],
						[ $term['term'] ],
						$taxonomy,
						true // Append.
					);

					if ( is_wp_error( $wp_result ) ) {
						$errors[] = sprintf(
							'Post %d: %s',
							$result['post_id'],
							$wp_result->get_error_message()
						);
					} else {
						$success++;
					}

					$progress->tick();
				}
			}
		}

		$progress->finish();

		WP_CLI::success( sprintf( 'Applied %d term assignments.', $success ) );

		if ( ! empty( $errors ) ) {
			WP_CLI::warning( sprintf( '%d errors occurred:', count( $errors ) ) );
			foreach ( $errors as $error ) {
				WP_CLI::log( '  ' . $error );
			}
		}
	}

	/**
	 * Create LLM client based on provider.
	 *
	 * @param string      $provider Provider name (ollama or openai).
	 * @param string|null $model    Model name override.
	 *
	 * @return LLMClientInterface
	 *
	 * @throws \RuntimeException If provider is invalid or not configured.
	 */
	private function createClient( string $provider, ?string $model = null ): LLMClientInterface {
		$config = $model ? [ 'model' => $model ] : [];

		switch ( $provider ) {
			case 'openai':
				return new OpenAIClient( $config );

			case 'ollama':
			default:
				return new OllamaClient( $config );
		}
	}
}
