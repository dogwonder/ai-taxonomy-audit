<?php
/**
 * Script Generator
 *
 * @package DGWTaxonomyAudit\Output
 */

declare(strict_types=1);

namespace DGWTaxonomyAudit\Output;

/**
 * Generates shell scripts with WP-CLI commands for applying taxonomy terms.
 */
class ScriptGenerator {

	/**
	 * Command prefix (e.g., "ddev wp" or "wp").
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Output directory.
	 *
	 * @var string
	 */
	private string $output_dir;

	/**
	 * Constructor.
	 *
	 * @param string      $prefix     Command prefix.
	 * @param string|null $output_dir Output directory.
	 */
	public function __construct( string $prefix = 'ddev wp', ?string $output_dir = null ) {
		$this->prefix = $prefix;
		$this->output_dir = $output_dir ?? \DGWTaxonomyAudit\get_output_dir();
	}

	/**
	 * Generate shell script from classification results.
	 *
	 * @param array<array{post_id: int, post_title: string, classifications: array}> $results Results.
	 * @param string|null                                                            $file    Output file path.
	 *
	 * @return string File path or script content.
	 */
	public function generate( array $results, ?string $file = null ): string {
		$script = $this->buildScript( $results );

		if ( $file ) {
			$this->writeToFile( $file, $script );
			return $file;
		}

		return $script;
	}

	/**
	 * Generate script from CSV suggestions.
	 *
	 * @param array<array{post_id: int, taxonomy: string, term: string}> $suggestions Suggestions.
	 * @param string|null                                                $file        Output file path.
	 *
	 * @return string File path or script content.
	 */
	public function generateFromSuggestions( array $suggestions, ?string $file = null ): string {
		$csv_handler = new CSVHandler();
		$grouped = $csv_handler->groupByPost( $suggestions );

		// Convert to results format.
		$results = [];
		foreach ( $grouped as $post_data ) {
			$classifications = [];
			foreach ( $post_data['taxonomies'] as $taxonomy => $terms ) {
				$classifications[ $taxonomy ] = array_map(
					fn( $term ) => [ 'term' => $term ],
					$terms
				);
			}

			$results[] = [
				'post_id'         => $post_data['post_id'],
				'post_title'      => $post_data['post_title'],
				'classifications' => $classifications,
			];
		}

		return $this->generate( $results, $file );
	}

