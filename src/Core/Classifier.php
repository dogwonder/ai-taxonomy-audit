<?php
/**
 * Classification Orchestrator
 *
 * @package DGWTaxonomyAudit\Core
 */

declare(strict_types=1);

namespace DGWTaxonomyAudit\Core;

/**
 * Orchestrates the classification workflow with two-step conversation and retry logic.
 */
class Classifier {

	/**
	 * Maximum retry attempts for invalid terms.
	 */
	private const MAX_RETRIES = 2;

	/**
	 * LLM client.
	 *
	 * @var LLMClientInterface
	 */
	private LLMClientInterface $client;

	/**
	 * Taxonomy extractor.
	 *
	 * @var TaxonomyExtractor
	 */
	private TaxonomyExtractor $taxonomy_extractor;

	/**
	 * Content exporter.
	 *
	 * @var ContentExporter
	 */
	private ContentExporter $content_exporter;

	/**
	 * Minimum confidence threshold.
	 *
	 * @var float
	 */
	private float $min_confidence;

	/**
	 * Whether to use two-step classification.
	 *
	 * @var bool
	 */
	private bool $two_step = true;

	/**
	 * Whether to include gap-filling suggestions (audit mode).
	 *
	 * In audit mode, the LLM can suggest new terms that don't exist in the vocabulary.
	 * These are marked with `in_vocabulary: false` in the output.
	 *
	 * @var bool
	 */
	private bool $audit_mode = false;

	/**
	 * Cost tracker for usage statistics.
	 *
	 * @var CostTracker|null
	 */
	private ?CostTracker $cost_tracker = null;

	/**
	 * SKOS context data for hierarchical vocabulary formatting.
	 *
	 * @var array{concepts: array<string, array>}|null
	 */
	private ?array $skos_context = null;

	/**
	 * Constructor.
	 *
	 * @param LLMClientInterface|null $client             LLM client.
	 * @param TaxonomyExtractor|null  $taxonomy_extractor Taxonomy extractor.
	 * @param ContentExporter|null    $content_exporter   Content exporter.
	 */
	public function __construct(
		?LLMClientInterface $client = null,
		?TaxonomyExtractor $taxonomy_extractor = null,
		?ContentExporter $content_exporter = null
	) {
		$config = \DGWTaxonomyAudit\get_config();

		$this->client = $client ?? new OllamaClient();
		$this->taxonomy_extractor = $taxonomy_extractor ?? new TaxonomyExtractor();
		$this->content_exporter = $content_exporter ?? new ContentExporter(
			$config['classification']['max_content_length']
		);

		$this->min_confidence = $config['classification']['min_confidence_threshold'];

		// Initialize cost tracker.
		$this->cost_tracker = new CostTracker(
			$this->client->getProvider(),
			$this->client->getModel()
		);
	}

	/**
	 * Classify a single post using two-step conversation with retry.
	 *
	 * @param int           $post_id    Post ID.
	 * @param array<string> $taxonomies Taxonomies to classify against.
	 *
	 * @return array{post_id: int, post_title: string, classifications: array, error: string|null}
	 */
	public function classifyPost( int $post_id, array $taxonomies ): array {
		$post_data = $this->content_exporter->getPost( $post_id );

		if ( null === $post_data ) {
			return [
				'post_id'         => $post_id,
				'post_title'      => '',
				'classifications' => [],
				'error'           => 'Post not found',
			];
		}

		$vocabulary = $this->taxonomy_extractor->getVocabulary( $taxonomies );
		$content = $this->content_exporter->buildPromptContent( $post_data );

		try {
			if ( $this->two_step ) {
				$result = $this->classifyTwoStep( $content, $vocabulary );
			} else {
				$result = $this->classifySingleStep( $content, $vocabulary );
			}

			$classifications = $result['classifications'] ?? [];

			if ( $this->audit_mode ) {
				// In audit mode: preserve suggested new terms, only retry for terms
				// that aren't explicitly marked as suggested.
				$invalid_terms = $this->findInvalidTermsForAudit( $classifications, $vocabulary );

				if ( ! empty( $invalid_terms ) && isset( $result['messages'] ) ) {
					$result = $this->retryWithCorrection( $result['messages'], $invalid_terms, $vocabulary );
					$classifications = $result['classifications'] ?? [];
				}

				// Filter by minimum confidence.
				$classifications = $this->filterByConfidence( $classifications );

				// Validate vocab terms and mark in_vocabulary flag.
				$classifications = $this->validateAndMarkTerms( $classifications, $vocabulary );
			} else {
				// In benchmark mode: validate and retry if needed.
				$invalid_terms = $this->findInvalidTerms( $classifications, $vocabulary );

				if ( ! empty( $invalid_terms ) && isset( $result['messages'] ) ) {
					$result = $this->retryWithCorrection( $result['messages'], $invalid_terms, $vocabulary );
					$classifications = $result['classifications'] ?? [];
				}

				// Filter by minimum confidence.
				$classifications = $this->filterByConfidence( $classifications );

				// Final validation (remove any remaining invalid terms).
				$classifications = $this->validateTerms( $classifications, $vocabulary );
			}

			return [
				'post_id'         => $post_id,
				'post_title'      => $post_data['title'],
				'post_url'        => $post_data['url'],
				'existing_terms'  => $post_data['existing_terms'],
				'classifications' => $classifications,
				'error'           => $result['error'] ?? null,
			];
		} catch ( \Exception $e ) {
			return [
				'post_id'         => $post_id,
				'post_title'      => $post_data['title'],
				'classifications' => [],
				'error'           => $e->getMessage(),
			];
		}
	}

