<?php
/**
 * Export Config Loader
 *
 * Loads wp-to-file export profiles configuration for field mapping.
 * This allows ai-taxonomy-audit to use the same content field definitions
 * as wp-to-file for consistency across the pipeline.
 *
 * @package DGWTaxonomyAudit\Core
 */

declare(strict_types=1);

namespace DGWTaxonomyAudit\Core;

/**
 * Loads and parses wp-to-file export profiles configuration.
 */
class ExportConfigLoader {

	/**
	 * Cached configuration data.
	 *
	 * @var array|null
	 */
	private static ?array $config = null;

	/**
	 * Path to config file.
	 *
	 * @var string|null
	 */
	private static ?string $config_path = null;

	/**
	 * Get post type configuration.
	 *
	 * @param string $post_type Post type name.
	 *
	 * @return array{
	 *     exclude_content?: bool,
	 *     acf_fields?: array{content?: array<string>, frontmatter?: array<string>},
	 *     taxonomies?: array<string>
	 * }
	 */
	public static function getPostTypeConfig( string $post_type ): array {
		$config = self::loadConfig();

		return $config['post_type_configs'][ $post_type ] ?? [];
	}

	/**
	 * Get ACF content fields for a post type.
	 *
	 * @param string $post_type Post type name.
	 *
	 * @return array<string> List of ACF field keys to use as content.
	 */
	public static function getContentFields( string $post_type ): array {
		$config = self::getPostTypeConfig( $post_type );

		return $config['acf_fields']['content'] ?? [];
	}

	/**
	 * Check if post_content should be excluded for a post type.
	 *
	 * @param string $post_type Post type name.
	 *
	 * @return bool True if post_content should be excluded.
	 */
	public static function shouldExcludeContent( string $post_type ): bool {
		$config = self::getPostTypeConfig( $post_type );

		return ! empty( $config['exclude_content'] );
	}

	/**
	 * Set custom config path (for testing or alternative configs).
	 *
	 * @param string $path Path to config file.
	 *
	 * @return void
	 */
	public static function setConfigPath( string $path ): void {
		self::$config_path = $path;
		self::$config      = null; // Clear cache.
	}

	/**
	 * Clear cached configuration.
	 *
	 * @return void
	 */
	public static function clearCache(): void {
		self::$config = null;
	}

	/**
	 * Load configuration from YAML file.
	 *
	 * @return array Configuration data.
	 */
	private static function loadConfig(): array {
		if ( null !== self::$config ) {
			return self::$config;
		}

		$config_file = self::findConfigFile();

		if ( null === $config_file || ! file_exists( $config_file ) ) {
			self::$config = [];
			return self::$config;
		}

		$content = file_get_contents( $config_file );
		if ( false === $content ) {
			self::$config = [];
			return self::$config;
		}

		// Use PHP yaml extension if available, otherwise use simple parser.
		if ( function_exists( 'yaml_parse' ) ) {
			self::$config = yaml_parse( $content ) ?: [];
		} else {
			self::$config = self::parseYAML( $content );
		}

		return self::$config;
	}

	/**
	 * Find the wp-to-file config file.
	 *
	 * @return string|null Path to config file or null if not found.
	 */
	private static function findConfigFile(): ?string {
		// Use custom path if set.
		if ( null !== self::$config_path ) {
			return self::$config_path;
		}

		// Check common locations for wp-to-file config.
		$possible_paths = [
			// MU-plugins location.
			WPMU_PLUGIN_DIR . '/wp-to-file/config/export-profiles.yaml',
			// Regular plugins location.
			WP_PLUGIN_DIR . '/wp-to-file/config/export-profiles.yaml',
			// Content directory root.
			WP_CONTENT_DIR . '/config/export-profiles.yaml',
		];

		foreach ( $possible_paths as $path ) {
			if ( file_exists( $path ) ) {
				return $path;
			}
		}

		return null;
	}

