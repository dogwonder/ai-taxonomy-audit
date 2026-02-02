<?php
/**
 * SKOS Turtle Parser
 *
 * Parses SKOS Turtle files from wp-to-file-graph output.
 * This is a lightweight parser for wp-to-file-graph output only, not a full RDF parser.
 *
 * @package DGWTaxonomyAudit\Core
 */

declare(strict_types=1);

namespace DGWTaxonomyAudit\Core;

/**
 * Parses SKOS Turtle files and extracts concept hierarchy.
 */
class SKOSParser {

	/**
	 * Known prefixes from Turtle file.
	 *
	 * @var array<string, string>
	 */
	private array $prefixes = [];

	/**
	 * Parse a SKOS Turtle file and extract concepts with hierarchy.
	 *
	 * @param string $filepath Path to .ttl file.
	 *
	 * @return array{concepts: array<string, array>, errors: array<string>}
	 */
	public function parse( string $filepath ): array {
		$concepts = [];
		$errors   = [];

		if ( ! file_exists( $filepath ) ) {
			return [
				'concepts' => [],
				'errors'   => [ 'File not found: ' . $filepath ],
			];
		}

		$content = file_get_contents( $filepath );
		if ( false === $content ) {
			return [
				'concepts' => [],
				'errors'   => [ 'Could not read file: ' . $filepath ],
			];
		}

		// Extract prefixes.
		$this->extractPrefixes( $content );

		// Parse concepts.
		$concepts = $this->extractConcepts( $content );

		return [
			'concepts' => $concepts,
			'errors'   => $errors,
		];
	}

	/**
	 * Enrich vocabulary data with SKOS hierarchy and definitions.
	 *
	 * @param array<string, array<array>> $vocabulary Vocabulary from TaxonomyExtractor.
	 * @param array{concepts: array}      $skos_data  Parsed SKOS data.
	 *
	 * @return array<string, array<array>>
	 */
	public function enrichVocabulary( array $vocabulary, array $skos_data ): array {
		$concepts = $skos_data['concepts'] ?? [];
		$enriched = [];

		foreach ( $vocabulary as $taxonomy => $terms ) {
			$enriched[ $taxonomy ] = [];

			foreach ( $terms as $term ) {
				$slug = $term['slug'] ?? '';

				// Try to find matching SKOS concept.
				$concept_key = $this->findConceptKey( $taxonomy, $slug, $concepts );

				if ( null !== $concept_key && isset( $concepts[ $concept_key ] ) ) {
					$concept = $concepts[ $concept_key ];

					// Add SKOS data to term.
					$term['skos_definition'] = $concept['definition'] ?? null;
					$term['skos_broader']    = $concept['broader'] ?? null;
					$term['skos_narrower']   = $concept['narrower'] ?? [];
					$term['skos_preflabel']  = $concept['prefLabel'] ?? null;
				}

				$enriched[ $taxonomy ][] = $term;
			}
		}

		return $enriched;
	}

	/**
	 * Extract @prefix declarations from Turtle content.
	 *
	 * @param string $content Turtle file content.
	 *
	 * @return void
	 */
	private function extractPrefixes( string $content ): void {
		$this->prefixes = [];

		// Match @prefix declarations: @prefix site: <https://example.com/> .
		if ( preg_match_all( '/@prefix\s+(\w+):\s+<([^>]+)>\s*\./', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$this->prefixes[ $match[1] ] = $match[2];
			}
		}
	}

	/**
	 * Extract SKOS concepts from Turtle content.
	 *
	 * @param string $content Turtle file content.
	 *
	 * @return array<string, array>
	 */
	private function extractConcepts( string $content ): array {
		$concepts = [];

		// Split into subject blocks (handle multi-line statements).
		// Pattern matches: subject a skos:Concept ; ... .
		$pattern = '/(\S+:\S+)\s+a\s+skos:Concept\s*;([^\.]+)\./s';

		if ( preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$subject_uri = $this->resolveUri( $match[1] );
				$properties  = $match[2];

				$concept = $this->parseConceptProperties( $subject_uri, $properties );
				if ( null !== $concept ) {
					$key              = $this->extractConceptKey( $subject_uri );
					$concepts[ $key ] = $concept;
				}
			}
		}

