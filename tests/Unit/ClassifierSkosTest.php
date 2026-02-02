<?php
/**
 * Tests for Classifier SKOS integration.
 *
 * @package DGWTaxonomyAudit\Tests\Unit
 */

declare(strict_types=1);

namespace DGWTaxonomyAudit\Tests\Unit;

use DGWTaxonomyAudit\Core\SKOSParser;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Test class for Classifier SKOS integration.
 *
 * Tests the vocabulary formatting with SKOS context using reflection
 * to access private methods (unit testing the formatting logic).
 */
class ClassifierSkosTest extends TestCase {

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
	 * Test SKOS parser produces valid structure for classifier.
	 */
	public function test_skos_parser_produces_classifier_compatible_structure(): void {
		$parser = new SKOSParser();
		$skos_data = $parser->parse( $this->fixture_path );

		// Verify structure is compatible with Classifier.
		$this->assertArrayHasKey( 'concepts', $skos_data );
		$this->assertIsArray( $skos_data['concepts'] );

		// Check concept structure.
		$climate = $skos_data['concepts']['category/climate'];
		$this->assertArrayHasKey( 'slug', $climate );
		$this->assertArrayHasKey( 'prefLabel', $climate );
		$this->assertArrayHasKey( 'definition', $climate );
		$this->assertArrayHasKey( 'broader', $climate );
		$this->assertArrayHasKey( 'narrower', $climate );
	}

	/**
	 * Test hierarchy chain is correctly parsed.
	 */
	public function test_hierarchy_chain_is_correctly_parsed(): void {
		$parser = new SKOSParser();
		$skos_data = $parser->parse( $this->fixture_path );
		$concepts = $skos_data['concepts'];

		// climate (root) -> mitigation -> carbon-reduction (leaf)
		$climate = $concepts['category/climate'];
		$this->assertNull( $climate['broader'] );
		$this->assertContains( 'category/mitigation', $climate['narrower'] );

		$mitigation = $concepts['category/mitigation'];
		$this->assertEquals( 'category/climate', $mitigation['broader'] );
		$this->assertContains( 'category/carbon-reduction', $mitigation['narrower'] );

		$carbon = $concepts['category/carbon-reduction'];
		$this->assertEquals( 'category/mitigation', $carbon['broader'] );
		$this->assertEmpty( $carbon['narrower'] );
	}

	/**
	 * Test slug extraction from concept keys.
	 */
	public function test_slug_extraction_from_concept_keys(): void {
		$parser = new SKOSParser();
		$skos_data = $parser->parse( $this->fixture_path );

		foreach ( $skos_data['concepts'] as $key => $concept ) {
			// Key format: taxonomy/slug.
			$expected_slug = substr( $key, strpos( $key, '/' ) + 1 );
			$this->assertEquals( $expected_slug, $concept['slug'] );
		}
	}

	/**
	 * Test multiple taxonomies are correctly separated.
	 */
	public function test_multiple_taxonomies_correctly_separated(): void {
		$parser = new SKOSParser();
		$skos_data = $parser->parse( $this->fixture_path );

		$category_concepts = array_filter(
			$skos_data['concepts'],
			fn( $key ) => strpos( $key, 'category/' ) === 0,
			ARRAY_FILTER_USE_KEY
		);

		$tag_concepts = array_filter(
			$skos_data['concepts'],
			fn( $key ) => strpos( $key, 'post_tag/' ) === 0,
			ARRAY_FILTER_USE_KEY
		);

		$this->assertCount( 5, $category_concepts ); // climate, mitigation, adaptation, carbon-reduction, governance
		$this->assertCount( 2, $tag_concepts );       // sustainability, net-zero
	}

	/**
	 * Test definitions are extracted without language tags.
	 */
	public function test_definitions_extracted_without_language_tags(): void {
		$parser = new SKOSParser();
		$skos_data = $parser->parse( $this->fixture_path );

		$climate = $skos_data['concepts']['category/climate'];

		// Should not contain @en or other language markers.
		$this->assertStringNotContainsString( '@en', $climate['definition'] );
		$this->assertStringNotContainsString( '@', $climate['prefLabel'] );
	}
}