	/**
	 * Two-step classification: establish context, then classify.
	 *
	 * @param string               $content    Post content.
	 * @param array<string, mixed> $vocabulary Vocabulary.
	 *
	 * @return array{classifications: array, messages: array, error: string|null}
	 */
	private function classifyTwoStep( string $content, array $vocabulary ): array {
		$messages = [
			[
				'role'    => 'system',
				'content' => $this->audit_mode
					? $this->getAuditSystemPrompt()
					: $this->getSystemPrompt(),
			],
		];

		// Step 1: Establish context.
		$messages[] = [
			'role'    => 'user',
			'content' => $this->getContextPrompt( $content ),
		];

		$response = $this->client->chat( $messages, false );
		$this->recordUsage( $response );
		$assistant_reply = $response['message']['content'] ?? '';

		$messages[] = [
			'role'    => 'assistant',
			'content' => $assistant_reply,
		];

		// Step 2: Request classification.
		$messages[] = [
			'role'    => 'user',
			'content' => $this->audit_mode
				? $this->getAuditClassificationPrompt( $vocabulary )
				: $this->getClassificationPrompt( $vocabulary ),
		];

		$response = $this->client->chat( $messages, true );
		$this->recordUsage( $response );
		$classification_reply = $response['message']['content'] ?? '';

		$messages[] = [
			'role'    => 'assistant',
			'content' => $classification_reply,
		];

		// Parse the classification response.
		$parsed = $this->parseClassificationResponse( $classification_reply );

		return [
			'classifications' => $parsed['classifications'] ?? [],
			'messages'        => $messages,
			'error'           => $parsed['error'] ?? null,
		];
	}

	/**
	 * Single-step classification (original approach).
	 *
	 * @param string               $content    Post content.
	 * @param array<string, mixed> $vocabulary Vocabulary.
	 *
	 * @return array{classifications: array, messages: array, error: string|null}
	 */
	private function classifySingleStep( string $content, array $vocabulary ): array {
		$messages = [
			[
				'role'    => 'system',
				'content' => $this->audit_mode
					? $this->getAuditSystemPrompt()
					: $this->getSystemPrompt(),
			],
			[
				'role'    => 'user',
				'content' => $this->audit_mode
					? $this->getAuditSingleStepPrompt( $content, $vocabulary )
					: $this->getSingleStepPrompt( $content, $vocabulary ),
			],
		];

		$response = $this->client->chat( $messages, true );
		$this->recordUsage( $response );
		$reply = $response['message']['content'] ?? '';

		$messages[] = [
			'role'    => 'assistant',
			'content' => $reply,
		];

		$parsed = $this->parseClassificationResponse( $reply );

		return [
			'classifications' => $parsed['classifications'] ?? [],
			'messages'        => $messages,
			'error'           => $parsed['error'] ?? null,
		];
	}

	/**
	 * Retry classification when invalid terms are found.
	 *
	 * @param array<array{role: string, content: string}> $messages      Conversation history.
	 * @param array<string>                               $invalid_terms Invalid terms found.
	 * @param array<string, mixed>                        $vocabulary    Vocabulary.
	 *
	 * @return array{classifications: array, error: string|null}
	 */
	private function retryWithCorrection( array $messages, array $invalid_terms, array $vocabulary ): array {
		$retry_prompt = $this->getRetryPrompt( $invalid_terms );

		$messages[] = [
			'role'    => 'user',
			'content' => $retry_prompt,
		];

		$response = $this->client->chat( $messages, true );
		$this->recordUsage( $response );
		$reply = $response['message']['content'] ?? '';

		$parsed = $this->parseClassificationResponse( $reply );

		// Check again for invalid terms.
		$still_invalid = $this->findInvalidTerms( $parsed['classifications'] ?? [], $vocabulary );

		if ( ! empty( $still_invalid ) ) {
			// Log but don't retry again - just filter them out.
			$parsed['error'] = sprintf(
				'Some terms could not be validated after retry: %s',
				implode( ', ', $still_invalid )
			);
		}

		return $parsed;
	}

