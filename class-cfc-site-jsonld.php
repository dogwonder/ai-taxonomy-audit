<?php
/**
 * JSON-LD Structured Data Generator
 *
 * Generates Schema.org JSON-LD with TCLP custom vocabulary extensions
 * for clause and guide post types.
 *
 * @link       https://chancerylaneproject.org
 * @since      1.0.0
 *
 * @package    CFC_Site
 * @subpackage CFC_Site/includes
 * @author     Rich Holman <dogwonder@gmail.com>
 */

/**
 * JSON-LD structured data functionality
 *
 * Outputs JSON-LD structured data in the page head for clause and guide pages,
 * making content discoverable by AI agents and search engines.
 *
 * Uses vocab/tclp-vocabulary.json as single source of truth for property mappings.
 *
 * @package    CFC_Site
 * @subpackage CFC_Site/includes
 * @author     Rich Holman <dogwonder@gmail.com>
 */
class CFC_Site_JSONLD
{
    /**
     * Vocabulary configuration loaded from tclp-vocabulary.json
     *
     * @var array|null
     */
    private $vocab_config = null;

    /**
     * Get vocabulary configuration
     *
     * Loads and caches the vocabulary config from tclp-vocabulary.json
     *
     * @return array Vocabulary configuration
     */
    private function get_vocab_config()
    {
        if ($this->vocab_config === null) {
            $config_path = ABSPATH . 'vocab/tclp-vocabulary.json';

            if (file_exists($config_path)) {
                $json = file_get_contents($config_path);
                $this->vocab_config = json_decode($json, true) ?: [];
            } else {
                $this->vocab_config = [];
            }
        }

        return $this->vocab_config;
    }

    /**
     * Get property name for a taxonomy
     *
     * @param string $taxonomy Taxonomy slug
     * @return string|null Property name with prefix (e.g., 'tclp:climateOutcome') or null if not mapped
     */
    private function get_taxonomy_property($taxonomy)
    {
        $config = $this->get_vocab_config();
        $prefix = $config['prefix'] ?? 'tclp';
        $mappings = $config['taxonomy_mappings'] ?? [];

        if (isset($mappings[$taxonomy]['property'])) {
            return $prefix . ':' . $mappings[$taxonomy]['property'];
        }

        return null;
    }

    /**
     * Check if a taxonomy property is multi-valued
     *
     * @param string $taxonomy Taxonomy slug
     * @return bool True if multi-valued
     */
    private function is_multi_valued($taxonomy)
    {
        $config = $this->get_vocab_config();
        $mappings = $config['taxonomy_mappings'] ?? [];

        return $mappings[$taxonomy]['multi_valued'] ?? true;
    }

    /**
     * Get all taxonomy mappings for a post type
     *
     * @param string $post_type Post type slug
     * @return array Array of taxonomy => property mappings
     */
    private function get_taxonomies_for_post_type($post_type)
    {
        $config = $this->get_vocab_config();
        $mappings = $config['taxonomy_mappings'] ?? [];
        $prefix = $config['prefix'] ?? 'tclp';
        $result = [];

        foreach ($mappings as $taxonomy => $mapping) {
            $post_types = $mapping['post_types'] ?? [];
            if (in_array($post_type, $post_types)) {
                $result[$taxonomy] = $prefix . ':' . $mapping['property'];
            }
        }

        return $result;
    }

    /**
     * Output JSON-LD in the page head
     *
     * This method is hooked to wp_head and outputs JSON-LD
     * for single clause and guide pages.
     *
     * @since    1.0.0
     */
    public function output_jsonld()
    {
        if (is_singular(['clause', 'guide'])) {
            echo $this->generate_jsonld(get_queried_object());
        }
    }

