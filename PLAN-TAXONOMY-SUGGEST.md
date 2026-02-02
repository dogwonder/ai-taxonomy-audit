# Plan: ai-taxonomy-suggester

A real-time taxonomy suggestion plugin for the WordPress block editor, using the official WordPress PHP AI Client.

---

## Overview

### What This Is

A **separate plugin** that provides real-time taxonomy suggestions while writing content in the block editor.

### Relationship to ai-taxonomy-audit

| Plugin | Purpose | When | Where |
|--------|---------|------|-------|
| `ai-taxonomy-audit` | Batch audit existing content | After publish | CLI |
| `ai-taxonomy-suggester` | Real-time suggestions | While writing | Block editor |

**Shared infrastructure:** Both plugins can share vocabulary extraction and SKOS parsing logic via a common library.

### Why Separate Plugins

1. **Different use cases** — auditing vs authoring
2. **Different dependencies** — CLI vs Gutenberg/React
3. **Independent release cycles**
4. **Users may want one without the other
5. **Cleaner architecture**

---

## Goals

1. **Real-time suggestions** — Analyze content and suggest terms as authors write
2. **Native WordPress experience** — Gutenberg sidebar, familiar UI patterns
3. **Use official WordPress AI stack** — `php-ai-client` for provider abstraction
4. **SKOS-aware** — Leverage hierarchical vocabulary when available
5. **Future-proof** — Ready for Abilities API integration

### Non-Goals (v1)