	/**
	 * Record usage data from an API response.
	 *
	 * @param array<string, mixed> $response API response.
	 *
	 * @return void
	 */
	private function recordUsage( array $response ): void {
		if ( null === $this->cost_tracker ) {
			return;
		}

		$usage = $response['usage'] ?? [];
		if ( ! empty( $usage ) ) {
			$this->cost_tracker->recordUsage( $usage );
		}
	}

	/**
	 * Find terms that don't exist in vocabulary.
	 *
	 * @param array<string, array> $classifications Classifications.
	 * @param array<string, array> $vocabulary      Vocabulary.
	 *
	 * @return array<string> Invalid term slugs.
	 */
	private function findInvalidTerms( array $classifications, array $vocabulary ): array {
		$invalid = [];

		foreach ( $classifications as $taxonomy => $terms ) {
			if ( ! isset( $vocabulary[ $taxonomy ] ) ) {
				continue;
			}

			$valid_slugs = array_column( $vocabulary[ $taxonomy ], 'slug' );

			foreach ( $terms as $term ) {
				$slug = $term['term'] ?? '';
				if ( ! empty( $slug ) && ! in_array( $slug, $valid_slugs, true ) ) {
					$invalid[] = $slug;
				}
			}
		}

		return $invalid;
	}

	/**
	 * Find terms that are invalid in audit mode.
	 *
	 * In audit mode, terms explicitly marked as in_vocabulary: false are valid suggestions.
	 * This only flags terms not in vocabulary that weren't marked as suggestions.
	 *
	 * @param array<string, array> $classifications Classifications.
	 * @param array<string, array> $vocabulary      Vocabulary.
	 *
	 * @return array<string> Invalid term slugs.
	 */
	private function findInvalidTermsForAudit( array $classifications, array $vocabulary ): array {
		$invalid = [];

		foreach ( $classifications as $taxonomy => $terms ) {
			if ( ! isset( $vocabulary[ $taxonomy ] ) ) {
				continue;
			}

			$valid_slugs = array_column( $vocabulary[ $taxonomy ], 'slug' );

			foreach ( $terms as $term ) {
				$slug = $term['term'] ?? '';
				if ( empty( $slug ) ) {
					continue;
				}

				$in_vocabulary = $term['in_vocabulary'] ?? null;
				$is_valid_slug = in_array( $slug, $valid_slugs, true );

				// Invalid if: not in vocabulary AND not explicitly marked as a suggestion.
				// If in_vocabulary is null, we assume the LLM didn't follow instructions.
				if ( ! $is_valid_slug && $in_vocabulary !== false ) {
					$invalid[] = $slug;
				}
			}
		}

		return $invalid;
	}

	/**
	 * Get the system prompt with explicit rules.
	 *
	 * @return string
	 */
	private function getSystemPrompt(): string {
		return <<<'PROMPT'
You are a taxonomy classification assistant for a WordPress content management system. Your job is to assign the most relevant taxonomy terms to content.

CRITICAL RULES - Follow these exactly:

1. ONLY use terms from the vocabulary I provide. Do not invent, suggest, or hallucinate new terms.
2. If you are unsure about a term, do NOT include it. Only suggest terms you are confident about.
3. Be consistent. Given the same content, you should produce the same classification every time.
4. Prefer specific terms over general ones when the content clearly warrants specificity.
5. Consider the hierarchical relationships between terms if they exist.
6. Your response must be valid JSON. No markdown, no explanations outside the JSON structure.

CONFIDENCE SCORING:
- 0.9-1.0: Term is an obvious, primary match for the content
- 0.7-0.89: Term is clearly relevant but not the primary focus
- 0.5-0.69: Term is somewhat related but marginal
- Below 0.5: Do not include - insufficient confidence

REASONING:
- Keep reasons brief (under 15 words)
- Focus on WHY the term matches, not WHAT the content says
PROMPT;
	}

	/**
	 * Get the context prompt (step 1 of two-step).
	 *
	 * @param string $content Post content.
	 *
	 * @return string
	 */
	private function getContextPrompt( string $content ): string {
		return <<<PROMPT
I need you to classify the following content. First, read it carefully and confirm you understand the main topics, themes, and subject matter.

CONTENT TO CLASSIFY:

{$content}

Please provide a brief summary (2-3 sentences) of what this content is about. Focus on the key topics and themes that would be relevant for taxonomy classification. Do not suggest any terms yet - I will provide the allowed vocabulary next.
PROMPT;
	}

