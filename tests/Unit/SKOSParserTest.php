<?php
/**
 * Tests for SKOSParser.
 *
 * @package DGWTaxonomyAudit\Tests\Unit
 */

declare(strict_types=1);

namespace DGWTaxonomyAudit\Tests\Unit;

use DGWTaxonomyAudit\Core\SKOSParser;
use PHPUnit\Framework\TestCase;

/**
 * Test class for SKOSParser.
 */
class SKOSParserTest extends TestCase {

	/**
	 * Path to test fixture.
	 *
	 * @var string
	 */
	private string $fixture_path;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->fixture_path = dirname( __DIR__ ) . '/fixtures/taxonomy.skos.ttl';
	}

	/**
	 * Test that parse() returns expected structure.
	 */
	public function test_parse_returns_expected_structure(): void {
		$parser = new SKOSParser();
		$result = $parser->parse( $this->fixture_path );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'concepts', $result );
		$this->assertArrayHasKey( 'errors', $result );
	}

	/**
	 * Test that parse() extracts concept URIs correctly.
	 */
	public function test_parse_extracts_concept_uris(): void {
		$parser = new SKOSParser();
		$result = $parser->parse( $this->fixture_path );

		$this->assertArrayHasKey( 'category/climate', $result['concepts'] );
		$this->assertArrayHasKey( 'category/mitigation', $result['concepts'] );
		$this->assertArrayHasKey( 'category/adaptation', $result['concepts'] );
		$this->assertArrayHasKey( 'category/carbon-reduction', $result['concepts'] );
		$this->assertArrayHasKey( 'category/governance', $result['concepts'] );
	}

	/**
	 * Test that parse() extracts prefLabel correctly.
	 */
	public function test_parse_extracts_preflabel(): void {
		$parser = new SKOSParser();
		$result = $parser->parse( $this->fixture_path );

		$climate = $result['concepts']['category/climate'];
		$this->assertEquals( 'Climate', $climate['prefLabel'] );

		$mitigation = $result['concepts']['category/mitigation'];
		$this->assertEquals( 'Mitigation', $mitigation['prefLabel'] );
	}

	/**
	 * Test that parse() extracts definition correctly.
	 */
	public function test_parse_extracts_definition(): void {
		$parser = new SKOSParser();
		$result = $parser->parse( $this->fixture_path );

		$climate = $result['concepts']['category/climate'];
		$this->assertEquals( 'Actions addressing climate change', $climate['definition'] );
	}

	/**
	 * Test that parse() extracts broader relationships.
	 */
	public function test_parse_extracts_broader_relationships(): void {
		$parser = new SKOSParser();
		$result = $parser->parse( $this->fixture_path );

		$mitigation = $result['concepts']['category/mitigation'];
		$this->assertEquals( 'category/climate', $mitigation['broader'] );

		$carbon = $result['concepts']['category/carbon-reduction'];
		$this->assertEquals( 'category/mitigation', $carbon['broader'] );
	}

	/**
	 * Test that parse() extracts narrower relationships.
	 */
	public function test_parse_extracts_narrower_relationships(): void {
		$parser = new SKOSParser();
		$result = $parser->parse( $this->fixture_path );

		$climate = $result['concepts']['category/climate'];
		$this->assertContains( 'category/mitigation', $climate['narrower'] );
		$this->assertContains( 'category/adaptation', $climate['narrower'] );
	}

	/**
	 * Test that parse() extracts slug from URI.
	 */
	public function test_parse_extracts_slug_from_uri(): void {
		$parser = new SKOSParser();
		$result = $parser->parse( $this->fixture_path );

		$climate = $result['concepts']['category/climate'];
		$this->assertEquals( 'climate', $climate['slug'] );

		$carbon = $result['concepts']['category/carbon-reduction'];
		$this->assertEquals( 'carbon-reduction', $carbon['slug'] );
	}

	/**
	 * Test that parse() handles missing file gracefully.
	 */
	public function test_parse_handles_missing_file(): void {
		$parser = new SKOSParser();
		$result = $parser->parse( '/nonexistent/file.ttl' );

		$this->assertEmpty( $result['concepts'] );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'File not found', $result['errors'][0] );
	}

	/**
	 * Test that parse() handles multiple taxonomies.
	 */
	public function test_parse_handles_multiple_taxonomies(): void {
		$parser = new SKOSParser();
		$result = $parser->parse( $this->fixture_path );

		// Should have concepts from both category and post_tag
		$this->assertArrayHasKey( 'post_tag/sustainability', $result['concepts'] );
		$this->assertArrayHasKey( 'post_tag/net-zero', $result['concepts'] );
	}

	/**
	 * Test enrichVocabulary() merges SKOS data into vocabulary.
	 */
	public function test_enrich_vocabulary_merges_skos_data(): void {
		$parser = new SKOSParser();
		$skos_data = $parser->parse( $this->fixture_path );

		$vocabulary = [
			'category' => [
				[
					'slug'        => 'climate',
					'name'        => 'Climate',
					'description' => '',
				],
				[
					'slug'        => 'mitigation',
					'name'        => 'Mitigation',
					'description' => '',
				],
			],
		];

		$enriched = $parser->enrichVocabulary( $vocabulary, $skos_data );

		// Should have SKOS-enriched descriptions.
		$climate = $this->findTermBySlug( $enriched['category'], 'climate' );
		$this->assertEquals( 'Actions addressing climate change', $climate['skos_definition'] );

		// Should have hierarchy info.
		$mitigation = $this->findTermBySlug( $enriched['category'], 'mitigation' );
		$this->assertEquals( 'category/climate', $mitigation['skos_broader'] );
	}

	/**
	 * Test enrichVocabulary() preserves terms not in SKOS.
	 */
	public function test_enrich_vocabulary_preserves_non_skos_terms(): void {
		$parser = new SKOSParser();
		$skos_data = $parser->parse( $this->fixture_path );

		$vocabulary = [
			'category' => [
				[
					'slug'        => 'climate',
					'name'        => 'Climate',
					'description' => 'Original description',
				],
				[
					'slug'        => 'unknown-term',
					'name'        => 'Unknown Term',
					'description' => 'Not in SKOS',
				],
			],
		];

		$enriched = $parser->enrichVocabulary( $vocabulary, $skos_data );

		// Unknown term should still exist.
		$unknown = $this->findTermBySlug( $enriched['category'], 'unknown-term' );
		$this->assertNotNull( $unknown );
		$this->assertEquals( 'Unknown Term', $unknown['name'] );
	}

	/**
	 * Test that parse() handles concepts with no hierarchy.
	 */
	public function test_parse_handles_concepts_without_hierarchy(): void {
		$parser = new SKOSParser();
		$result = $parser->parse( $this->fixture_path );

		$governance = $result['concepts']['category/governance'];
		$this->assertNull( $governance['broader'] );
		$this->assertEmpty( $governance['narrower'] );
	}

	/**
	 * Helper to find term by slug in array.
	 *
	 * @param array<array{slug: string}> $terms Terms array.
	 * @param string                     $slug  Slug to find.
	 *
	 * @return array|null
	 */
	private function findTermBySlug( array $terms, string $slug ): ?array {
		foreach ( $terms as $term ) {
			if ( ( $term['slug'] ?? '' ) === $slug ) {
				return $term;
			}
		}
		return null;
	}
}
