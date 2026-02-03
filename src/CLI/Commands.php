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
use DGWTaxonomyAudit\Core\ContentSampler;
use DGWTaxonomyAudit\Core\CostTracker;
use DGWTaxonomyAudit\Core\GapAnalyzer;
use DGWTaxonomyAudit\Core\LLMClientInterface;
use DGWTaxonomyAudit\Core\OllamaClient;
use DGWTaxonomyAudit\Core\OpenAIClient;
use DGWTaxonomyAudit\Core\OpenRouterClient;
use DGWTaxonomyAudit\Core\SKOSParser;
use DGWTaxonomyAudit\Core\TaxonomyExtractor;
use DGWTaxonomyAudit\Output\CSVHandler;
use DGWTaxonomyAudit\Output\RunStorage;
use DGWTaxonomyAudit\Output\ScriptGenerator;
use DGWTaxonomyAudit\Output\SuggestionStore;
use WP_CLI;

/**
 * AI-powered taxonomy classification using LLM (Ollama, OpenAI, or OpenRouter).
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
 *     # Classify posts using OpenRouter (access to many models)
 *     wp taxonomy-audit classify --provider=openrouter --model=google/gemma-2-9b-it:free
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
	 *   - openrouter
	 * ---
	 *
	 * [--model=<model>]
	 * : Model to use. Defaults vary by provider.
	 *
	 * [--single-step]
	 * : Use single-step classification instead of two-step conversation.
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
	 * [--sampling=<strategy>]
	 * : Sampling strategy for post selection.
	 * ---
	 * default: sequential
	 * options:
	 *   - sequential
	 *   - stratified
	 * ---
	 *
	 * [--save-run]
	 * : Save results to a structured run for historical tracking.
	 *
	 * [--run-notes=<notes>]
	 * : Optional notes to attach to the run (requires --save-run).
	 *
	 * [--audit]
	 * : Enable audit mode to suggest new taxonomy terms that don't exist in vocabulary. The LLM will suggest both existing vocabulary terms AND new terms that should exist. Results include an in_vocabulary flag to distinguish them.
	 *
	 * [--skos-context=<file>]
	 * : Path to SKOS Turtle file for hierarchical vocabulary context. Use with wp-to-file-graph SKOS export to provide the LLM with taxonomy hierarchy (broader/narrower relationships) and richer term definitions.
	 *
	 * ## EXAMPLES
	 *
	 *     wp taxonomy-audit classify --post_type=post --limit=10 --format=csv
	 *     wp taxonomy-audit classify --post-ids=123,456,789
	 *     wp taxonomy-audit classify --taxonomies=topic,category --model=qwen2.5:latest
	 *     wp taxonomy-audit classify --provider=openai --model=gpt-4o-mini --limit=5
	 *     wp taxonomy-audit classify --limit=20 --sampling=stratified
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
		$sampling = $assoc_args['sampling'] ?? 'sequential';
		$save_run = isset( $assoc_args['save-run'] );
		$run_notes = $assoc_args['run-notes'] ?? '';
		$audit_mode = isset( $assoc_args['audit'] );
		$skos_context_file = $assoc_args['skos-context'] ?? null;

		// Parse SKOS context if provided.
		$skos_data = null;
		if ( null !== $skos_context_file ) {
			if ( ! file_exists( $skos_context_file ) ) {
				WP_CLI::error( sprintf( 'SKOS file not found: %s', $skos_context_file ) );
			}

			$skos_parser = new SKOSParser();
			$skos_data = $skos_parser->parse( $skos_context_file );

			if ( ! empty( $skos_data['errors'] ) ) {
				WP_CLI::warning( sprintf( 'SKOS parsing warnings: %s', implode( ', ', $skos_data['errors'] ) ) );
			}

			if ( empty( $skos_data['concepts'] ) ) {
				WP_CLI::warning( 'No SKOS concepts found in file. Continuing without hierarchy context.' );
				$skos_data = null;
			} else {
				WP_CLI::log( sprintf( 'Loaded %d SKOS concepts for hierarchy context.', count( $skos_data['concepts'] ) ) );
			}
		}

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
			$error_messages = [
				'openai'     => 'OpenAI API is not available. Check your API key (OPENAI_API_KEY constant).',
				'openrouter' => 'OpenRouter API is not available. Check your API key (OPENROUTER_API_KEY constant).',
				'ollama'     => 'Ollama is not available. Is it running?',
			];
			WP_CLI::error( $error_messages[ $provider ] ?? 'Provider not available.' );
		}

		// Get posts to process.
		if ( ! empty( $assoc_args['post-ids'] ) ) {
			$post_ids = array_map( 'intval', explode( ',', $assoc_args['post-ids'] ) );
		} elseif ( $unclassified_only ) {
			$exporter = new ContentExporter();
			$posts = $exporter->getUnclassifiedPosts( $post_type, $taxonomies, $limit );
			$post_ids = array_column( $posts, 'post_id' );
		} elseif ( 'stratified' === $sampling ) {
			$sampler = new ContentSampler();
			$post_ids = $sampler->getStratifiedSample( $post_type, $limit, $taxonomies );

			// Show sampling info.
			$stats = $sampler->getSamplingStats( $post_type, $taxonomies );
			WP_CLI::log( sprintf(
				'Stratified sampling: %d date periods, %d taxonomy terms, %d uncategorized',
				$stats['date_periods'],
				$stats['taxonomy_terms'],
				$stats['uncategorized']
			) );
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
		WP_CLI::log( sprintf( 'Mode: %s', $audit_mode ? 'audit (gap-filling enabled)' : 'benchmark (vocabulary-only)' ) );
		WP_CLI::log( '' );

		// Handle single-step flag.
		$two_step_mode = ! isset( $assoc_args['single-step'] );

		if ( $dry_run ) {
			$this->showDryRun( $post_ids, $client, $taxonomies, $two_step_mode );
			return;
		}

		// Create classifier.
		$classifier = new Classifier( $client, $extractor );
		$classifier->setMinConfidence( $min_confidence );
		$classifier->setTwoStep( $two_step_mode );
		$classifier->setAuditMode( $audit_mode );

		if ( null !== $skos_data ) {
			$classifier->setSkosContext( $skos_data );
		}

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

		// Get usage statistics.
		$usage = $classifier->getUsage();

		// Show cost summary.
		$this->outputCostSummary( $usage, $client );

		// Save to run if requested.
		if ( $save_run ) {
			$run_storage = new RunStorage();

			$config = [
				'provider'             => $provider,
				'model'                => $client->getModel(),
				'post_type'            => $post_type,
				'taxonomies'           => $taxonomies,
				'limit'                => $limit,
				'sampling'             => $sampling,
				'min_confidence'       => $min_confidence,
				'unclassified_only'    => $unclassified_only,
				'audit_mode'           => $audit_mode,
			];

			try {
				$run_id = $run_storage->createRun( $config, $run_notes );

				// Build suggestions data.
				$suggestions_data = [
					'generated_at' => gmdate( 'c' ),
					'version'      => DGW_TAXONOMY_AUDIT_VERSION,
					'results'      => $results,
				];

				// Calculate summary.
				$total_suggestions = 0;
				$posts_with_suggestions = 0;
				$by_taxonomy = [];

				foreach ( $results as $result ) {
					if ( ! empty( $result['classifications'] ) ) {
						$posts_with_suggestions++;
						foreach ( $result['classifications'] as $tax => $terms ) {
							$count = count( $terms );
							$total_suggestions += $count;
							$by_taxonomy[ $tax ] = ( $by_taxonomy[ $tax ] ?? 0 ) + $count;
						}
					}
				}

				$summary = [
					'total_posts'            => count( $results ),
					'posts_with_suggestions' => $posts_with_suggestions,
					'total_suggestions'      => $total_suggestions,
					'by_taxonomy'            => $by_taxonomy,
					'usage'                  => $usage,
				];

				$run_storage->addFile( $run_id, 'suggestions', $suggestions_data, $summary );
				$run_storage->completeRun( $run_id );

				WP_CLI::log( sprintf( 'Run saved: %s', $run_id ) );
			} catch ( \RuntimeException $e ) {
				WP_CLI::warning( sprintf( 'Failed to save run: %s', $e->getMessage() ) );
			}
		}

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

		// Check OpenRouter.
		WP_CLI::log( '--- OpenRouter (Multi-Model API) ---' );
		if ( defined( 'OPENROUTER_API_KEY' ) && ! empty( OPENROUTER_API_KEY ) ) {
			try {
				$openrouter = new OpenRouterClient();
				if ( $openrouter->isAvailable() ) {
					WP_CLI::log( WP_CLI::colorize( '%GStatus: Connected%n' ) );
					WP_CLI::log( sprintf( 'Default model: %s', $config['openrouter']['model'] ?? 'google/gemma-2-9b-it:free' ) );
					$models = $openrouter->getModels();
					if ( ! empty( $models ) ) {
						WP_CLI::log( sprintf( 'Recommended models: %s', implode( ', ', array_slice( $models, 0, 3 ) ) ) );
					}
				} else {
					WP_CLI::log( WP_CLI::colorize( '%RStatus: API key invalid%n' ) );
				}
			} catch ( \Exception $e ) {
				WP_CLI::log( WP_CLI::colorize( '%RStatus: Error - ' . $e->getMessage() . '%n' ) );
			}
		} else {
			WP_CLI::log( WP_CLI::colorize( '%YStatus: Not configured%n' ) );
			WP_CLI::log( 'Add to wp-config.php: define( \'OPENROUTER_API_KEY\', \'sk-or-...\' );' );
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
	 * Show dry run information with cost estimates.
	 *
	 * @param array<int>          $post_ids   Post IDs.
	 * @param LLMClientInterface  $client     LLM client.
	 * @param array<string>       $taxonomies Taxonomies.
	 * @param bool                $two_step   Whether using two-step mode.
	 *
	 * @return void
	 */
	private function showDryRun( array $post_ids, LLMClientInterface $client, array $taxonomies, bool $two_step = true ): void {
		WP_CLI::log( '=== DRY RUN ===' );
		WP_CLI::log( 'Would process the following posts:' );
		WP_CLI::log( '' );

		$total_content_length = 0;
		$config = \DGWTaxonomyAudit\get_config();
		$max_content_length = $config['classification']['max_content_length'];

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( $post ) {
				WP_CLI::log( sprintf( '  [%d] %s', $post_id, $post->post_title ) );
				// Estimate content length (capped at max).
				$content_length = min( mb_strlen( $post->post_content ), $max_content_length );
				$total_content_length += $content_length;
			}
		}

		$avg_content_length = count( $post_ids ) > 0
			? (int) ( $total_content_length / count( $post_ids ) )
			: 500;

		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Total: %d posts', count( $post_ids ) ) );

		// Calculate vocabulary size.
		$extractor = new TaxonomyExtractor();
		$vocabulary = $extractor->getVocabulary( $taxonomies );
		$vocab_size = 0;
		foreach ( $vocabulary as $tax_terms ) {
			foreach ( $tax_terms as $term ) {
				$vocab_size += mb_strlen( $term['slug'] ?? '' );
				$vocab_size += mb_strlen( $term['name'] ?? '' );
				$vocab_size += mb_strlen( $term['description'] ?? '' );
			}
		}

		// Get cost estimates.
		$cost_tracker = new CostTracker( $client->getProvider(), $client->getModel() );
		$estimate = $cost_tracker->estimateCost(
			count( $post_ids ),
			$avg_content_length,
			$vocab_size,
			$two_step
		);

		WP_CLI::log( '' );
		WP_CLI::log( '=== Cost Estimate ===' );
		WP_CLI::log( sprintf( 'Provider: %s', $client->getProvider() ) );
		WP_CLI::log( sprintf( 'Model: %s', $client->getModel() ) );
		WP_CLI::log( sprintf( 'Estimated input tokens: %s', number_format( $estimate['estimated_input_tokens'] ) ) );
		WP_CLI::log( sprintf( 'Estimated output tokens: %s', number_format( $estimate['estimated_output_tokens'] ) ) );
		WP_CLI::log( sprintf( 'Estimated API calls: %d', $estimate['estimated_requests'] ) );
		WP_CLI::log( sprintf( 'Estimated cost: %s', $estimate['cost_formatted'] ) );

		// Show comparison with other providers.
		if ( 'ollama' !== $client->getProvider() ) {
			WP_CLI::log( '' );
			WP_CLI::log( '--- Provider Comparison ---' );
			$comparison = CostTracker::compareProviders(
				$estimate['estimated_input_tokens'],
				$estimate['estimated_output_tokens']
			);
			foreach ( $comparison as $label => $data ) {
				WP_CLI::log( sprintf( '  %s: %s', $label, $data['cost_formatted'] ) );
			}
		}

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
	 * Output cost summary after classification.
	 *
	 * @param array{input_tokens: int, output_tokens: int, total_tokens: int, requests: int, cost: float, cost_formatted: string} $usage  Usage data.
	 * @param LLMClientInterface                                                                                                  $client LLM client.
	 *
	 * @return void
	 */
	private function outputCostSummary( array $usage, LLMClientInterface $client ): void {
		WP_CLI::log( '' );
		WP_CLI::log( '=== Usage Summary ===' );
		WP_CLI::log( sprintf( 'Provider: %s', $client->getProvider() ) );
		WP_CLI::log( sprintf( 'Model: %s', $client->getModel() ) );
		WP_CLI::log( sprintf( 'API requests: %d', $usage['requests'] ) );
		WP_CLI::log( sprintf( 'Input tokens: %s', number_format( $usage['input_tokens'] ) ) );
		WP_CLI::log( sprintf( 'Output tokens: %s', number_format( $usage['output_tokens'] ) ) );
		WP_CLI::log( sprintf( 'Total tokens: %s', number_format( $usage['total_tokens'] ) ) );
		WP_CLI::log( sprintf( 'Cost: %s', $usage['cost_formatted'] ) );

		// Show comparison if using a paid provider.
		if ( 'ollama' !== $client->getProvider() && $usage['total_tokens'] > 0 ) {
			WP_CLI::log( '' );
			WP_CLI::log( '--- Cost Comparison ---' );
			$comparison = CostTracker::compareProviders( $usage['input_tokens'], $usage['output_tokens'] );
			foreach ( $comparison as $label => $data ) {
				$marker = $data['cost'] === $usage['cost'] ? ' (current)' : '';
				WP_CLI::log( sprintf( '  %s: %s%s', $label, $data['cost_formatted'], $marker ) );
			}
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
	 * @param string      $provider Provider name (ollama, openai, or openrouter).
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

			case 'openrouter':
				return new OpenRouterClient( $config );

			case 'ollama':
			default:
				return new OllamaClient( $config );
		}
	}

	/**
	 * Analyze taxonomy gaps between suggestions and vocabulary.
	 *
	 * Compares classification suggestions against existing vocabulary to identify:
	 * - Terms suggested but not in vocabulary (potential new terms)
	 * - Existing terms never suggested (potential pruning candidates)
	 * - Terms with low confidence (ambiguous terms)
	 * - Content without adequate taxonomy coverage
	 *
	 * ## OPTIONS
	 *
	 * --suggestions=<path>
	 * : Path to suggestions JSON file from classify command.
	 *
	 * [--taxonomies=<list>]
	 * : Comma-separated list of taxonomies to analyze.
	 * ---
	 * default: category,post_tag
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * [--output=<path>]
	 * : Save JSON output to file.
	 *
	 * [--save-run=<run-id>]
	 * : Add gap analysis to an existing run by ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp taxonomy-audit gap-analysis --suggestions=output/suggestions-2025-01-28.json
	 *     wp taxonomy-audit gap-analysis --suggestions=output/suggestions.json --format=json
	 *     wp taxonomy-audit gap-analysis --suggestions=output/suggestions.json --output=gap-report.json
	 *
	 * @param array<string> $args       Positional arguments.
	 * @param array<string> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function gap_analysis( array $args, array $assoc_args ): void {
		$suggestions_file = $assoc_args['suggestions'] ?? '';
		$taxonomies = array_map( 'trim', explode( ',', $assoc_args['taxonomies'] ?? 'category,post_tag' ) );
		$format = $assoc_args['format'] ?? 'table';
		$output_file = $assoc_args['output'] ?? null;
		$save_to_run = $assoc_args['save-run'] ?? null;

		if ( empty( $suggestions_file ) ) {
			WP_CLI::error( 'Please specify a suggestions file with --suggestions' );
		}

		if ( ! file_exists( $suggestions_file ) ) {
			WP_CLI::error( sprintf( 'File not found: %s', $suggestions_file ) );
		}

		// Load suggestions.
		$store = new SuggestionStore();
		$data = $store->load( $suggestions_file );
		$suggestions = $data['results'] ?? [];

		if ( empty( $suggestions ) ) {
			WP_CLI::error( 'No suggestions found in file.' );
		}

		WP_CLI::log( sprintf( 'Analyzing %d posts against taxonomies: %s', count( $suggestions ), implode( ', ', $taxonomies ) ) );
		WP_CLI::log( '' );

		// Run analysis.
		$analyzer = new GapAnalyzer();
		$report = $analyzer->analyze( $suggestions, $taxonomies );

		// Save to run if requested.
		if ( $save_to_run ) {
			$run_storage = new RunStorage();

			try {
				$summary = [
					'health_score'        => $report['summary']['health_score'] ?? 0,
					'new_term_candidates' => $report['summary']['new_term_candidates'] ?? 0,
					'unused_terms'        => $report['summary']['unused_terms'] ?? 0,
					'ambiguous_terms'     => $report['summary']['ambiguous_terms'] ?? 0,
				];

				$run_storage->addFile( $save_to_run, 'gap-analysis', $report, $summary );
				WP_CLI::log( sprintf( 'Gap analysis added to run: %s', $save_to_run ) );
			} catch ( \RuntimeException $e ) {
				WP_CLI::warning( sprintf( 'Failed to save to run: %s', $e->getMessage() ) );
			}
		}

		// Output based on format.
		if ( 'json' === $format || $output_file ) {
			$json = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

			if ( $output_file ) {
				file_put_contents( $output_file, $json );
				WP_CLI::success( sprintf( 'Gap analysis report saved to: %s', $output_file ) );
			} else {
				WP_CLI::log( $json );
			}
			return;
		}

		// Table format output.
		$this->outputGapAnalysisTable( $report );
	}

	/**
	 * Output gap analysis report as tables.
	 *
	 * @param array $report Gap analysis report.
	 *
	 * @return void
	 */
	private function outputGapAnalysisTable( array $report ): void {
		// Summary.
		WP_CLI::log( '=== Gap Analysis Summary ===' );
		WP_CLI::log( sprintf( 'Health Score: %.0f/100', $report['summary']['health_score'] ) );
		WP_CLI::log( sprintf( 'New term candidates: %d', $report['summary']['new_term_candidates'] ) );
		WP_CLI::log( sprintf( 'Unused existing terms: %d', $report['summary']['unused_terms'] ) );
		WP_CLI::log( sprintf( 'Ambiguous terms: %d', $report['summary']['ambiguous_terms'] ) );
		WP_CLI::log( sprintf( 'Uncovered posts: %d', $report['summary']['uncovered_posts'] ) );
		WP_CLI::log( '' );

		// Suggested new terms.
		if ( ! empty( $report['suggested_new_terms'] ) ) {
			WP_CLI::log( '=== Suggested New Terms ===' );
			WP_CLI::log( 'Terms suggested by LLM that don\'t exist in vocabulary:' );
			WP_CLI\Utils\format_items(
				'table',
				array_slice( $report['suggested_new_terms'], 0, 20 ),
				[ 'term', 'taxonomy', 'suggested_count', 'avg_confidence' ]
			);
			if ( count( $report['suggested_new_terms'] ) > 20 ) {
				WP_CLI::log( sprintf( '... and %d more', count( $report['suggested_new_terms'] ) - 20 ) );
			}
			WP_CLI::log( '' );
		}

		// Unused existing terms.
		if ( ! empty( $report['unused_existing_terms'] ) ) {
			WP_CLI::log( '=== Unused Existing Terms ===' );
			WP_CLI::log( 'Terms in vocabulary that were never suggested:' );
			WP_CLI\Utils\format_items(
				'table',
				array_slice( $report['unused_existing_terms'], 0, 20 ),
				[ 'term', 'taxonomy', 'name', 'post_count' ]
			);
			if ( count( $report['unused_existing_terms'] ) > 20 ) {
				WP_CLI::log( sprintf( '... and %d more', count( $report['unused_existing_terms'] ) - 20 ) );
			}
			WP_CLI::log( '' );
		}

		// Ambiguous terms.
		if ( ! empty( $report['ambiguous_terms'] ) ) {
			WP_CLI::log( '=== Ambiguous Terms ===' );
			WP_CLI::log( 'Terms with low average confidence (may need clarification):' );
			WP_CLI\Utils\format_items(
				'table',
				$report['ambiguous_terms'],
				[ 'term', 'taxonomy', 'avg_confidence', 'post_count' ]
			);
			WP_CLI::log( '' );
		}

		// Uncovered content.
		if ( ! empty( $report['uncovered_content'] ) ) {
			WP_CLI::log( '=== Uncovered Content ===' );
			WP_CLI::log( 'Posts without adequate taxonomy suggestions:' );
			$items = array_map( function ( $item ) {
				return [
					'post_id'    => $item['post_id'],
					'post_title' => mb_substr( $item['post_title'], 0, 50 ),
				];
			}, array_slice( $report['uncovered_content'], 0, 20 ) );
			WP_CLI\Utils\format_items( 'table', $items, [ 'post_id', 'post_title' ] );
			if ( count( $report['uncovered_content'] ) > 20 ) {
				WP_CLI::log( sprintf( '... and %d more', count( $report['uncovered_content'] ) - 20 ) );
			}
			WP_CLI::log( '' );
		}

		if ( empty( $report['suggested_new_terms'] ) &&
			empty( $report['unused_existing_terms'] ) &&
			empty( $report['ambiguous_terms'] ) &&
			empty( $report['uncovered_content'] )
		) {
			WP_CLI::success( 'No significant gaps found. Taxonomy appears healthy.' );
		}
	}

	/**
	 * Find taxonomy terms with zero posts.
	 *
	 * ## OPTIONS
	 *
	 * [--taxonomies=<list>]
	 * : Comma-separated list of taxonomies to check.
	 * ---
	 * default: category,post_tag
	 * ---
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
	 *     wp taxonomy-audit unused-terms
	 *     wp taxonomy-audit unused-terms --taxonomies=category,topic
	 *     wp taxonomy-audit unused-terms --format=json
	 *
	 * @param array<string> $args       Positional arguments.
	 * @param array<string> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function unused_terms( array $args, array $assoc_args ): void {
		$taxonomies = array_map( 'trim', explode( ',', $assoc_args['taxonomies'] ?? 'category,post_tag' ) );
		$format = $assoc_args['format'] ?? 'table';

		// Validate taxonomies.
		$extractor = new TaxonomyExtractor();
		$validation = $extractor->validateTaxonomies( $taxonomies );

		if ( ! empty( $validation['invalid'] ) ) {
			WP_CLI::error( sprintf( 'Invalid taxonomies: %s', implode( ', ', $validation['invalid'] ) ) );
		}

		$analyzer = new GapAnalyzer( $extractor );
		$empty_terms = $analyzer->getEmptyTerms( $validation['valid'] );

		if ( empty( $empty_terms ) ) {
			WP_CLI::success( 'No unused terms found. All terms have at least one post.' );
			return;
		}

		WP_CLI::log( sprintf( 'Found %d terms with zero posts:', count( $empty_terms ) ) );
		WP_CLI::log( '' );

		WP_CLI\Utils\format_items( $format, $empty_terms, [ 'term', 'taxonomy', 'name', 'term_id' ] );
	}

	/**
	 * Find terms that don't semantically match analyzed content.
	 *
	 * Compares terms assigned to posts against LLM suggestions to find
	 * terms that may be misapplied or outdated.
	 *
	 * ## OPTIONS
	 *
	 * --suggestions=<path>
	 * : Path to suggestions JSON file from classify command.
	 *
	 * [--taxonomies=<list>]
	 * : Comma-separated list of taxonomies to check.
	 * ---
	 * default: category,post_tag
	 * ---
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
	 *     wp taxonomy-audit mismatched-terms --suggestions=output/suggestions.json
	 *     wp taxonomy-audit mismatched-terms --suggestions=output/suggestions.json --format=json
	 *
	 * @param array<string> $args       Positional arguments.
	 * @param array<string> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function mismatched_terms( array $args, array $assoc_args ): void {
		$suggestions_file = $assoc_args['suggestions'] ?? '';
		$taxonomies = array_map( 'trim', explode( ',', $assoc_args['taxonomies'] ?? 'category,post_tag' ) );
		$format = $assoc_args['format'] ?? 'table';

		if ( empty( $suggestions_file ) ) {
			WP_CLI::error( 'Please specify a suggestions file with --suggestions' );
		}

		if ( ! file_exists( $suggestions_file ) ) {
			WP_CLI::error( sprintf( 'File not found: %s', $suggestions_file ) );
		}

		// Load suggestions.
		$store = new SuggestionStore();
		$data = $store->load( $suggestions_file );
		$suggestions = $data['results'] ?? [];

		if ( empty( $suggestions ) ) {
			WP_CLI::error( 'No suggestions found in file.' );
		}

		// Collect term stats.
		$analyzer = new GapAnalyzer();
		$term_stats = [];

		foreach ( $taxonomies as $taxonomy ) {
			$term_stats[ $taxonomy ] = [];
		}

		foreach ( $suggestions as $result ) {
			if ( empty( $result['classifications'] ) ) {
				continue;
			}

			foreach ( $result['classifications'] as $taxonomy => $terms ) {
				if ( ! isset( $term_stats[ $taxonomy ] ) ) {
					continue;
				}

				foreach ( $terms as $term ) {
					$slug = $term['term'];
					if ( ! isset( $term_stats[ $taxonomy ][ $slug ] ) ) {
						$term_stats[ $taxonomy ][ $slug ] = [ 'count' => 0 ];
					}
					$term_stats[ $taxonomy ][ $slug ]['count']++;
				}
			}
		}

		$mismatched = $analyzer->findMismatchedTerms( $term_stats, $taxonomies );

		if ( empty( $mismatched ) ) {
			WP_CLI::success( 'No mismatched terms found.' );
			return;
		}

		WP_CLI::log( sprintf( 'Found %d terms with posts but never suggested by LLM:', count( $mismatched ) ) );
		WP_CLI::log( 'These terms may be outdated, misapplied, or need description updates.' );
		WP_CLI::log( '' );

		WP_CLI\Utils\format_items(
			$format,
			$mismatched,
			[ 'term', 'taxonomy', 'name', 'assigned_count', 'suggested_count' ]
		);
	}

	/**
	 * Generate shell script to prune unused taxonomy terms.
	 *
	 * Creates a safe deletion script with confirmation prompts.
	 *
	 * ## OPTIONS
	 *
	 * [--taxonomies=<list>]
	 * : Comma-separated list of taxonomies to check.
	 * ---
	 * default: category,post_tag
	 * ---
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
	 * [--no-confirm]
	 * : Skip confirmation prompts in generated script.
	 *
	 * ## EXAMPLES
	 *
	 *     wp taxonomy-audit generate-prune-script --output=prune-terms.sh
	 *     wp taxonomy-audit generate-prune-script --taxonomies=post_tag --prefix='wp'
	 *
	 * @param array<string> $args       Positional arguments.
	 * @param array<string> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function generate_prune_script( array $args, array $assoc_args ): void {
		$taxonomies = array_map( 'trim', explode( ',', $assoc_args['taxonomies'] ?? 'category,post_tag' ) );
		$output = $assoc_args['output'] ?? null;
		$prefix = $assoc_args['prefix'] ?? 'ddev wp';
		$no_confirm = isset( $assoc_args['no-confirm'] );

		// Get unused terms.
		$analyzer = new GapAnalyzer();
		$empty_terms = $analyzer->getEmptyTerms( $taxonomies );

		if ( empty( $empty_terms ) ) {
			WP_CLI::success( 'No unused terms found. Nothing to prune.' );
			return;
		}

		// Generate script.
		$lines = [
			'#!/bin/bash',
			'# AI Taxonomy Audit - Term Pruning Script',
			'# Generated: ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC',
			'# Command prefix: ' . $prefix,
			'#',
			'# WARNING: This script will DELETE taxonomy terms.',
			'# Review carefully before running.',
			'#',
			sprintf( '# Terms to delete: %d', count( $empty_terms ) ),
			'',
			'set -e',
			'',
		];

		if ( ! $no_confirm ) {
			$lines[] = 'echo "This script will delete ' . count( $empty_terms ) . ' unused taxonomy terms."';
			$lines[] = 'echo "Terms with zero posts:"';
			foreach ( array_slice( $empty_terms, 0, 10 ) as $term ) {
				$lines[] = sprintf( 'echo "  - %s (%s)"', $term['name'], $term['taxonomy'] );
			}
			if ( count( $empty_terms ) > 10 ) {
				$lines[] = sprintf( 'echo "  ... and %d more"', count( $empty_terms ) - 10 );
			}
			$lines[] = 'echo ""';
			$lines[] = 'read -p "Continue? (y/N) " confirm';
			$lines[] = 'if [[ "$confirm" != "y" && "$confirm" != "Y" ]]; then';
			$lines[] = '    echo "Aborted."';
			$lines[] = '    exit 1';
			$lines[] = 'fi';
			$lines[] = 'echo ""';
			$lines[] = '';
		}

		// Group by taxonomy.
		$by_taxonomy = [];
		foreach ( $empty_terms as $term ) {
			$by_taxonomy[ $term['taxonomy'] ][] = $term;
		}

		foreach ( $by_taxonomy as $taxonomy => $terms ) {
			$lines[] = sprintf( '# === %s (%d terms) ===', strtoupper( $taxonomy ), count( $terms ) );

			foreach ( $terms as $term ) {
				$lines[] = sprintf(
					'%s term delete %s %d # %s',
					$prefix,
					escapeshellarg( $taxonomy ),
					$term['term_id'],
					$term['name']
				);
			}

			$lines[] = '';
		}

		$lines[] = sprintf( 'echo "Completed: Deleted %d unused terms."', count( $empty_terms ) );

		$script = implode( "\n", $lines );

		if ( $output ) {
			file_put_contents( $output, $script );
			chmod( $output, 0755 );
			WP_CLI::success( sprintf( 'Prune script generated: %s', $output ) );
			WP_CLI::log( sprintf( 'Run with: bash %s', $output ) );
		} else {
			WP_CLI::log( $script );
		}
	}

	/**
	 * Compare classification results between different LLM providers.
	 *
	 * Runs classification on the same posts using multiple providers and
	 * outputs a comparison report to help calibrate confidence thresholds.
	 *
	 * ## OPTIONS
	 *
	 * --post-ids=<ids>
	 * : Comma-separated list of post IDs to compare.
	 *
	 * [--providers=<list>]
	 * : Comma-separated list of providers to compare.
	 * ---
	 * default: ollama,openai
	 * ---
	 *
	 * [--taxonomies=<list>]
	 * : Comma-separated list of taxonomies to classify against.
	 * ---
	 * default: category,post_tag
	 * ---
	 *
	 * [--output=<path>]
	 * : Save comparison report to JSON file.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp taxonomy-audit compare --post-ids=123,456,789 --providers=ollama,openai
	 *     wp taxonomy-audit compare --post-ids=123,456 --output=comparison.json
	 *
	 * @param array<string> $args       Positional arguments.
	 * @param array<string> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function compare( array $args, array $assoc_args ): void {
		$post_ids_raw = $assoc_args['post-ids'] ?? '';
		$providers = array_map( 'trim', explode( ',', $assoc_args['providers'] ?? 'ollama,openai' ) );
		$taxonomies = array_map( 'trim', explode( ',', $assoc_args['taxonomies'] ?? 'category,post_tag' ) );
		$output_file = $assoc_args['output'] ?? null;
		$format = $assoc_args['format'] ?? 'table';

		if ( empty( $post_ids_raw ) ) {
			WP_CLI::error( 'Please specify post IDs with --post-ids' );
		}

		$post_ids = array_map( 'intval', explode( ',', $post_ids_raw ) );

		// Validate taxonomies.
		$extractor = new TaxonomyExtractor();
		$validation = $extractor->validateTaxonomies( $taxonomies );

		if ( ! empty( $validation['invalid'] ) ) {
			WP_CLI::error( sprintf( 'Invalid taxonomies: %s', implode( ', ', $validation['invalid'] ) ) );
		}

		$taxonomies = $validation['valid'];

		WP_CLI::log( sprintf( 'Comparing %d posts across %d providers', count( $post_ids ), count( $providers ) ) );
		WP_CLI::log( sprintf( 'Providers: %s', implode( ', ', $providers ) ) );
		WP_CLI::log( sprintf( 'Taxonomies: %s', implode( ', ', $taxonomies ) ) );
		WP_CLI::log( '' );

		$results = [];

		foreach ( $providers as $provider ) {
			WP_CLI::log( sprintf( '--- Running %s ---', strtoupper( $provider ) ) );

			try {
				$client = $this->createClient( $provider, null );

				if ( ! $client->isAvailable() ) {
					WP_CLI::warning( sprintf( '%s is not available, skipping.', $provider ) );
					continue;
				}

				$classifier = new Classifier( $client, $extractor );
				$classifier->setMinConfidence( 0.0 ); // Get all results for comparison.

				$provider_results = [];

				foreach ( $post_ids as $post_id ) {
					$result = $classifier->classifyPost( $post_id, $taxonomies );
					$provider_results[ $post_id ] = $result;

					if ( $result['error'] ) {
						WP_CLI::warning( sprintf( 'Post %d: %s', $post_id, $result['error'] ) );
					}
				}

				$results[ $provider ] = $provider_results;
				WP_CLI::log( sprintf( 'Completed %d posts with %s', count( $post_ids ), $provider ) );

			} catch ( \Exception $e ) {
				WP_CLI::warning( sprintf( '%s error: %s', $provider, $e->getMessage() ) );
			}

			WP_CLI::log( '' );
		}

		if ( count( $results ) < 2 ) {
			WP_CLI::error( 'Need at least 2 providers with results to compare.' );
		}

		// Build comparison report.
		$comparison = $this->buildComparisonReport( $results, $post_ids, $taxonomies );

		if ( 'json' === $format || $output_file ) {
			$json = wp_json_encode( $comparison, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

			if ( $output_file ) {
				file_put_contents( $output_file, $json );
				WP_CLI::success( sprintf( 'Comparison report saved to: %s', $output_file ) );
			} else {
				WP_CLI::log( $json );
			}
			return;
		}

		// Table output.
		$this->outputComparisonTable( $comparison );
	}

	/**
	 * Build comparison report from provider results.
	 *
	 * @param array<string, array> $results    Results by provider.
	 * @param array<int>           $post_ids   Post IDs.
	 * @param array<string>        $taxonomies Taxonomies.
	 *
	 * @return array Comparison report.
	 */
	private function buildComparisonReport( array $results, array $post_ids, array $taxonomies ): array {
		$providers = array_keys( $results );
		$comparisons = [];
		$agreement_stats = [
			'total_posts'      => count( $post_ids ),
			'full_agreement'   => 0,
			'partial_agreement' => 0,
			'no_agreement'     => 0,
		];

		foreach ( $post_ids as $post_id ) {
			$post_comparison = [
				'post_id'    => $post_id,
				'post_title' => '',
				'providers'  => [],
				'agreement'  => [],
			];

			// Collect classifications from each provider.
			$all_terms = [];

			foreach ( $providers as $provider ) {
				if ( ! isset( $results[ $provider ][ $post_id ] ) ) {
					continue;
				}

				$result = $results[ $provider ][ $post_id ];
				$post_comparison['post_title'] = $result['post_title'] ?? '';

				$provider_terms = [];
				foreach ( $result['classifications'] ?? [] as $taxonomy => $terms ) {
					foreach ( $terms as $term ) {
						$key = $taxonomy . ':' . $term['term'];
						$provider_terms[ $key ] = $term['confidence'];
						$all_terms[ $key ][ $provider ] = $term['confidence'];
					}
				}

				$post_comparison['providers'][ $provider ] = [
					'terms'      => $provider_terms,
					'term_count' => count( $provider_terms ),
				];
			}

			// Calculate agreement for each term.
			$agreed_count = 0;
			$total_terms = count( $all_terms );

			foreach ( $all_terms as $term_key => $provider_confidences ) {
				$agreed_providers = array_keys( $provider_confidences );
				$is_agreed = count( $agreed_providers ) === count( $providers );

				$post_comparison['agreement'][ $term_key ] = [
					'providers'        => $agreed_providers,
					'confidences'      => $provider_confidences,
					'avg_confidence'   => array_sum( $provider_confidences ) / count( $provider_confidences ),
					'full_agreement'   => $is_agreed,
				];

				if ( $is_agreed ) {
					$agreed_count++;
				}
			}

			// Categorize agreement level.
			if ( $total_terms > 0 && $agreed_count === $total_terms ) {
				$agreement_stats['full_agreement']++;
			} elseif ( $agreed_count > 0 ) {
				$agreement_stats['partial_agreement']++;
			} else {
				$agreement_stats['no_agreement']++;
			}

			$comparisons[] = $post_comparison;
		}

		return [
			'generated_at' => gmdate( 'c' ),
			'providers'    => $providers,
			'taxonomies'   => $taxonomies,
			'summary'      => $agreement_stats,
			'comparisons'  => $comparisons,
		];
	}

	/**
	 * Output comparison report as table.
	 *
	 * @param array $comparison Comparison report.
	 *
	 * @return void
	 */
	private function outputComparisonTable( array $comparison ): void {
		$providers = $comparison['providers'];

		WP_CLI::log( '=== Provider Comparison Summary ===' );
		WP_CLI::log( sprintf( 'Providers compared: %s', implode( ', ', $providers ) ) );
		WP_CLI::log( sprintf( 'Posts analyzed: %d', $comparison['summary']['total_posts'] ) );
		WP_CLI::log( sprintf( 'Full agreement: %d posts', $comparison['summary']['full_agreement'] ) );
		WP_CLI::log( sprintf( 'Partial agreement: %d posts', $comparison['summary']['partial_agreement'] ) );
		WP_CLI::log( sprintf( 'No agreement: %d posts', $comparison['summary']['no_agreement'] ) );
		WP_CLI::log( '' );

		WP_CLI::log( '=== Per-Post Comparison ===' );

		foreach ( $comparison['comparisons'] as $post ) {
			WP_CLI::log( sprintf( '--- Post %d: %s ---', $post['post_id'], $post['post_title'] ) );

			foreach ( $providers as $provider ) {
				if ( ! isset( $post['providers'][ $provider ] ) ) {
					WP_CLI::log( sprintf( '  %s: (no results)', $provider ) );
					continue;
				}

				$terms = $post['providers'][ $provider ]['terms'];
				if ( empty( $terms ) ) {
					WP_CLI::log( sprintf( '  %s: (no suggestions)', $provider ) );
				} else {
					$term_list = [];
					foreach ( $terms as $term_key => $confidence ) {
						$term_list[] = sprintf( '%s (%.0f%%)', $term_key, $confidence * 100 );
					}
					WP_CLI::log( sprintf( '  %s: %s', $provider, implode( ', ', $term_list ) ) );
				}
			}

			// Show agreement.
			$agreed_terms = array_filter( $post['agreement'], fn( $a ) => $a['full_agreement'] );
			$disagreed_terms = array_filter( $post['agreement'], fn( $a ) => ! $a['full_agreement'] );

			if ( ! empty( $agreed_terms ) ) {
				WP_CLI::log( WP_CLI::colorize( '  %GAgreed:%n ' . implode( ', ', array_keys( $agreed_terms ) ) ) );
			}
			if ( ! empty( $disagreed_terms ) ) {
				WP_CLI::log( WP_CLI::colorize( '  %YDisagreed:%n ' . implode( ', ', array_keys( $disagreed_terms ) ) ) );
			}

			WP_CLI::log( '' );
		}
	}

	/**
	 * List all analysis runs.
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
	 * [--limit=<number>]
	 * : Maximum number of runs to show.
	 * ---
	 * default: 20
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp taxonomy-audit runs list
	 *     wp taxonomy-audit runs list --format=json
	 *
	 * @subcommand runs-list
	 *
	 * @param array<string> $args       Positional arguments.
	 * @param array<string> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function runs_list( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'table';
		$limit = (int) ( $assoc_args['limit'] ?? 20 );

		$storage = new RunStorage();
		$runs = $storage->listRuns();

		if ( empty( $runs ) ) {
			WP_CLI::log( 'No runs found.' );
			WP_CLI::log( sprintf( 'Runs directory: %s', $storage->getRunsDir() ) );
			return;
		}

		// Limit results.
		$runs = array_slice( $runs, 0, $limit );

		// Flatten for table output.
		$items = array_map( function ( $run ) {
			return [
				'id'         => $run['id'],
				'status'     => $run['status'],
				'created_at' => $run['created_at'],
				'provider'   => $run['config']['provider'] ?? '-',
				'post_type'  => $run['config']['post_type'] ?? '-',
				'files'      => count( $run['files'] ),
			];
		}, $runs );

		WP_CLI\Utils\format_items( $format, $items, [ 'id', 'status', 'created_at', 'provider', 'post_type', 'files' ] );
	}

	/**
	 * Show details of a specific run.
	 *
	 * ## OPTIONS
	 *
	 * <run-id>
	 * : The run ID to show.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: yaml
	 * options:
	 *   - yaml
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp taxonomy-audit runs show 2025-01-28T103000
	 *     wp taxonomy-audit runs show 2025-01-28T103000 --format=json
	 *
	 * @subcommand runs-show
	 *
	 * @param array<string> $args       Positional arguments.
	 * @param array<string> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function runs_show( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Please specify a run ID.' );
		}

		$run_id = $args[0];
		$format = $assoc_args['format'] ?? 'yaml';

		$storage = new RunStorage();

		try {
			$run = $storage->getRun( $run_id );
		} catch ( \RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		// YAML-like output.
		WP_CLI::log( sprintf( '=== Run: %s ===', $run['id'] ) );
		WP_CLI::log( sprintf( 'Status: %s', $run['status'] ) );
		WP_CLI::log( sprintf( 'Created: %s', $run['created_at'] ) );

		if ( ! empty( $run['notes'] ) ) {
			WP_CLI::log( sprintf( 'Notes: %s', $run['notes'] ) );
		}

		WP_CLI::log( '' );
		WP_CLI::log( '--- Config ---' );
		foreach ( $run['config'] as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = implode( ', ', $value );
			}
			WP_CLI::log( sprintf( '  %s: %s', $key, $value ) );
		}

		WP_CLI::log( '' );
		WP_CLI::log( '--- Files ---' );
		foreach ( $run['files'] as $file ) {
			WP_CLI::log( sprintf( '  - %s', $file ) );
		}

		if ( ! empty( $run['summary'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( '--- Summary ---' );
			foreach ( $run['summary'] as $key => $value ) {
				if ( is_array( $value ) ) {
					$value = wp_json_encode( $value );
				}
				WP_CLI::log( sprintf( '  %s: %s', $key, $value ) );
			}
		}

		if ( ! empty( $run['error'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( WP_CLI::colorize( '%RError:%n ' . $run['error'] ) );
		}
	}

	/**
	 * Compare two analysis runs.
	 *
	 * ## OPTIONS
	 *
	 * <run-id-a>
	 * : First run ID.
	 *
	 * <run-id-b>
	 * : Second run ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp taxonomy-audit runs compare 2025-01-27T140000 2025-01-28T103000
	 *     wp taxonomy-audit runs compare 2025-01-27T140000 2025-01-28T103000 --format=json
	 *
	 * @subcommand runs-compare
	 *
	 * @param array<string> $args       Positional arguments.
	 * @param array<string> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function runs_compare( array $args, array $assoc_args ): void {
		if ( count( $args ) < 2 ) {
			WP_CLI::error( 'Please specify two run IDs to compare.' );
		}

		$run_id_a = $args[0];
		$run_id_b = $args[1];
		$format = $assoc_args['format'] ?? 'table';

		$storage = new RunStorage();

		try {
			$comparison = $storage->compareRuns( $run_id_a, $run_id_b );
		} catch ( \RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $comparison, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		// Table output.
		WP_CLI::log( sprintf( '=== Comparing Runs ===' ) );
		WP_CLI::log( sprintf( 'Run A: %s', $run_id_a ) );
		WP_CLI::log( sprintf( 'Run B: %s', $run_id_b ) );
		WP_CLI::log( '' );

		// Config differences.
		if ( ! empty( $comparison['config'] ) ) {
			WP_CLI::log( '--- Config Differences ---' );
			foreach ( $comparison['config'] as $key => $diff ) {
				$val_a = is_array( $diff['a'] ) ? wp_json_encode( $diff['a'] ) : ( $diff['a'] ?? '(none)' );
				$val_b = is_array( $diff['b'] ) ? wp_json_encode( $diff['b'] ) : ( $diff['b'] ?? '(none)' );
				WP_CLI::log( sprintf( '  %s: %s → %s', $key, $val_a, $val_b ) );
			}
			WP_CLI::log( '' );
		}

		// Summary differences.
		if ( ! empty( $comparison['summary'] ) ) {
			WP_CLI::log( '--- Summary Differences ---' );
			foreach ( $comparison['summary'] as $key => $diff ) {
				$val_a = is_array( $diff['a'] ) ? wp_json_encode( $diff['a'] ) : ( $diff['a'] ?? '0' );
				$val_b = is_array( $diff['b'] ) ? wp_json_encode( $diff['b'] ) : ( $diff['b'] ?? '0' );
				WP_CLI::log( sprintf( '  %s: %s → %s', $key, $val_a, $val_b ) );
			}
			WP_CLI::log( '' );
		}

		// File differences.
		WP_CLI::log( '--- Files ---' );
		if ( ! empty( $comparison['files']['only_in_a'] ) ) {
			WP_CLI::log( sprintf( '  Only in A: %s', implode( ', ', $comparison['files']['only_in_a'] ) ) );
		}
		if ( ! empty( $comparison['files']['only_in_b'] ) ) {
			WP_CLI::log( sprintf( '  Only in B: %s', implode( ', ', $comparison['files']['only_in_b'] ) ) );
		}
		if ( ! empty( $comparison['files']['in_both'] ) ) {
			WP_CLI::log( sprintf( '  In both: %s', implode( ', ', $comparison['files']['in_both'] ) ) );
		}

		// Suggestions comparison.
		if ( ! empty( $comparison['suggestions'] ) ) {
			$sugg = $comparison['suggestions'];
			WP_CLI::log( '' );
			WP_CLI::log( '--- Suggestions Comparison ---' );
			WP_CLI::log( sprintf( '  Posts only in A: %d', count( $sugg['posts_only_in_a'] ) ) );
			WP_CLI::log( sprintf( '  Posts only in B: %d', count( $sugg['posts_only_in_b'] ) ) );
			WP_CLI::log( sprintf( '  Posts in both: %d', $sugg['posts_in_both'] ) );
			WP_CLI::log( sprintf( '  Posts with changed suggestions: %d', count( $sugg['posts_changed'] ) ) );
			WP_CLI::log( sprintf( '  Posts unchanged: %d', $sugg['posts_unchanged'] ) );
		}
	}

	/**
	 * Delete an analysis run.
	 *
	 * ## OPTIONS
	 *
	 * <run-id>
	 * : The run ID to delete.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp taxonomy-audit runs delete 2025-01-28T103000
	 *     wp taxonomy-audit runs delete 2025-01-28T103000 --yes
	 *
	 * @subcommand runs-delete
	 *
	 * @param array<string> $args       Positional arguments.
	 * @param array<string> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function runs_delete( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Please specify a run ID.' );
		}

		$run_id = $args[0];
		$skip_confirm = isset( $assoc_args['yes'] );

		$storage = new RunStorage();

		// Verify run exists.
		try {
			$run = $storage->getRun( $run_id );
		} catch ( \RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		// Confirm deletion.
		if ( ! $skip_confirm ) {
			WP_CLI::log( sprintf( 'Run: %s', $run_id ) );
			WP_CLI::log( sprintf( 'Created: %s', $run['created_at'] ) );
			WP_CLI::log( sprintf( 'Files: %s', implode( ', ', $run['files'] ) ) );
			WP_CLI::log( '' );

			WP_CLI::confirm( 'Are you sure you want to delete this run?' );
		}

		if ( $storage->deleteRun( $run_id ) ) {
			WP_CLI::success( sprintf( 'Run %s deleted.', $run_id ) );
		} else {
			WP_CLI::error( 'Failed to delete run.' );
		}
	}
}