	/**
	 * Get the classification prompt (step 2 of two-step).
	 *
	 * @param array<string, mixed> $vocabulary Vocabulary.
	 *
	 * @return string
	 */
	private function getClassificationPrompt( array $vocabulary ): string {
		$vocab_text = $this->formatVocabulary( $vocabulary );

		return <<<PROMPT
Now classify the content using ONLY the terms from this vocabulary:

{$vocab_text}

CLASSIFICATION PROCESS (do this in order):

Step 1: For each taxonomy, scan through ALL available terms
Step 2: Identify terms that genuinely match the content (not just vaguely related)
Step 3: Assign a confidence score based on how central the term is to the content
Step 4: Write a brief reason explaining the match

RULES:
1. Use term SLUGS (the hyphenated versions), not display names
2. Only include terms with confidence >= 0.5
3. Maximum 5 terms per taxonomy unless the content clearly warrants more
4. If no terms match a taxonomy well, omit that taxonomy from your response

Return your response as JSON in this exact format:
{
  "classifications": {
    "taxonomy_slug": [
      {"term": "term-slug", "confidence": 0.95, "reason": "Brief explanation"}
    ]
  }
}

IMPORTANT: Only use terms that appear in the vocabulary above. Do not invent terms.
PROMPT;
	}

	/**
	 * Get the single-step prompt (combined context + classification).
	 *
	 * @param string               $content    Post content.
	 * @param array<string, mixed> $vocabulary Vocabulary.
	 *
	 * @return string
	 */
	private function getSingleStepPrompt( string $content, array $vocabulary ): string {
		$vocab_text = $this->formatVocabulary( $vocabulary );

		return <<<PROMPT
Classify the following content using ONLY terms from the provided vocabulary.

CONTENT:

{$content}

ALLOWED VOCABULARY:

{$vocab_text}

RULES:
1. Use term SLUGS (hyphenated versions), not display names
2. Only include terms with confidence >= 0.5
3. Maximum 5 terms per taxonomy
4. If no terms match well, omit that taxonomy
5. ONLY use terms from the vocabulary above - do not invent terms

Return JSON in this exact format:
{
  "classifications": {
    "taxonomy_slug": [
      {"term": "term-slug", "confidence": 0.95, "reason": "Brief explanation"}
    ]
  }
}
PROMPT;
	}

	/**
	 * Get the single-step prompt for audit mode (combined context + classification).
	 *
	 * @param string               $content    Post content.
	 * @param array<string, mixed> $vocabulary Vocabulary.
	 *
	 * @return string
	 */
	private function getAuditSingleStepPrompt( string $content, array $vocabulary ): string {
		$vocab_text = $this->formatVocabulary( $vocabulary );

		return <<<PROMPT
Classify the following content and suggest new terms that should exist in the vocabulary.

CONTENT:

{$content}

EXISTING VOCABULARY:

{$vocab_text}

CLASSIFICATION PROCESS:

Step 1: BENCHMARK - Identify matching terms from the vocabulary above
Step 2: GAP ANALYSIS - Identify concepts in the content that SHOULD have a term but don't
Step 3: For each term (existing or suggested), assign confidence and brief reason

RULES:
1. Use exact slugs for existing terms
2. Use kebab-case for suggested new terms (e.g., "climate-adaptation", "net-zero-target")
3. Mark existing terms with "in_vocabulary": true
4. Mark suggested new terms with "in_vocabulary": false
5. Maximum 5 existing terms per taxonomy
6. Maximum 3 suggested new terms per taxonomy
7. Only suggest new terms that genuinely fill vocabulary gaps

Return JSON in this exact format:
{
  "classifications": {
    "taxonomy_slug": [
      {"term": "existing-term", "confidence": 0.95, "reason": "Brief explanation", "in_vocabulary": true},
      {"term": "suggested-new-term", "confidence": 0.80, "reason": "Why this term should exist", "in_vocabulary": false}
    ]
  }
}

IMPORTANT: Be conservative with new term suggestions. Only suggest terms that represent genuine concepts missing from the vocabulary.
PROMPT;
	}

	/**
	 * Get the retry prompt when invalid terms are found.
	 *
	 * @param array<string> $invalid_terms Invalid terms.
	 *
	 * @return string
	 */
	private function getRetryPrompt( array $invalid_terms ): string {
		$terms_list = implode( ', ', $invalid_terms );

		return <<<PROMPT
The following terms you suggested do not exist in the provided vocabulary: {$terms_list}

Do NOT hallucinate or invent terms. You must ONLY select from the exact terms I provided in the vocabulary list.

Please try again. Review the vocabulary carefully and select only terms that actually exist. Return your corrected response in the same JSON format.
PROMPT;
	}