	/**
	 * Simple YAML parser for basic structures.
	 *
	 * Handles the YAML structures used in export-profiles.yaml.
	 * For complex YAML, install the PHP yaml extension.
	 *
	 * @param string $content YAML content.
	 *
	 * @return array Parsed data.
	 */
	private static function parseYAML( string $content ): array {
		$lines         = explode( "\n", $content );
		$result        = [];
		$current_path  = [];
		$current_indent = 0;

		foreach ( $lines as $line ) {
			// Skip comments and empty lines.
			$trimmed = trim( $line );
			if ( empty( $trimmed ) || strpos( $trimmed, '#' ) === 0 ) {
				continue;
			}

			$indent = strlen( $line ) - strlen( ltrim( $line ) );

			// Handle indent changes - going back up the tree.
			if ( $indent < $current_indent ) {
				$levels_back = (int) ( ( $current_indent - $indent ) / 2 );
				for ( $i = 0; $i < $levels_back; $i++ ) {
					array_pop( $current_path );
				}
			}
			$current_indent = $indent;

			// Parse key-value pairs.
			if ( strpos( $trimmed, ':' ) !== false ) {
				$parts = explode( ':', $trimmed, 2 );
				$key   = trim( $parts[0] );
				$value = isset( $parts[1] ) ? trim( $parts[1] ) : '';

				if ( empty( $value ) ) {
					// Parent key - add to path.
					$current_path[] = $key;
					self::setNestedValue( $result, $current_path, [] );
				} else {
					// Key-value pair.
					$full_path    = array_merge( $current_path, [ $key ] );
					$parsed_value = self::parseValue( $value );
					self::setNestedValue( $result, $full_path, $parsed_value );
				}
			} elseif ( strpos( $trimmed, '- ' ) === 0 ) {
				// Array item.
				$value        = substr( $trimmed, 2 );
				$parsed_value = self::parseValue( $value );

				$current = &self::getNestedRef( $result, $current_path );
				if ( ! is_array( $current ) ) {
					$current = [];
				}
				$current[] = $parsed_value;
			}
		}

		return $result;
	}

	/**
	 * Parse a YAML value.
	 *
	 * @param string $value Raw value string.
	 *
	 * @return mixed Parsed value.
	 */
	private static function parseValue( string $value ): mixed {
		// Handle inline arrays: [item1, item2].
		if ( strpos( $value, '[' ) === 0 && substr( $value, -1 ) === ']' ) {
			$inner = substr( $value, 1, -1 );
			return array_map( 'trim', explode( ',', $inner ) );
		}

		// Handle booleans.
		$lower = strtolower( $value );
		if ( in_array( $lower, [ 'true', 'yes', 'on' ], true ) ) {
			return true;
		}
		if ( in_array( $lower, [ 'false', 'no', 'off' ], true ) ) {
			return false;
		}

		// Handle numbers.
		if ( is_numeric( $value ) ) {
			return strpos( $value, '.' ) !== false ? (float) $value : (int) $value;
		}

		// Handle quoted strings.
		if ( ( strpos( $value, '"' ) === 0 && substr( $value, -1 ) === '"' ) ||
			( strpos( $value, "'" ) === 0 && substr( $value, -1 ) === "'" ) ) {
			return substr( $value, 1, -1 );
		}

		return $value;
	}

	/**
	 * Set a nested array value.
	 *
	 * @param array $array Target array.
	 * @param array $path  Path to value.
	 * @param mixed $value Value to set.
	 *
	 * @return void
	 */
	private static function setNestedValue( array &$array, array $path, mixed $value ): void {
		$current = &$array;

		foreach ( $path as $key ) {
			if ( ! isset( $current[ $key ] ) || ! is_array( $current[ $key ] ) ) {
				$current[ $key ] = [];
			}
			$current = &$current[ $key ];
		}

		if ( is_array( $value ) && is_array( $current ) ) {
			$current = array_merge( $current, $value );
		} else {
			$current = $value;
		}
	}

	/**
	 * Get reference to nested array value.
	 *
	 * @param array $array Source array.
	 * @param array $path  Path to value.
	 *
	 * @return mixed Reference to value.
	 */
	private static function &getNestedRef( array &$array, array $path ): mixed {
		$current = &$array;

		foreach ( $path as $key ) {
			if ( ! isset( $current[ $key ] ) ) {
				$current[ $key ] = [];
			}
			$current = &$current[ $key ];
		}

		return $current;
	}
}
