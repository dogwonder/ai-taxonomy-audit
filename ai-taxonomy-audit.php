<?php
/**
 * Plugin Name: AI Taxonomy Audit
 * Plugin URI: https://dgw.ltd
 * Description: WP-CLI tool for human-in-the-loop taxonomy enrichment using LLM (Ollama, OpenAI, or OpenRouter).
 * Version: 1.0.0
 * Author: DGW Ltd
 * Author URI: https://dgw.ltd
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: ai-taxonomy-audit
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 *
 * @package DGWTaxonomyAudit
 */

declare(strict_types=1);

namespace DGWTaxonomyAudit;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'DGW_TAXONOMY_AUDIT_VERSION', '1.0.0' );
define( 'DGW_TAXONOMY_AUDIT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DGW_TAXONOMY_AUDIT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Autoloader.
if ( file_exists( DGW_TAXONOMY_AUDIT_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once DGW_TAXONOMY_AUDIT_PLUGIN_DIR . 'vendor/autoload.php';
}

/**
 * Register WP-CLI commands if WP-CLI is available.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'taxonomy-audit', CLI\Commands::class );
}

/**
 * Get plugin configuration.
 *
 * @return array<string, mixed>
 */
function get_config(): array {
	static $config = null;

	if ( null === $config ) {
		$config = [
			'ollama' => [
				'base_uri'    => defined( 'DGW_OLLAMA_BASE_URI' ) ? DGW_OLLAMA_BASE_URI : 'http://localhost:11434',
				'model'       => defined( 'DGW_OLLAMA_MODEL' ) ? DGW_OLLAMA_MODEL : 'qwen2.5:latest',
				'temperature' => 0.3,
				'timeout'     => 120,
			],
			'openai' => [
				'model'       => defined( 'DGW_OPENAI_MODEL' ) ? DGW_OPENAI_MODEL : 'gpt-4o-mini',
				'temperature' => 0.3,
				'timeout'     => 120,
			],
			'openrouter' => [
				'model'       => defined( 'DGW_OPENROUTER_MODEL' ) ? DGW_OPENROUTER_MODEL : 'google/gemma-2-9b-it:free',
				'temperature' => 0.3,
				'timeout'     => 120,
			],
			'classification' => [
				'max_content_length'       => 1500,
				'max_terms_per_taxonomy'   => 5,
				'min_confidence_threshold' => 0.7,
				'include_existing_terms'   => true,
			],
			'output' => [
				'format'            => 'csv',
				'include_rationale' => true,
				'generate_script'   => true,
				'prefix'            => 'ddev wp',
			],
		];

		// Allow filtering.
		$config = apply_filters( 'dgw_taxonomy_audit_config', $config );
	}

	return $config;
}

/**
 * Get output directory path.
 *
 * @return string
 */
function get_output_dir(): string {
	$dir = DGW_TAXONOMY_AUDIT_PLUGIN_DIR . 'output/';

	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}

	return $dir;
}