- Automatic term assignment (always human approval)
- Suggesting new terms that don't exist (audit mode)
- Batch processing (that's what ai-taxonomy-audit does)
- Training/fine-tuning models

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                      BLOCK EDITOR (Browser)                      │
│  ┌───────────────────────────────────────────────────────────┐  │
│  │                    Editor Canvas                           │  │
│  │  [Post content being written...]                          │  │
│  └───────────────────────────────────────────────────────────┘  │
│                              │                                   │
│  ┌───────────────────────────▼───────────────────────────────┐  │
│  │              Taxonomy Suggester Sidebar                    │  │
│  │  ┌─────────────────────────────────────────────────────┐  │  │
│  │  │  Category Suggestions                                │  │  │
│  │  │  ┌─────────────────────────────────────────────┐    │  │  │
│  │  │  │ ☐ climate (95%)           [+ Add]           │    │  │  │
│  │  │  │ ☐ mitigation (82%)        [+ Add]           │    │  │  │
│  │  │  │ ☐ adaptation (71%)        [+ Add]           │    │  │  │
│  │  │  └─────────────────────────────────────────────┘    │  │  │
│  │  │                                                      │  │  │
│  │  │  Tag Suggestions                                     │  │  │
│  │  │  ┌─────────────────────────────────────────────┐    │  │  │
│  │  │  │ ☐ net-zero (88%)          [+ Add]           │    │  │  │
│  │  │  │ ☐ sustainability (76%)    [+ Add]           │    │  │  │
│  │  │  └─────────────────────────────────────────────┘    │  │  │
│  │  │                                                      │  │  │
│  │  │  [🔄 Refresh]  [⚙️ Settings]                        │  │  │
│  │  └─────────────────────────────────────────────────────┘  │  │
│  └───────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
                              │
                              │ REST API
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      PHP BACKEND (Server)                        │
│                                                                  │
│  ┌───────────────────────────────────────────────────────────┐  │
│  │  REST Endpoint: POST /wp-json/ai-taxonomy-suggester/v1/suggest │
│  └───────────────────────────────────────────────────────────┘  │
│                              │                                   │
│                              ▼                                   │
│  ┌───────────────────────────────────────────────────────────┐  │
│  │                    Suggester Service                       │  │
│  │  • Extract vocabulary from WordPress                       │  │
│  │  • Load SKOS context (if configured)                      │  │
│  │  • Format prompt with vocabulary                          │  │
│  │  • Call AI provider                                       │  │
│  │  • Parse and validate response                            │  │
│  └───────────────────────────────────────────────────────────┘  │
│                              │                                   │
│                              ▼                                   │
│  ┌───────────────────────────────────────────────────────────┐  │
│  │              WordPress PHP AI Client                       │  │
│  │  AiClient::prompt($content)                               │  │
│  │      ->usingProvider('openai')                            │  │
│  │      ->generateText()                                     │  │
│  └───────────────────────────────────────────────────────────┘  │
│                              │                                   │
│                              ▼                                   │
│  ┌───────────────────────────────────────────────────────────┐  │
│  │                      AI Provider                          │  │
│  │         OpenAI / Google Gemini / Ollama (local)           │  │
│  └───────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
```

---

## Plugin Structure

```
wp-content/plugins/ai-taxonomy-suggester/
├── ai-taxonomy-suggester.php      # Plugin bootstrap
├── composer.json
├── package.json
├── webpack.config.js
│
├── src/
│   ├── Admin/
│   │   └── SettingsPage.php       # Admin settings UI (provider, model, taxonomies)
│   │
│   ├── Core/
│   │   ├── Suggester.php          # Main suggestion logic
│   │   ├── PromptBuilder.php      # Build prompts from vocabulary
│   │   ├── ResponseParser.php     # Parse AI responses
│   │   ├── VocabularyProvider.php # Get terms from WP + SKOS
│   │   └── ConfigHelper.php       # Read wp-config constants
│   │
│   ├── REST/
│   │   └── SuggestEndpoint.php    # REST API handler
│   │
│   └── Abilities/
│       └── AbilityRegistration.php # Future: Abilities API
│
├── assets/
│   └── js/
│       └── editor/
│           ├── index.js           # Entry point
│           ├── sidebar.js         # Sidebar panel component
│           ├── suggestion-list.js # Suggestion display
│           └── api.js             # REST API client
│
├── languages/
│   └── ai-taxonomy-suggester.pot
│
└── tests/
    ├── Unit/
    │   ├── SuggesterTest.php
    │   └── PromptBuilderTest.php
    └── Integration/
        └── RESTEndpointTest.php
```

---

## Implementation Phases

### Phase 1: Foundation (Week 1-2)

**Goal:** Plugin scaffold, settings, basic REST API

#### 1.1 Plugin Bootstrap

```php
<?php
/**
 * Plugin Name: AI Taxonomy Suggester
 * Description: Real-time taxonomy suggestions in the block editor
 * Version: 0.1.0
 * Requires PHP: 8.0
 * Requires at least: 6.4
 */

declare(strict_types=1);

namespace AiTaxonomySuggester;

// Composer autoload
require_once __DIR__ . '/vendor/autoload.php';

// Bootstrap
add_action('init', function() {
    // Register REST routes
    add_action('rest_api_init', [REST\SuggestEndpoint::class, 'register']);

    // Register editor assets
    add_action('enqueue_block_editor_assets', function() {
        wp_enqueue_script(
            'ai-taxonomy-suggester-editor',
            plugins_url('build/editor.js', __FILE__),
            ['wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch'],
            '0.1.0',
            true
        );

        wp_localize_script('ai-taxonomy-suggester-editor', 'aiTaxonomySuggester', [
            'nonce' => wp_create_nonce('wp_rest'),
            'taxonomies' => get_option('ai_taxonomy_suggester_taxonomies', ['category', 'post_tag']),
        ]);
    });
});

// Admin settings
if (is_admin()) {
    add_action('admin_menu', [Admin\SettingsPage::class, 'register']);
}
```

#### 1.2 Settings Page

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `provider` | select | `openai` | AI provider (openai, google, ollama) |
| `model` | text | `gpt-4o-mini` | Model identifier |
| `taxonomies` | multiselect | `category,post_tag` | Taxonomies to suggest |
| `skos_file` | file | — | Optional SKOS Turtle file path |
| `min_confidence` | number | `0.7` | Minimum confidence threshold |
| `max_suggestions` | number | `5` | Max suggestions per taxonomy |

**API Keys:** Defined in `wp-config.php` (not stored in database):

```php
// wp-config.php
define('AI_TAXONOMY_OPENAI_API_KEY', 'sk-...');
define('AI_TAXONOMY_GOOGLE_API_KEY', 'AIza...');

// For local Ollama (no API key needed)
define('AI_TAXONOMY_OLLAMA_HOST', 'http://localhost:11434');
```

**Local Model Support (Ollama):**

For zero-cost development or privacy-sensitive environments:

```bash
# Install Ollama
brew install ollama

# Pull a capable model
ollama pull llama3.2

# Ollama runs locally on port 11434
```

Select `ollama` as provider and specify model (e.g., `llama3.2`, `mistral`).

#### 1.3 composer.json

```json
{
    "name": "dgwltd/ai-taxonomy-suggester",
    "description": "Real-time taxonomy suggestions for WordPress block editor",
    "type": "wordpress-plugin",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=8.0",
        "wordpress/php-ai-client": "^0.4"
    },
    "autoload": {
        "psr-4": {
            "AiTaxonomySuggester\\": "src/"
        }
    }
}
```

---

### Phase 2: Core Logic (Week 2-3)

**Goal:** Vocabulary extraction, prompt building, AI integration

#### 2.1 VocabularyProvider

```php
<?php