	/**
	 * Get the system prompt for audit mode.
	 *
	 * In audit mode, the LLM can suggest new terms that should exist in the vocabulary.
	 *
	 * @return string
	 */
	private function getAuditSystemPrompt(): string {
		return <<<'PROMPT'
You are a taxonomy classification assistant for a WordPress content management system. Your job is to:
1. Assign relevant taxonomy terms from the provided vocabulary
2. Suggest NEW terms that SHOULD exist in the vocabulary but don't

CRITICAL RULES:

FOR EXISTING VOCABULARY TERMS:
- Use exact slugs from the vocabulary provided
- Assign confidence based on relevance to content
- Mark these with "in_vocabulary": true

FOR SUGGESTED NEW TERMS:
- Only suggest terms that fill genuine gaps in the vocabulary
- Use kebab-case slugs (e.g., "biodiversity-loss", "carbon-sequestration")
- Be conservative — only suggest terms that would genuinely improve the taxonomy
- Mark these with "in_vocabulary": false

CONFIDENCE SCORING:
- 0.9-1.0: Term is an obvious, primary match for the content
- 0.7-0.89: Term is clearly relevant but not the primary focus
- 0.5-0.69: Term is somewhat related but marginal
- Below 0.5: Do not include

REASONING:
- Keep reasons brief (under 15 words)
- For existing terms: explain WHY the term matches
- For suggested new terms: explain WHY this term should exist

Your response must be valid JSON. No markdown, no explanations outside the JSON structure.
PROMPT;
	}

	/**
	 * Get the classification prompt for audit mode.
	 *
	 * @param array<string, mixed> $vocabulary Vocabulary.
	 *
	 * @return string
	 */
	private function getAuditClassificationPrompt( array $vocabulary ): string {
		$vocab_text = $this->formatVocabulary( $vocabulary );

		return <<<PROMPT
Now classify the content using terms from this vocabulary AND suggest new terms that should exist:

EXISTING VOCABULARY:
{$vocab_text}

CLASSIFICATION PROCESS (do this in order):

Step 1: BENCHMARK - Identify matching terms from the vocabulary above
Step 2: GAP ANALYSIS - Identify concepts in the content that SHOULD have a term but don't
Step 3: For each term (existing or suggested), assign confidence and brief reason

RULES:
1. Use exact slugs for existing terms
2. Use kebab-case for suggested new terms (e.g., "climate-adaptation", "net-zero-target")
3. Mark existing terms with "in_vocabulary": true
4. Mark suggested new terms with "in_vocabulary": false
5. Maximum 5 existing terms per taxonomy
6. Maximum 3 suggested new terms per taxonomy
7. Only suggest new terms that genuinely fill vocabulary gaps

Return JSON in this exact format:
{
  "classifications": {
    "taxonomy_slug": [
      {"term": "existing-term", "confidence": 0.95, "reason": "Brief explanation", "in_vocabulary": true},
      {"term": "suggested-new-term", "confidence": 0.80, "reason": "Why this term should exist", "in_vocabulary": false}
    ]
  }
}

IMPORTANT: Be conservative with new term suggestions. Only suggest terms that represent genuine concepts missing from the vocabulary.
PROMPT;
	}

	/**
	 * Format vocabulary for prompt.
	 *
	 * Uses hierarchical formatting when SKOS context is available.
	 *
	 * @param array<string, mixed> $vocabulary Vocabulary.
	 *
	 * @return string
	 */
	private function formatVocabulary( array $vocabulary ): string {
		// Use hierarchical formatting if SKOS context is available.
		if ( $this->hasSkosContext() ) {
			return $this->formatVocabularyWithSkos( $vocabulary );
		}

		// Flat formatting (original behavior).
		$parts = [];

		foreach ( $vocabulary as $taxonomy => $terms ) {
			$parts[] = strtoupper( $taxonomy ) . ':';
			foreach ( $terms as $term ) {
				$line = '  - ' . $term['slug'];
				if ( ! empty( $term['name'] ) && $term['name'] !== $term['slug'] ) {
					$line .= ' ("' . $term['name'] . '")';
				}
				if ( ! empty( $term['description'] ) ) {
					$line .= ' - ' . substr( $term['description'], 0, 100 );
				}
				$parts[] = $line;
			}
			$parts[] = '';
		}

		return implode( "\n", $parts );
	}

	/**
	 * Format vocabulary with SKOS hierarchy.
	 *
	 * Organizes terms hierarchically using broader/narrower relationships.
	 *
	 * @param array<string, mixed> $vocabulary Vocabulary.
	 *
	 * @return string
	 */
	private function formatVocabularyWithSkos( array $vocabulary ): string {
		$concepts = $this->skos_context['concepts'] ?? [];
		$parts    = [];

		foreach ( $vocabulary as $taxonomy => $terms ) {
			$parts[] = strtoupper( $taxonomy ) . ':';
			$parts[] = '(Terms organized hierarchically. Prefer specific terms when content is specific.)';
			$parts[] = '';

			// Build a lookup of terms by slug.
			$term_lookup = [];
			foreach ( $terms as $term ) {
				$term_lookup[ $term['slug'] ] = $term;
			}

			// Find root terms (no broader) and render hierarchically.
			$rendered = [];
			foreach ( $terms as $term ) {
				$slug        = $term['slug'];
				$concept_key = $this->findConceptKey( $taxonomy, $slug, $concepts );

				// Skip if already rendered (as a child).
				if ( isset( $rendered[ $slug ] ) ) {
					continue;
				}

				// Check if this term has a broader (parent) in the vocabulary.
				$has_parent_in_vocab = false;
				if ( null !== $concept_key && isset( $concepts[ $concept_key ]['broader'] ) ) {
					$parent_key  = $concepts[ $concept_key ]['broader'];
					$parent_slug = $this->extractSlugFromKey( $parent_key );
					if ( isset( $term_lookup[ $parent_slug ] ) ) {
						$has_parent_in_vocab = true;
					}
				}

				// Only render root terms here (children are rendered recursively).
				if ( ! $has_parent_in_vocab ) {
					$this->formatTermWithHierarchy(
						$term,
						$taxonomy,
						$concepts,
						$term_lookup,
						$parts,
						$rendered,
						1
					);
				}
			}

			$parts[] = '';
		}

		return implode( "\n", $parts );
	}