		return $concepts;
	}

	/**
	 * Parse properties of a SKOS concept.
	 *
	 * @param string $uri        Concept URI.
	 * @param string $properties Property string from Turtle.
	 *
	 * @return array|null
	 */
	private function parseConceptProperties( string $uri, string $properties ): ?array {
		$concept = [
			'uri'        => $uri,
			'slug'       => $this->extractSlugFromUri( $uri ),
			'prefLabel'  => null,
			'definition' => null,
			'broader'    => null,
			'narrower'   => [],
		];

		// Extract prefLabel: skos:prefLabel "Climate"@en
		if ( preg_match( '/skos:prefLabel\s+"([^"]+)"(?:@\w+)?/', $properties, $match ) ) {
			$concept['prefLabel'] = $match[1];
		}

		// Extract definition: skos:definition "..."@en
		if ( preg_match( '/skos:definition\s+"([^"]+)"(?:@\w+)?/', $properties, $match ) ) {
			$concept['definition'] = $match[1];
		}

		// Extract broader: skos:broader site:category/climate
		if ( preg_match( '/skos:broader\s+(\S+)/', $properties, $match ) ) {
			$broader_uri        = $this->resolveUri( $match[1] );
			$concept['broader'] = $this->extractConceptKey( $broader_uri );
		}

		// Extract narrower: skos:narrower site:category/mitigation, site:category/adaptation
		if ( preg_match( '/skos:narrower\s+([^;.]+)/', $properties, $match ) ) {
			$narrower_string = trim( $match[1] );
			$narrower_uris   = preg_split( '/\s*,\s*/', $narrower_string );

			foreach ( $narrower_uris as $narrower_uri ) {
				$narrower_uri = trim( $narrower_uri );
				if ( ! empty( $narrower_uri ) ) {
					$resolved_uri         = $this->resolveUri( $narrower_uri );
					$concept['narrower'][] = $this->extractConceptKey( $resolved_uri );
				}
			}
		}

		return $concept;
	}

	/**
	 * Resolve a prefixed URI to a full URI.
	 *
	 * @param string $prefixed Prefixed URI (e.g., "site:category/climate").
	 *
	 * @return string Full URI.
	 */
	private function resolveUri( string $prefixed ): string {
		if ( strpos( $prefixed, ':' ) === false ) {
			return $prefixed;
		}

		// Check if it's already a full URI.
		if ( strpos( $prefixed, 'http://' ) === 0 || strpos( $prefixed, 'https://' ) === 0 ) {
			return $prefixed;
		}

		[ $prefix, $local ] = explode( ':', $prefixed, 2 );

		if ( isset( $this->prefixes[ $prefix ] ) ) {
			return $this->prefixes[ $prefix ] . $local;
		}

		return $prefixed;
	}

	/**
	 * Extract concept key from URI.
	 *
	 * For URI like "https://example.com/category/climate" returns "category/climate".
	 *
	 * @param string $uri Full URI.
	 *
	 * @return string Concept key.
	 */
	private function extractConceptKey( string $uri ): string {
		// Remove the site prefix to get the local path.
		foreach ( $this->prefixes as $prefix => $base_uri ) {
			if ( strpos( $uri, $base_uri ) === 0 ) {
				return substr( $uri, strlen( $base_uri ) );
			}
		}

		// If no prefix matched, try to extract from URL path.
		$parsed = parse_url( $uri );
		if ( isset( $parsed['path'] ) ) {
			return ltrim( $parsed['path'], '/' );
		}

		return $uri;
	}

	/**
	 * Extract slug from concept URI.
	 *
	 * For URI "category/climate" returns "climate".
	 *
	 * @param string $uri Concept URI or key.
	 *
	 * @return string Slug.
	 */
	private function extractSlugFromUri( string $uri ): string {
		// Get the last segment after any slash.
		$parts = explode( '/', $uri );
		return end( $parts );
	}

	/**
	 * Find the concept key that matches a taxonomy and slug.
	 *
	 * @param string                      $taxonomy Taxonomy name.
	 * @param string                      $slug     Term slug.
	 * @param array<string, array> $concepts Parsed concepts.
	 *
	 * @return string|null Concept key or null if not found.
	 */
	private function findConceptKey( string $taxonomy, string $slug, array $concepts ): ?string {
		// Try exact match: taxonomy/slug
		$key = $taxonomy . '/' . $slug;
		if ( isset( $concepts[ $key ] ) ) {
			return $key;
		}

		// Try with underscore variant: post_tag -> post_tag/slug
		if ( isset( $concepts[ $key ] ) ) {
			return $key;
		}

		// Search by slug match (fallback).
		foreach ( $concepts as $concept_key => $concept ) {
			if ( ( $concept['slug'] ?? '' ) === $slug ) {
				// Verify taxonomy matches (concept key should start with taxonomy/).
				if ( strpos( $concept_key, $taxonomy . '/' ) === 0 ) {
					return $concept_key;
				}
			}
		}

		return null;
	}
}