declare(strict_types=1);

namespace AiTaxonomySuggester\Core;

/**
 * Provides vocabulary from WordPress taxonomies and optional SKOS.
 */
class VocabularyProvider {

    private ?SKOSParser $skos_parser = null;
    private ?array $skos_data = null;

    public function __construct(?string $skos_file = null) {
        if ($skos_file && file_exists($skos_file)) {
            $this->skos_parser = new SKOSParser();
            $this->skos_data = $this->skos_parser->parse($skos_file);
        }
    }

    /**
     * Get vocabulary for specified taxonomies.
     */
    public function getVocabulary(array $taxonomies): array {
        $vocabulary = [];

        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms([
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
            ]);

            if (is_wp_error($terms)) {
                continue;
            }

            $vocabulary[$taxonomy] = array_map(function($term) use ($taxonomy) {
                $data = [
                    'slug'        => $term->slug,
                    'name'        => $term->name,
                    'description' => $term->description,
                    'term_id'     => $term->term_id,
                ];

                // Enrich with SKOS if available
                if ($this->skos_data) {
                    $data = $this->enrichWithSkos($data, $taxonomy);
                }

                return $data;
            }, $terms);
        }

        return $vocabulary;
    }

    /**
     * Check if SKOS context is available.
     */
    public function hasSkosContext(): bool {
        return $this->skos_data !== null && !empty($this->skos_data['concepts']);
    }

    /**
     * Get SKOS data.
     */
    public function getSkosData(): ?array {
        return $this->skos_data;
    }

    private function enrichWithSkos(array $term, string $taxonomy): array {
        $concepts = $this->skos_data['concepts'] ?? [];
        $key = $taxonomy . '/' . $term['slug'];

        if (isset($concepts[$key])) {
            $concept = $concepts[$key];
            $term['skos_definition'] = $concept['definition'] ?? null;
            $term['skos_broader'] = $concept['broader'] ?? null;
            $term['skos_narrower'] = $concept['narrower'] ?? [];
        }

        return $term;
    }
}
```

#### 2.2 PromptBuilder

```php
<?php

declare(strict_types=1);

namespace AiTaxonomySuggester\Core;

/**
 * Builds prompts for taxonomy suggestion.
 */
class PromptBuilder {

    /**
     * Build system instruction for taxonomy suggestion.
     */
    public function buildSystemInstruction(): string {
        return <<<'PROMPT'
You are a taxonomy classification assistant. Your job is to suggest the most relevant taxonomy terms for content.

RULES:
1. ONLY suggest terms from the vocabulary provided
2. Return valid JSON with confidence scores (0-1)
3. Be conservative - only suggest terms you're confident about
4. Prefer specific terms over general ones when content warrants it

CONFIDENCE SCORING:
- 0.9-1.0: Primary, obvious match
- 0.7-0.89: Clearly relevant
- 0.5-0.69: Somewhat related
- Below 0.5: Do not include
PROMPT;
    }