	/**
	 * Format a term with its children (recursive).
	 *
	 * @param array<string, mixed>        $term        Term data.
	 * @param string                      $taxonomy    Taxonomy name.
	 * @param array<string, array>        $concepts    SKOS concepts.
	 * @param array<string, array>        $term_lookup All terms by slug.
	 * @param array<string>               $parts       Output parts (by reference).
	 * @param array<string, bool>         $rendered    Already rendered slugs (by reference).
	 * @param int                         $depth       Indentation depth.
	 *
	 * @return void
	 */
	private function formatTermWithHierarchy(
		array $term,
		string $taxonomy,
		array $concepts,
		array $term_lookup,
		array &$parts,
		array &$rendered,
		int $depth
	): void {
		$slug        = $term['slug'];
		$indent      = str_repeat( '  ', $depth );
		$concept_key = $this->findConceptKey( $taxonomy, $slug, $concepts );

		// Build the term line.
		$line = $indent . '- ' . $slug;
		if ( ! empty( $term['name'] ) && $term['name'] !== $slug ) {
			$line .= ' ("' . $term['name'] . '")';
		}

		// Prefer SKOS definition, fallback to WP description.
		$definition = null;
		if ( null !== $concept_key && ! empty( $concepts[ $concept_key ]['definition'] ) ) {
			$definition = $concepts[ $concept_key ]['definition'];
		} elseif ( ! empty( $term['description'] ) ) {
			$definition = $term['description'];
		}

		if ( null !== $definition ) {
			$line .= ' - ' . substr( $definition, 0, 100 );
		}

		$parts[]           = $line;
		$rendered[ $slug ] = true;

		// Render children (narrower terms).
		if ( null !== $concept_key && ! empty( $concepts[ $concept_key ]['narrower'] ) ) {
			foreach ( $concepts[ $concept_key ]['narrower'] as $child_key ) {
				$child_slug = $this->extractSlugFromKey( $child_key );

				// Only render if the child is in our vocabulary and not yet rendered.
				if ( isset( $term_lookup[ $child_slug ] ) && ! isset( $rendered[ $child_slug ] ) ) {
					$this->formatTermWithHierarchy(
						$term_lookup[ $child_slug ],
						$taxonomy,
						$concepts,
						$term_lookup,
						$parts,
						$rendered,
						$depth + 1
					);
				}
			}
		}
	}

	/**
	 * Find the concept key for a taxonomy/slug combination.
	 *
	 * @param string               $taxonomy Taxonomy name.
	 * @param string               $slug     Term slug.
	 * @param array<string, array> $concepts SKOS concepts.
	 *
	 * @return string|null Concept key or null.
	 */
	private function findConceptKey( string $taxonomy, string $slug, array $concepts ): ?string {
		// Try exact match: taxonomy/slug.
		$key = $taxonomy . '/' . $slug;
		if ( isset( $concepts[ $key ] ) ) {
			return $key;
		}

		// Search by slug (fallback).
		foreach ( $concepts as $concept_key => $concept ) {
			if ( ( $concept['slug'] ?? '' ) === $slug ) {
				if ( strpos( $concept_key, $taxonomy . '/' ) === 0 ) {
					return $concept_key;
				}
			}
		}

		return null;
	}

	/**
	 * Extract slug from a concept key.
	 *
	 * @param string $key Concept key (e.g., "category/climate").
	 *
	 * @return string Slug.
	 */
	private function extractSlugFromKey( string $key ): string {
		$parts = explode( '/', $key );
		return end( $parts );
	}

	/**
	 * Parse classification response from LLM.
	 *
	 * @param string $response Response content.
	 *
	 * @return array{classifications: array, error: string|null}
	 */
	private function parseClassificationResponse( string $response ): array {
		if ( empty( $response ) ) {
			return [ 'classifications' => [], 'error' => 'Empty response from LLM' ];
		}

		// Extract JSON from response (may be wrapped in markdown).
		$json = $this->extractJson( $response );

		$parsed = json_decode( $json, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return [
				'classifications' => [],
				'error'           => 'Failed to parse JSON: ' . json_last_error_msg(),
			];
		}

		return [
			'classifications' => $parsed['classifications'] ?? [],
			'error'           => null,
		];
	}