	/**
	 * Build shell script content.
	 *
	 * Only generates commands for confirmed terms (in_vocabulary: true or unset).
	 * Suggested new terms (in_vocabulary: false) are listed as comments at the end.
	 *
	 * @param array<array{post_id: int, post_title: string, classifications: array}> $results Results.
	 *
	 * @return string Script content.
	 */
	private function buildScript( array $results ): string {
		$lines = [
			'#!/bin/bash',
			'#',
			'# AI Taxonomy Audit - Term Assignment Script',
			sprintf( '# Generated: %s', gmdate( 'Y-m-d H:i:s' ) . ' UTC' ),
			sprintf( '# Command prefix: %s', $this->prefix ),
			'#',
			'# Review each command before running.',
			'# Use --dry-run to preview changes without applying them.',
			'#',
			'',
			'set -e  # Exit on error',
			'',
		];

		$command_count = 0;
		$suggested_terms = []; // Track suggested new terms.

		foreach ( $results as $result ) {
			if ( empty( $result['classifications'] ) ) {
				continue;
			}

			$post_has_commands = false;

			foreach ( $result['classifications'] as $taxonomy => $terms ) {
				foreach ( $terms as $term_data ) {
					$term = $term_data['term'];
					$in_vocabulary = $term_data['in_vocabulary'] ?? true; // Default to true for backwards compat.

					if ( $in_vocabulary === false ) {
						// Collect suggested new terms.
						$suggested_terms[] = [
							'post_id'    => $result['post_id'],
							'post_title' => $result['post_title'],
							'taxonomy'   => $taxonomy,
							'term'       => $term,
							'reason'     => $term_data['reason'] ?? '',
						];
						continue;
					}

					// Only add comment header once per post.
					if ( ! $post_has_commands ) {
						$lines[] = sprintf(
							'# Post: %s (ID: %d)',
							$this->escapeComment( $result['post_title'] ),
							$result['post_id']
						);
						$post_has_commands = true;
					}

					$lines[] = $this->buildCommand( $result['post_id'], $taxonomy, $term );
					$command_count++;
				}
			}

			if ( $post_has_commands ) {
				$lines[] = '';
			}
		}

		$lines[] = sprintf( 'echo "Completed: %d term assignments"', $command_count );

		// Add suggested new terms as comments at the end.
		if ( ! empty( $suggested_terms ) ) {
			$lines[] = '';
			$lines[] = '#';
			$lines[] = '# ═══════════════════════════════════════════════════════════════════════════';
			$lines[] = '# SUGGESTED NEW TERMS (require manual creation before applying)';
			$lines[] = '# ═══════════════════════════════════════════════════════════════════════════';
			$lines[] = '#';
			$lines[] = '# The following terms were suggested by the AI but do not exist in the';
			$lines[] = '# vocabulary. Review these suggestions and create terms if appropriate:';
			$lines[] = '#';

			// Group by taxonomy.
			$by_taxonomy = [];
			foreach ( $suggested_terms as $suggestion ) {
				$taxonomy = $suggestion['taxonomy'];
				if ( ! isset( $by_taxonomy[ $taxonomy ] ) ) {
					$by_taxonomy[ $taxonomy ] = [];
				}
				$by_taxonomy[ $taxonomy ][] = $suggestion;
			}

			foreach ( $by_taxonomy as $taxonomy => $suggestions ) {
				$lines[] = sprintf( '# %s:', strtoupper( $taxonomy ) );

				// Dedupe by term.
				$seen = [];
				foreach ( $suggestions as $suggestion ) {
					$term = $suggestion['term'];
					if ( isset( $seen[ $term ] ) ) {
						continue;
					}
					$seen[ $term ] = true;

					$lines[] = sprintf(
						'#   - %s',
						$term
					);
					if ( ! empty( $suggestion['reason'] ) ) {
						$lines[] = sprintf(
							'#     Reason: %s',
							$this->escapeComment( $suggestion['reason'] )
						);
					}
				}
				$lines[] = '#';
			}

			$lines[] = '# To create a new term:';
			$lines[] = sprintf( '# %s term create <taxonomy> <term-slug> --slug=<term-slug>', $this->prefix );
			$lines[] = '#';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Build a single WP-CLI command.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy name.
	 * @param string $term     Term slug.
	 *
	 * @return string Command string.
	 */
	private function buildCommand( int $post_id, string $taxonomy, string $term ): string {
		return sprintf(
			'%s post term add %d %s %s',
			$this->prefix,
			$post_id,
			escapeshellarg( $taxonomy ),
			escapeshellarg( $term )
		);
	}

	/**
	 * Write script to file.
	 *
	 * @param string $file    File path.
	 * @param string $content Script content.
	 *
	 * @return void
	 */
	private function writeToFile( string $file, string $content ): void {
		if ( false === file_put_contents( $file, $content ) ) {
			throw new \RuntimeException( "Failed to write file: {$file}" );
		}

		// Make executable.
		chmod( $file, 0755 );
	}

	/**
	 * Escape string for shell comment.
	 *
	 * @param string $text Text to escape.
	 *
	 * @return string Escaped text.
	 */
	private function escapeComment( string $text ): string {
		// Remove newlines and limit length.
		$text = str_replace( [ "\n", "\r" ], ' ', $text );
		$text = substr( $text, 0, 80 );

		return $text;
	}

	/**
	 * Generate commands for terminal display (copyable).
	 *
	 * Only generates commands for confirmed terms (in_vocabulary: true or unset).
	 *
	 * @param array<array{post_id: int, post_title: string, classifications: array}> $results Results.
	 *
	 * @return array<string> Commands.
	 */
	public function generateCommands( array $results ): array {
		$commands = [];

		foreach ( $results as $result ) {
			if ( empty( $result['classifications'] ) ) {
				continue;
			}

			foreach ( $result['classifications'] as $taxonomy => $terms ) {
				foreach ( $terms as $term_data ) {
					$in_vocabulary = $term_data['in_vocabulary'] ?? true;
					if ( $in_vocabulary === false ) {
						continue; // Skip suggested new terms.
					}

					$commands[] = $this->buildCommand(
						$result['post_id'],
						$taxonomy,
						$term_data['term']
					);
				}
			}
		}

		return $commands;
	}

	/**
	 * Generate output filename.
	 *
	 * @param string $prefix File prefix.
	 *
	 * @return string Filename.
	 */
	public function generateFilename( string $prefix = 'set-terms' ): string {
		return sprintf( '%s-%s.sh', $prefix, gmdate( 'Y-m-d-His' ) );
	}

	/**
	 * Set command prefix.
	 *
	 * @param string $prefix Prefix (e.g., "ddev wp", "wp", "lando wp").
	 *
	 * @return self
	 */
	public function setPrefix( string $prefix ): self {
		$this->prefix = $prefix;
		return $this;
	}

	/**
	 * Get command prefix.
	 *
	 * @return string
	 */
	public function getPrefix(): string {
		return $this->prefix;
	}

	/**
	 * Count total commands that would be generated.
	 *
	 * Only counts confirmed terms (in_vocabulary: true or unset).
	 *
	 * @param array<array{classifications: array}> $results Results.
	 *
	 * @return int Command count.
	 */
	public function countCommands( array $results ): int {
		$count = 0;

		foreach ( $results as $result ) {
			if ( empty( $result['classifications'] ) ) {
				continue;
			}

			foreach ( $result['classifications'] as $terms ) {
				foreach ( $terms as $term_data ) {
					$in_vocabulary = $term_data['in_vocabulary'] ?? true;
					if ( $in_vocabulary !== false ) {
						$count++;
					}
				}
			}
		}

		return $count;
	}

	/**
	 * Generate dry-run script (shows what would be done).
	 *
	 * @param array<array{post_id: int, post_title: string, classifications: array}> $results Results.
	 *
	 * @return string Script with echo statements.
	 */
	public function generateDryRun( array $results ): string {
		$lines = [
			'#!/bin/bash',
			'#',
			'# AI Taxonomy Audit - DRY RUN',
			sprintf( '# Generated: %s', gmdate( 'Y-m-d H:i:s' ) . ' UTC' ),
			'#',
			'# This shows what commands WOULD be run.',
			'#',
			'',
		];

		$suggested_terms = [];

		foreach ( $results as $result ) {
			if ( empty( $result['classifications'] ) ) {
				continue;
			}

			$post_has_commands = false;

			foreach ( $result['classifications'] as $taxonomy => $terms ) {
				foreach ( $terms as $term_data ) {
					$in_vocabulary = $term_data['in_vocabulary'] ?? true;

					if ( $in_vocabulary === false ) {
						$suggested_terms[] = [
							'taxonomy' => $taxonomy,
							'term'     => $term_data['term'],
						];
						continue;
					}

					if ( ! $post_has_commands ) {
						$lines[] = sprintf(
							'echo "Post: %s (ID: %d)"',
							addslashes( $this->escapeComment( $result['post_title'] ) ),
							$result['post_id']
						);
						$post_has_commands = true;
					}

					$command = $this->buildCommand( $result['post_id'], $taxonomy, $term_data['term'] );
					$lines[] = sprintf( 'echo "  Would run: %s"', addslashes( $command ) );
				}
			}

			if ( $post_has_commands ) {
				$lines[] = '';
			}
		}

		// Show suggested new terms.
		if ( ! empty( $suggested_terms ) ) {
			$lines[] = 'echo ""';
			$lines[] = 'echo "Suggested new terms (not in vocabulary):"';
			$unique = [];
			foreach ( $suggested_terms as $s ) {
				$key = $s['taxonomy'] . ':' . $s['term'];
				if ( ! isset( $unique[ $key ] ) ) {
					$unique[ $key ] = $s;
					$lines[] = sprintf(
						'echo "  - %s: %s"',
						addslashes( $s['taxonomy'] ),
						addslashes( $s['term'] )
					);
				}
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Count suggested new terms in results.
	 *
	 * @param array<array{classifications: array}> $results Results.
	 *
	 * @return int Count of suggested new terms.
	 */
	public function countSuggestedTerms( array $results ): int {
		$count = 0;

		foreach ( $results as $result ) {
			if ( empty( $result['classifications'] ) ) {
				continue;
			}

			foreach ( $result['classifications'] as $terms ) {
				foreach ( $terms as $term_data ) {
					if ( isset( $term_data['in_vocabulary'] ) && $term_data['in_vocabulary'] === false ) {
						$count++;
					}
				}
			}
		}

		return $count;
	}
}