    /**
     * Build user prompt with content and vocabulary.
     */
    public function buildUserPrompt(string $content, array $vocabulary, bool $has_skos = false): string {
        $vocab_text = $has_skos
            ? $this->formatVocabularyWithHierarchy($vocabulary)
            : $this->formatVocabularyFlat($vocabulary);

        return <<<PROMPT
Suggest taxonomy terms for this content:

CONTENT:
{$content}

VOCABULARY:
{$vocab_text}

Return JSON in this exact format:
{
  "suggestions": {
    "taxonomy_slug": [
      {"term": "term-slug", "confidence": 0.95}
    ]
  }
}

Only include terms with confidence >= 0.5.
PROMPT;
    }

    private function formatVocabularyFlat(array $vocabulary): string {
        $parts = [];

        foreach ($vocabulary as $taxonomy => $terms) {
            $parts[] = strtoupper($taxonomy) . ':';
            foreach ($terms as $term) {
                $line = '  - ' . $term['slug'];
                if (!empty($term['name']) && $term['name'] !== $term['slug']) {
                    $line .= ' ("' . $term['name'] . '")';
                }
                if (!empty($term['description'])) {
                    $line .= ' - ' . substr($term['description'], 0, 80);
                }
                $parts[] = $line;
            }
            $parts[] = '';
        }

        return implode("\n", $parts);
    }

    private function formatVocabularyWithHierarchy(array $vocabulary): string {
        // Similar to Classifier::formatVocabularyWithSkos()
        // Displays terms in tree structure with indentation
        // ... implementation ...
    }
}
```

#### 2.3 Suggester Service

```php
<?php

declare(strict_types=1);

namespace AiTaxonomySuggester\Core;

use WordPress\AI\AiClient;

/**
 * Main suggestion service.
 */
class Suggester {

    private VocabularyProvider $vocabulary_provider;
    private PromptBuilder $prompt_builder;
    private ResponseParser $response_parser;

    private string $provider;
    private string $model;
    private float $min_confidence;
    private int $max_suggestions;

    public function __construct(array $config = []) {
        $this->provider = $config['provider'] ?? 'openai';
        $this->model = $config['model'] ?? $this->getDefaultModel($this->provider);
        $this->min_confidence = $config['min_confidence'] ?? 0.7;
        $this->max_suggestions = $config['max_suggestions'] ?? 5;

        $skos_file = $config['skos_file'] ?? null;

        $this->vocabulary_provider = new VocabularyProvider($skos_file);
        $this->prompt_builder = new PromptBuilder();
        $this->response_parser = new ResponseParser();
    }

    /**
     * Get default model for provider.
     */
    private function getDefaultModel(string $provider): string {
        return match($provider) {
            'openai' => 'gpt-4o-mini',
            'google' => 'gemini-1.5-flash',
            'ollama' => 'llama3.2',
            default => 'gpt-4o-mini',
        };
    }

    /**
     * Get API key from wp-config.php constants.
     */
    private function getApiKey(): ?string {
        return match($this->provider) {
            'openai' => defined('AI_TAXONOMY_OPENAI_API_KEY') ? AI_TAXONOMY_OPENAI_API_KEY : null,
            'google' => defined('AI_TAXONOMY_GOOGLE_API_KEY') ? AI_TAXONOMY_GOOGLE_API_KEY : null,
            'ollama' => null, // Ollama doesn't need API key
            default => null,
        };
    }

    /**
     * Get Ollama host from wp-config.php.
     */
    private function getOllamaHost(): string {
        return defined('AI_TAXONOMY_OLLAMA_HOST')
            ? AI_TAXONOMY_OLLAMA_HOST
            : 'http://localhost:11434';
    }