	/**
	 * Extract JSON from response that may contain markdown.
	 *
	 * @param string $content Response content.
	 *
	 * @return string JSON string.
	 */
	private function extractJson( string $content ): string {
		// Try to extract from markdown code block.
		if ( preg_match( '/```(?:json)?\s*\n?(.*?)\n?```/s', $content, $matches ) ) {
			return trim( $matches[1] );
		}

		// Try to find JSON object directly.
		if ( preg_match( '/\{[\s\S]*\}/m', $content, $matches ) ) {
			return $matches[0];
		}

		return $content;
	}

	/**
	 * Classify multiple posts.
	 *
	 * @param array<int>    $post_ids   Post IDs.
	 * @param array<string> $taxonomies Taxonomies to classify against.
	 * @param callable|null $progress   Progress callback.
	 *
	 * @return array<array{post_id: int, post_title: string, classifications: array, error: string|null}>
	 */
	public function classifyPosts( array $post_ids, array $taxonomies, ?callable $progress = null ): array {
		$results = [];
		$total = count( $post_ids );

		foreach ( $post_ids as $index => $post_id ) {
			$results[] = $this->classifyPost( $post_id, $taxonomies );

			if ( $progress ) {
				$progress( $index + 1, $total, $post_id );
			}
		}

		return $results;
	}

	/**
	 * Classify posts by query arguments.
	 *
	 * @param array<string, mixed> $args       Query arguments.
	 * @param array<string>        $taxonomies Taxonomies to classify against.
	 * @param callable|null        $progress   Progress callback.
	 *
	 * @return array<array{post_id: int, post_title: string, classifications: array, error: string|null}>
	 */
	public function classifyByQuery( array $args, array $taxonomies, ?callable $progress = null ): array {
		$posts = $this->content_exporter->getPosts( $args );
		$post_ids = array_column( $posts, 'post_id' );

		return $this->classifyPosts( $post_ids, $taxonomies, $progress );
	}

	/**
	 * Filter classifications by minimum confidence.
	 *
	 * @param array<string, array> $classifications Classifications.
	 *
	 * @return array<string, array>
	 */
	private function filterByConfidence( array $classifications ): array {
		$filtered = [];

		foreach ( $classifications as $taxonomy => $terms ) {
			if ( ! is_array( $terms ) ) {
				continue;
			}

			$filtered[ $taxonomy ] = array_filter( $terms, function ( $term ) {
				return isset( $term['confidence'] ) && $term['confidence'] >= $this->min_confidence;
			} );

			$filtered[ $taxonomy ] = array_values( $filtered[ $taxonomy ] );

			if ( empty( $filtered[ $taxonomy ] ) ) {
				unset( $filtered[ $taxonomy ] );
			}
		}

		return $filtered;
	}

	/**
	 * Validate that suggested terms exist in vocabulary.
	 *
	 * @param array<string, array> $classifications Classifications.
	 * @param array<string, array> $vocabulary      Vocabulary.
	 *
	 * @return array<string, array>
	 */
	private function validateTerms( array $classifications, array $vocabulary ): array {
		$validated = [];

		foreach ( $classifications as $taxonomy => $terms ) {
			if ( ! isset( $vocabulary[ $taxonomy ] ) ) {
				continue;
			}

			$valid_slugs = array_column( $vocabulary[ $taxonomy ], 'slug' );

			$validated[ $taxonomy ] = array_filter( $terms, function ( $term ) use ( $valid_slugs ) {
				return isset( $term['term'] ) && in_array( $term['term'], $valid_slugs, true );
			} );

			$validated[ $taxonomy ] = array_values( $validated[ $taxonomy ] );

			if ( empty( $validated[ $taxonomy ] ) ) {
				unset( $validated[ $taxonomy ] );
			}
		}

		return $validated;
	}

	/**
	 * Validate vocabulary terms and mark in_vocabulary flag.
	 *
	 * This method is used in audit mode to:
	 * - Mark terms from the vocabulary with in_vocabulary: true
	 * - Preserve suggested new terms (in_vocabulary: false)
	 * - Filter out terms that are neither valid nor explicitly suggested
	 *
	 * @param array<string, array> $classifications Classifications.
	 * @param array<string, array> $vocabulary      Vocabulary.
	 *
	 * @return array<string, array>
	 */
	private function validateAndMarkTerms( array $classifications, array $vocabulary ): array {
		$result = [];

		foreach ( $classifications as $taxonomy => $terms ) {
			if ( ! is_array( $terms ) ) {
				continue;
			}

			// Get valid slugs for this taxonomy.
			$valid_slugs = [];
			if ( isset( $vocabulary[ $taxonomy ] ) ) {
				$valid_slugs = array_column( $vocabulary[ $taxonomy ], 'slug' );
			}

			$result[ $taxonomy ] = [];

			foreach ( $terms as $term ) {
				$slug = $term['term'] ?? '';
				if ( empty( $slug ) ) {
					continue;
				}

				$is_valid_slug = in_array( $slug, $valid_slugs, true );
				$explicit_in_vocab = $term['in_vocabulary'] ?? null;

				// Accept term if:
				// 1. It's in the vocabulary (mark as in_vocabulary: true)
				// 2. It's explicitly marked as a suggestion (in_vocabulary: false)
				if ( $is_valid_slug ) {
					$term['in_vocabulary'] = true;
					$result[ $taxonomy ][] = $term;
				} elseif ( $explicit_in_vocab === false ) {
					// Preserve suggested new term.
					$term['in_vocabulary'] = false;
					$result[ $taxonomy ][] = $term;
				}
				// Else: term is invalid and not explicitly suggested, skip it.
			}

			if ( empty( $result[ $taxonomy ] ) ) {
				unset( $result[ $taxonomy ] );
			}
		}

		return $result;
	}