    /**
     * Generate JSON-LD structured data for TCLP content
     *
     * @since    1.0.0
     * @param    int|WP_Post $post The post object or ID
     * @return   string JSON-LD script tag
     */
    private function generate_jsonld($post = null)
    {
        $post = get_post($post);

        if (!$post || !in_array($post->post_type, ['clause', 'guide'])) {
            return '';
        }

        $base_url = get_site_url();
        $post_url = get_permalink($post);

        // Base JSON-LD structure
        $jsonld = [
            '@context' => $base_url . '/vocab/context.jsonld',
            '@type' => 'LegalDocument',
            '@id' => $post_url,
            'name' => get_the_title($post),
            'url' => $post_url,
            'datePublished' => get_the_date('c', $post),
        ];

        // Determine the best date for dateModified
        // Priority: ACF last_updated_date > WordPress modified date
        $date_modified = get_the_modified_date('c', $post);

        if (function_exists('get_field')) {
            if ($post->post_type === 'clause') {
                $clause_fields = get_field('clause_fields', $post->ID);
                if (!empty($clause_fields['clause_last_updated_date'])) {
                    // Convert date to ISO 8601 format
                    $acf_date = $clause_fields['clause_last_updated_date'];
                    $date_obj = DateTime::createFromFormat('Y-m-d', $acf_date);
                    if ($date_obj) {
                        $date_modified = $date_obj->format('c');
                    }
                }
            } elseif ($post->post_type === 'guide') {
                $guide_fields = get_field('guide_fields', $post->ID);
                if (!empty($guide_fields['guide_last_updated_date'])) {
                    // Convert date to ISO 8601 format
                    $acf_date = $guide_fields['guide_last_updated_date'];
                    $date_obj = DateTime::createFromFormat('Y-m-d', $acf_date);
                    if ($date_obj) {
                        $date_modified = $date_obj->format('c');
                    }
                }
            }
        }

        $jsonld['dateModified'] = $date_modified;

        // Add description/summary
        if (!empty($post->post_excerpt)) {
            $jsonld['description'] = $post->post_excerpt;
        }

        // Add organization as publisher (primary attribution)
        $jsonld['publisher'] = [
            '@type' => 'Organization',
            'name' => 'The Chancery Lane Project',
            'url' => $base_url,
        ];

        // Type-specific processing
        if ($post->post_type === 'clause') {
            $jsonld = $this->add_clause_jsonld($jsonld, $post);
        } elseif ($post->post_type === 'guide') {
            $jsonld = $this->add_guide_jsonld($jsonld, $post);
        }

        // Generate script tag with proper formatting
        $json_output = wp_json_encode(
            $jsonld,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        // Add proper indentation to each line for better readability in page source
        $json_lines = explode("\n", $json_output);
        $formatted_json = implode("\n    ", $json_lines);

        return sprintf(
            "\n<!-- TCLP JSON-LD Structured Data -->\n<script type=\"application/ld+json\">\n    %s\n</script>\n\n",
            $formatted_json
        );
    }

    /**
     * Add clause-specific JSON-LD properties
     *
     * Uses taxonomy mappings from tclp-vocabulary.json config.
     *
     * @since    1.0.0
     * @param    array $jsonld Existing JSON-LD array
     * @param    WP_Post $post The clause post
     * @return   array Modified JSON-LD array
     */
    private function add_clause_jsonld($jsonld, $post)
    {
        // Add clause-specific type
        $jsonld['tclp:documentType'] = 'Clause';

        // ACF Fields (nested in clause_fields group)
        if (function_exists('get_field')) {
            $clause_fields = get_field('clause_fields', $post->ID);

            if ($clause_fields) {
                // Child name (alternative name for the clause)
                if (!empty($clause_fields['clause_child_name'])) {
                    $jsonld['alternateName'] = $clause_fields['clause_child_name'];
                }

                // Last reviewed date (for TCLP-specific tracking)
                if (!empty($clause_fields['clause_last_updated_date'])) {
                    $jsonld['tclp:lastReviewedDate'] = $clause_fields['clause_last_updated_date'];
                }

                // Summary
                if (!empty($clause_fields['clause_summary'])) {
                    $jsonld['abstract'] = $clause_fields['clause_summary'];
                }
            }
        }

        // Add all taxonomies mapped for clause post type (from config)
        $taxonomy_mappings = $this->get_taxonomies_for_post_type('clause');

        foreach ($taxonomy_mappings as $taxonomy => $property) {
            $jsonld = $this->add_taxonomy_terms($jsonld, $post, $taxonomy, $property);
        }

        return $jsonld;
    }

    /**
     * Add guide-specific JSON-LD properties
     *
     * Uses taxonomy mappings from tclp-vocabulary.json config.
     *
     * @since    1.0.0
     * @param    array $jsonld Existing JSON-LD array
     * @param    WP_Post $post The guide post
     * @return   array Modified JSON-LD array
     */
    private function add_guide_jsonld($jsonld, $post)
    {
        // Add guide-specific type
        $jsonld['@type'] = ['LegalDocument', 'Guide'];
        $jsonld['tclp:documentType'] = 'Guide';

        // ACF Fields (nested in guide_fields group)
        if (function_exists('get_field')) {
            $guide_fields = get_field('guide_fields', $post->ID);

            if ($guide_fields) {
                // Guide summary
                if (!empty($guide_fields['guide_summary'])) {
                    $jsonld['abstract'] = $guide_fields['guide_summary'];
                }

                // Last reviewed date (for TCLP-specific tracking)
                if (!empty($guide_fields['guide_last_updated_date'])) {
                    $jsonld['tclp:lastReviewedDate'] = $guide_fields['guide_last_updated_date'];
                }
            }

            // Guide links
            $guide_links = get_field('guide_links', $post->ID);
            if ($guide_links) {
                $potential_actions = [];

                if (!empty($guide_links['download_this_guide_url'])) {
                    $potential_actions[] = [
                        '@type' => 'DownloadAction',
                        'target' => $guide_links['download_this_guide_url'],
                    ];
                }

                if (!empty($guide_links['give_feedback_on_this_guide_url'])) {
                    $potential_actions[] = [
                        '@type' => 'InteractAction',
                        'name' => 'Give feedback',
                        'target' => $guide_links['give_feedback_on_this_guide_url'],
                    ];
                }

                if (!empty($potential_actions)) {
                    $jsonld['potentialAction'] = $potential_actions;
                }
            }
        }

        // Add all taxonomies mapped for guide post type (from config)
        $taxonomy_mappings = $this->get_taxonomies_for_post_type('guide');

        foreach ($taxonomy_mappings as $taxonomy => $property) {
            $jsonld = $this->add_taxonomy_terms($jsonld, $post, $taxonomy, $property);
        }

        // Tags
        $tags = get_the_tags($post->ID);
        if ($tags && !is_wp_error($tags)) {
            $jsonld['keywords'] = array_map(function ($tag) {
                return $tag->name;
            }, $tags);
        }

        return $jsonld;
    }

    /**
     * Add taxonomy terms to JSON-LD as SKOS concepts
     *
     * @since    1.0.0
     * @param    array $jsonld Existing JSON-LD array
     * @param    WP_Post $post The post object
     * @param    string $taxonomy Taxonomy slug
     * @param    string $property TCLP property name
     * @return   array Modified JSON-LD array
     */
    private function add_taxonomy_terms($jsonld, $post, $taxonomy, $property)
    {
        $base_url = get_site_url();
        $terms = get_the_terms($post, $taxonomy);

        if ($terms && !is_wp_error($terms)) {
            $term_data = array_map(function ($term) use ($base_url, $taxonomy) {
                return [
                    '@type' => 'skos:Concept',
                    // '@id' => $base_url . '/taxonomy/' . $taxonomy . '/' . $term->slug,  // Uncomment when taxonomy listing pages are ready
                    'skos:prefLabel' => $term->name,
                ];
            }, $terms);

            // Use single object if only one term, otherwise array
            $jsonld[$property] = count($term_data) === 1 ? $term_data[0] : $term_data;
        }

        return $jsonld;
    }

    /**
     * Get a single taxonomy term (for taxonomies that should only have one value)
     *
     * @since    1.0.0
     * @param    WP_Post $post The post object
     * @param    string $taxonomy Taxonomy slug
     * @return   WP_Term|null The first term or null
     */
    private function get_single_taxonomy_term($post, $taxonomy)
    {
        $terms = get_the_terms($post, $taxonomy);

        if ($terms && !is_wp_error($terms) && !empty($terms)) {
            return reset($terms);
        }

        return null;
    }
}