    /**
     * Get taxonomy suggestions for content.
     *
     * @param string $content Post content to analyze.
     * @param array $taxonomies Taxonomies to suggest for.
     *
     * @return array{suggestions: array, usage: array}
     */
    public function suggest(string $content, array $taxonomies): array {
        // Get vocabulary
        $vocabulary = $this->vocabulary_provider->getVocabulary($taxonomies);

        if (empty($vocabulary)) {
            return ['suggestions' => [], 'usage' => []];
        }

        // Build prompts
        $system = $this->prompt_builder->buildSystemInstruction();
        $user = $this->prompt_builder->buildUserPrompt(
            $content,
            $vocabulary,
            $this->vocabulary_provider->hasSkosContext()
        );

        // Call AI
        $response = AiClient::prompt($user)
            ->usingProvider($this->provider)
            ->usingModel($this->model)
            ->usingSystemInstruction($system)
            ->usingTemperature(0.3)
            ->generateText();

        // Parse response
        $suggestions = $this->response_parser->parse($response);

        // Filter and validate
        $suggestions = $this->filterSuggestions($suggestions, $vocabulary);

        return [
            'suggestions' => $suggestions,
            'usage' => [], // TODO: Extract from response if available
        ];
    }

    private function filterSuggestions(array $suggestions, array $vocabulary): array {
        $filtered = [];

        foreach ($suggestions as $taxonomy => $terms) {
            if (!isset($vocabulary[$taxonomy])) {
                continue;
            }

            $valid_slugs = array_column($vocabulary[$taxonomy], 'slug');
            $taxonomy_suggestions = [];

            foreach ($terms as $term) {
                // Validate term exists
                if (!in_array($term['term'], $valid_slugs, true)) {
                    continue;
                }

                // Filter by confidence
                if (($term['confidence'] ?? 0) < $this->min_confidence) {
                    continue;
                }

                $taxonomy_suggestions[] = $term;
            }

            // Sort by confidence and limit
            usort($taxonomy_suggestions, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
            $filtered[$taxonomy] = array_slice($taxonomy_suggestions, 0, $this->max_suggestions);
        }

        return $filtered;
    }
}
```

---

### Phase 3: REST API (Week 3)

**Goal:** Expose suggestion service via REST

#### 3.1 REST Endpoint

```php
<?php

declare(strict_types=1);

namespace AiTaxonomySuggester\REST;

use AiTaxonomySuggester\Core\Suggester;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class SuggestEndpoint {

    public static function register(): void {
        register_rest_route('ai-taxonomy-suggester/v1', '/suggest', [
            'methods' => 'POST',
            'callback' => [self::class, 'handle'],
            'permission_callback' => [self::class, 'check_permission'],
            'args' => [
                'content' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'wp_kses_post',
                ],
                'taxonomies' => [
                    'required' => false,
                    'type' => 'array',
                    'default' => ['category', 'post_tag'],
                    'items' => ['type' => 'string'],
                ],
            ],
        ]);
    }