	/**
	 * Set minimum confidence threshold.
	 *
	 * @param float $threshold Threshold (0-1).
	 *
	 * @return self
	 */
	public function setMinConfidence( float $threshold ): self {
		$this->min_confidence = max( 0, min( 1, $threshold ) );
		return $this;
	}

	/**
	 * Enable or disable two-step classification.
	 *
	 * @param bool $enabled Whether to use two-step.
	 *
	 * @return self
	 */
	public function setTwoStep( bool $enabled ): self {
		$this->two_step = $enabled;
		return $this;
	}

	/**
	 * Enable or disable audit mode (gap-filling suggestions).
	 *
	 * When enabled, the LLM can suggest new terms that should exist
	 * in the vocabulary but don't. These are marked with in_vocabulary: false.
	 *
	 * @param bool $enabled Whether to enable audit mode.
	 *
	 * @return self
	 */
	public function setAuditMode( bool $enabled ): self {
		$this->audit_mode = $enabled;
		return $this;
	}

	/**
	 * Check if audit mode is enabled.
	 *
	 * @return bool
	 */
	public function isAuditMode(): bool {
		return $this->audit_mode;
	}

	/**
	 * Set SKOS context for hierarchical vocabulary formatting.
	 *
	 * When set, vocabulary prompts will include hierarchy information
	 * (broader/narrower relationships) and SKOS definitions.
	 *
	 * @param array{concepts: array<string, array>} $skos_data Parsed SKOS data.
	 *
	 * @return self
	 */
	public function setSkosContext( array $skos_data ): self {
		$this->skos_context = $skos_data;
		return $this;
	}

	/**
	 * Check if SKOS context is available.
	 *
	 * @return bool
	 */
	public function hasSkosContext(): bool {
		return null !== $this->skos_context && ! empty( $this->skos_context['concepts'] );
	}

	/**
	 * Check if the classifier is ready to use.
	 *
	 * @return array{ready: bool, errors: array<string>, provider: string}
	 */
	public function checkReadiness(): array {
		$errors = [];
		$provider = $this->client->getProvider();

		if ( ! $this->client->isAvailable() ) {
			if ( 'ollama' === $provider ) {
				$errors[] = 'Ollama is not available. Is it running?';
			} else {
				$errors[] = sprintf( '%s API is not available. Check your API key.', ucfirst( $provider ) );
			}
		} else {
			$models = $this->client->getModels();
			$configured_model = $this->client->getModel();

			if ( 'ollama' === $provider && ! in_array( $configured_model, $models, true ) ) {
				$errors[] = sprintf(
					'Model "%s" not found. Available models: %s',
					$configured_model,
					implode( ', ', $models )
				);
			}
		}

		return [
			'ready'    => empty( $errors ),
			'errors'   => $errors,
			'provider' => $provider,
		];
	}

	/**
	 * Get the LLM client.
	 *
	 * @return LLMClientInterface
	 */
	public function getClient(): LLMClientInterface {
		return $this->client;
	}

	/**
	 * Get the cost tracker.
	 *
	 * @return CostTracker|null
	 */
	public function getCostTracker(): ?CostTracker {
		return $this->cost_tracker;
	}

	/**
	 * Get accumulated usage statistics.
	 *
	 * @return array{input_tokens: int, output_tokens: int, total_tokens: int, requests: int, cost: float, cost_formatted: string}
	 */
	public function getUsage(): array {
		if ( null === $this->cost_tracker ) {
			return [
				'input_tokens'   => 0,
				'output_tokens'  => 0,
				'total_tokens'   => 0,
				'requests'       => 0,
				'cost'           => 0.0,
				'cost_formatted' => 'N/A',
			];
		}

		return $this->cost_tracker->getUsage();
	}

	/**
	 * Reset usage statistics.
	 *
	 * @return void
	 */
	public function resetUsage(): void {
		if ( null !== $this->cost_tracker ) {
			$this->cost_tracker->reset();
		}
	}
}