    public static function check_permission(): bool {
        return current_user_can('edit_posts');
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $content = $request->get_param('content');
        $taxonomies = $request->get_param('taxonomies');

        // Validate content length
        if (strlen($content) < 50) {
            return new WP_Error(
                'content_too_short',
                __('Content must be at least 50 characters for meaningful suggestions.', 'ai-taxonomy-suggester'),
                ['status' => 400]
            );
        }

        // Get settings
        $config = [
            'provider' => get_option('ai_taxonomy_suggester_provider', 'openai'),
            'model' => get_option('ai_taxonomy_suggester_model', 'gpt-4o-mini'),
            'min_confidence' => (float) get_option('ai_taxonomy_suggester_min_confidence', 0.7),
            'max_suggestions' => (int) get_option('ai_taxonomy_suggester_max_suggestions', 5),
            'skos_file' => get_option('ai_taxonomy_suggester_skos_file'),
        ];

        try {
            $suggester = new Suggester($config);
            $result = $suggester->suggest($content, $taxonomies);

            return new WP_REST_Response([
                'success' => true,
                'suggestions' => $result['suggestions'],
                'usage' => $result['usage'],
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'suggestion_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }
}
```

#### 3.2 API Response Format

```json
{
  "success": true,
  "suggestions": {
    "category": [
      {"term": "climate", "confidence": 0.95, "name": "Climate"},
      {"term": "mitigation", "confidence": 0.82, "name": "Mitigation"}
    ],
    "post_tag": [
      {"term": "net-zero", "confidence": 0.88, "name": "Net Zero"},
      {"term": "sustainability", "confidence": 0.76, "name": "Sustainability"}
    ]
  },
  "usage": {
    "input_tokens": 450,
    "output_tokens": 120
  }
}
```

---

### Phase 4: Editor Integration (Week 4-5)

**Goal:** Gutenberg sidebar panel

#### 4.1 package.json

```json
{
  "name": "ai-taxonomy-suggester",
  "version": "0.1.0",
  "scripts": {
    "build": "wp-scripts build",
    "start": "wp-scripts start",
    "lint": "wp-scripts lint-js"
  },
  "devDependencies": {
    "@wordpress/scripts": "^27.0.0"
  },
  "dependencies": {
    "@wordpress/api-fetch": "^6.0.0",
    "@wordpress/components": "^27.0.0",
    "@wordpress/data": "^9.0.0",
    "@wordpress/edit-post": "^7.0.0",
    "@wordpress/element": "^5.0.0",
    "@wordpress/plugins": "^6.0.0"
  }
}
```

#### 4.2 Sidebar Component (React)

```jsx
// assets/js/editor/sidebar.js

import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/edit-post';
import { useSelect, useDispatch } from '@wordpress/data';
import { useState, useCallback } from '@wordpress/element';
import {
    Panel,
    PanelBody,
    Button,
    Spinner,
    Notice,
    CheckboxControl
} from '@wordpress/components';
import { registerPlugin } from '@wordpress/plugins';
import apiFetch from '@wordpress/api-fetch';

const PLUGIN_NAME = 'ai-taxonomy-suggester';

function TaxonomySuggesterSidebar() {
    const [suggestions, setSuggestions] = useState({});
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    // Get post content
    const content = useSelect((select) => {
        const { getEditedPostContent } = select('core/editor');
        return getEditedPostContent();
    }, []);

    // Get current terms
    const currentTerms = useSelect((select) => {
        const { getEditedPostAttribute } = select('core/editor');
        return {
            categories: getEditedPostAttribute('categories') || [],
            tags: getEditedPostAttribute('tags') || [],
        };
    }, []);

    // Dispatch for editing post
    const { editPost } = useDispatch('core/editor');

    // Fetch suggestions
    const fetchSuggestions = useCallback(async () => {
        if (content.length < 50) {
            setError('Content too short for meaningful suggestions.');
            return;
        }

        setLoading(true);
        setError(null);

        try {
            const response = await apiFetch({
                path: '/ai-taxonomy-suggester/v1/suggest',
                method: 'POST',
                data: {
                    content: content,
                    taxonomies: window.aiTaxonomySuggester?.taxonomies || ['category', 'post_tag'],
                },
            });

            if (response.success) {
                setSuggestions(response.suggestions);
            } else {
                setError('Failed to get suggestions.');
            }
        } catch (err) {
            setError(err.message || 'An error occurred.');
        } finally {
            setLoading(false);
        }
    }, [content]);

    // Add term to post
    const addTerm = useCallback((taxonomy, termSlug, termId) => {
        const attribute = taxonomy === 'category' ? 'categories' : 'tags';
        const current = taxonomy === 'category' ? currentTerms.categories : currentTerms.tags;

        if (!current.includes(termId)) {
            editPost({ [attribute]: [...current, termId] });
        }
    }, [currentTerms, editPost]);

    return (
        <>
            <PluginSidebarMoreMenuItem target={PLUGIN_NAME}>
                AI Taxonomy Suggester
            </PluginSidebarMoreMenuItem>

            <PluginSidebar
                name={PLUGIN_NAME}
                title="AI Taxonomy"
                icon="tag"
            >
                <Panel>
                    <PanelBody title="Suggestions" initialOpen={true}>
                        <Button
                            variant="primary"
                            onClick={fetchSuggestions}
                            disabled={loading}
                            style={{ marginBottom: '16px', width: '100%' }}
                        >
                            {loading ? <Spinner /> : 'Get Suggestions'}
                        </Button>

                        {error && (
                            <Notice status="error" isDismissible={false}>
                                {error}
                            </Notice>
                        )}

                        {Object.entries(suggestions).map(([taxonomy, terms]) => (
                            <div key={taxonomy} style={{ marginBottom: '16px' }}>
                                <h4 style={{ textTransform: 'capitalize', marginBottom: '8px' }}>
                                    {taxonomy.replace('_', ' ')}
                                </h4>
                                {terms.map((term) => (
                                    <SuggestionItem
                                        key={term.term}
                                        term={term}
                                        taxonomy={taxonomy}
                                        onAdd={addTerm}
                                    />
                                ))}
                            </div>
                        ))}

                        {Object.keys(suggestions).length === 0 && !loading && !error && (
                            <p style={{ color: '#757575' }}>
                                Click "Get Suggestions" to analyze your content.
                            </p>
                        )}
                    </PanelBody>
                </Panel>
            </PluginSidebar>
        </>
    );
}

function SuggestionItem({ term, taxonomy, onAdd }) {
    const confidence = Math.round(term.confidence * 100);
    const confidenceColor = confidence >= 90 ? '#00a32a' : confidence >= 70 ? '#dba617' : '#757575';

    return (
        <div style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            padding: '8px',
            borderBottom: '1px solid #e0e0e0',
        }}>
            <div>
                <span style={{ fontWeight: 500 }}>{term.name || term.term}</span>
                <span style={{
                    marginLeft: '8px',
                    fontSize: '12px',
                    color: confidenceColor,
                }}>
                    {confidence}%
                </span>
            </div>
            <Button
                variant="secondary"
                isSmall
                onClick={() => onAdd(taxonomy, term.term, term.term_id)}
            >
                + Add
            </Button>
        </div>
    );
}

registerPlugin(PLUGIN_NAME, {
    render: TaxonomySuggesterSidebar,
});
```

---

### Phase 5: Polish & Testing (Week 5-6)

#### 5.1 Features to Add

- [ ] Show already-assigned terms differently (greyed out or hidden)
- [ ] Keyboard shortcut to open sidebar and trigger suggestions
- [ ] Loading states and animations
- [ ] Error recovery and retry
- [ ] Content length warning (suggest minimum for good results)
- [ ] Ollama connection test in settings

#### 5.2 Compute Efficiency

**Rational compute usage without cost display:**

| Strategy | Implementation |
|----------|----------------|
| **Manual trigger only** | No auto-suggest burning tokens in background |
| **Minimum content length** | Require 50+ chars before allowing suggestion |
| **Smart model defaults** | Use `gpt-4o-mini` / `gemini-1.5-flash` (fast & cheap) |
| **Vocabulary caching** | Cache extracted vocabulary (transient, 1 hour) |
| **Content truncation** | Limit content sent to API (~4000 chars max) |
| **Local model option** | Ollama = zero API cost for development |

```php
// Example: Content truncation for rational token usage
private function prepareContent(string $content): string {
    $content = wp_strip_all_tags($content);
    $content = preg_replace('/\s+/', ' ', $content);

    // Truncate to ~4000 chars (roughly 1000 tokens)
    if (strlen($content) > 4000) {
        $content = substr($content, 0, 4000) . '...';
    }

    return trim($content);
}
```

#### 5.3 Testing

| Test Type | Coverage |
|-----------|----------|
| Unit | Suggester, PromptBuilder, ResponseParser |
| Integration | REST endpoint with mocked AI |
| E2E | Cypress/Playwright for editor sidebar |

#### 5.4 Performance

- Cache vocabulary (transient, 1 hour)
- Limit content length sent to API (see 5.2)
- Show loading states during API calls
- Graceful degradation if Ollama unavailable

---

### Phase 6: Abilities API (Future)

When WordPress Abilities API reaches stable:

```php
<?php

namespace AiTaxonomySuggester\Abilities;

/**
 * Register with WordPress Abilities API.
 */
class AbilityRegistration {

    public static function register(): void {
        if (!function_exists('register_ability')) {
            return;
        }

        register_ability('suggest-taxonomy-terms', [
            'label' => __('Suggest Taxonomy Terms', 'ai-taxonomy-suggester'),
            'description' => __('Analyze content and suggest relevant taxonomy terms', 'ai-taxonomy-suggester'),
            'callback' => [self::class, 'execute'],
            'permissions' => ['edit_posts'],
            'parameters' => [
                'content' => [
                    'type' => 'string',
                    'required' => true,
                    'description' => 'The content to analyze',
                ],
                'taxonomies' => [
                    'type' => 'array',
                    'default' => ['category', 'post_tag'],
                    'description' => 'Taxonomies to suggest terms for',
                ],
            ],
            'returns' => [
                'type' => 'object',
                'description' => 'Suggested terms organized by taxonomy',
            ],
        ]);
    }

    public static function execute(array $params): array {
        $suggester = new \AiTaxonomySuggester\Core\Suggester([
            // Load from settings
        ]);

        return $suggester->suggest(
            $params['content'],
            $params['taxonomies']
        );
    }
}
```

---

## Shared Code Strategy

### Option A: Composer Package (Recommended)

Extract shared code to a separate package:

```
dgwltd/taxonomy-vocabulary-lib/
├── src/
│   ├── SKOSParser.php
│   ├── TaxonomyExtractor.php
│   └── VocabularyFormatter.php
└── composer.json
```

Both plugins depend on it:

```json
{
    "require": {
        "dgwltd/taxonomy-vocabulary-lib": "^1.0"
    }
}
```

### Option B: Copy Essential Files

For v1, simply copy `SKOSParser.php` to the new plugin. Refactor to shared package later.

---

## Configuration Comparison

| Setting | ai-taxonomy-audit | ai-taxonomy-suggester |
|---------|-------------------|----------------------|
| Provider | `--provider` flag | Settings page |
| Model | `--model` flag | Settings page |
| SKOS file | `--skos-context` flag | Settings page |
| Taxonomies | `--taxonomies` flag | Settings page |
| Min confidence | `--min-confidence` flag | Settings page |
| API keys | `wp-config.php` | `wp-config.php` |
| Local models | `--provider=ollama` | Settings page (Ollama) |

---

## Timeline Summary

| Phase | Duration | Deliverable |
|-------|----------|-------------|
| 1. Foundation | 1-2 weeks | Plugin scaffold, settings, composer setup |
| 2. Core Logic | 1 week | Suggester service, vocabulary provider |
| 3. REST API | 1 week | Endpoint, validation, error handling |
| 4. Editor | 1-2 weeks | Gutenberg sidebar (manual trigger), term addition |
| 5. Polish | 1 week | Testing, compute efficiency, Ollama support |
| 6. Abilities | Future | Abilities API registration |

**Total: ~6 weeks for v1.0**

---

## Success Criteria

### v1.0 Release

- [ ] Settings page with provider configuration (provider, model, taxonomies)
- [ ] API keys via `wp-config.php` constants
- [ ] Ollama (local model) support working
- [ ] REST API endpoint functional
- [ ] Gutenberg sidebar displays suggestions (manual trigger)
- [ ] Click to add terms to post
- [ ] SKOS context support (optional)
- [ ] Basic error handling
- [ ] 80%+ unit test coverage

### v1.1 (Post-Release)

- [ ] Auto-suggest on content change (opt-in)
- [ ] Multiple provider support tested (OpenAI, Google, Ollama)
- [ ] Abilities API integration
- [ ] Performance optimization (vocabulary caching)
- [ ] Keyboard shortcuts

---

## Configuration Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| **API key storage** | `wp-config.php` constants | Security best practice, keeps secrets out of database |
| **Editor support** | Gutenberg-only | Modern workflow, no Classic Editor overhead |
| **Auto-suggest** | Manual trigger (disabled by default) | Rational compute usage, user controls when to call API |
| **Cost display** | No | Unnecessary complexity; smart defaults handle compute |
| **Local models** | Supported via Ollama | Zero-cost option for development and privacy-conscious users |
| **Shared library** | Copy files initially | Composer package can come later if needed |

---

## Related Documentation

- [WordPress PHP AI Client](https://github.com/WordPress/php-ai-client)
- [WordPress Abilities API](https://github.com/WordPress/abilities-api)
- [ai-taxonomy-audit README](./README.md)
- [BENCHMARKING.md](./BENCHMARKING.md) — Measuring impact
