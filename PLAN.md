# AI Taxonomy Audit: Benchmark vs Audit Mode Implementation Plan

## Overview

Add two distinct classification modes to separate validation of existing taxonomy assignments from gap-filling suggestions.

| Flag | Behaviour |
|------|-----------|
| `--benchmark` | Validate existing terms only (current behaviour) |
| `--audit` | Benchmark + suggest missing terms (comprehensive) |

`--audit` is a superset — always includes benchmark results plus gap suggestions.

---

## Problem Statement

The current implementation:

1. **Prompts enforce vocabulary-only** (`Classifier.php:306`):
   > "ONLY use terms from the vocabulary I provide. Do not invent, suggest, or hallucinate new terms."

2. **Validation strips non-vocab terms** (`Classifier.php:605-627`):
   - `validateTerms()` removes any suggested terms not in vocabulary before saving

3. **GapAnalyzer runs too late**:
   - Analyzes saved suggestions, but new terms are already filtered out
   - `suggested_new_terms` in gap-analysis is always empty

### Current Output (Benchmark Only)

```json
{
  "classifications": {
    "climate-or-nature-outcome": [
      {"term": "decarbonisation", "confidence": 0.95, "reason": "..."}
    ]
  }
}
```

Only confirms existing vocabulary terms — no gap suggestions.

---

## Proposed Solution

### New Output Structure

```json
{
  "classifications": {
    "climate-or-nature-outcome": [
      {"term": "decarbonisation", "confidence": 0.95, "reason": "...", "in_vocabulary": true},
      {"term": "biodiversity-loss", "confidence": 0.80, "reason": "...", "in_vocabulary": false}
    ]
  }
}
```

Each term includes `in_vocabulary` flag to distinguish:
- **Confirmed terms** (`in_vocabulary: true`) — existing vocab terms AI agrees with
- **Suggested additions** (`in_vocabulary: false`) — new terms AI recommends creating

---

## Implementation Steps

### 1. Add CLI Flag to Commands.php

Location: `src/CLI/Commands.php` in `classify()` method

```php
// Add to OPTIONS docblock (around line 107):
// [--audit]
// : Include gap-filling suggestions for new taxonomy terms.

// Add to method body (around line 163):
$audit_mode = isset( $assoc_args['audit'] );
```

Pass flag to Classifier:
```php
$classifier->setAuditMode( $audit_mode );
```

### 2. Add Audit Mode to Classifier

Location: `src/Core/Classifier.php`

#### 2.1 Add property and setter

```php
/**
 * Whether to include gap-filling suggestions.
 *
 * @var bool
 */
private bool $audit_mode = false;

/**
 * Enable or disable audit mode (gap-filling).
 *
 * @param bool $enabled Whether to enable audit mode.
 *
 * @return self
 */
public function setAuditMode( bool $enabled ): self {
    $this->audit_mode = $enabled;
    return $this;
}
```

#### 2.2 Add audit-mode system prompt

```php
private function getAuditSystemPrompt(): string {
    return <<<'PROMPT'
You are a taxonomy classification assistant for a WordPress content management system. Your job is to:
1. Assign relevant taxonomy terms from the provided vocabulary
2. Suggest NEW terms that SHOULD exist in the vocabulary but don't

CRITICAL RULES:

FOR EXISTING VOCABULARY TERMS:
- Use exact slugs from the vocabulary provided
- Assign confidence based on relevance to content

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

Return JSON in this format:
{
  "classifications": {
    "taxonomy_slug": [
      {"term": "existing-term", "confidence": 0.95, "reason": "Brief explanation", "in_vocabulary": true},
      {"term": "suggested-new-term", "confidence": 0.80, "reason": "Why this term should exist", "in_vocabulary": false}
    ]
  }
}
PROMPT;
}
```

#### 2.3 Add audit-mode classification prompt

```php
private function getAuditClassificationPrompt( array $vocabulary ): string {
    $vocab_text = $this->formatVocabulary( $vocabulary );

    return <<<PROMPT
Now classify the content using terms from this vocabulary AND suggest new terms that should exist:

EXISTING VOCABULARY:
{$vocab_text}

CLASSIFICATION PROCESS:

Step 1: BENCHMARK - Identify matching terms from the vocabulary above
Step 2: GAP ANALYSIS - Identify concepts in the content that SHOULD have a term but don't
Step 3: For each term (existing or suggested), assign confidence and brief reason

RULES:
1. Use exact slugs for existing terms
2. Use kebab-case for suggested new terms
3. Mark existing terms with "in_vocabulary": true
4. Mark suggested new terms with "in_vocabulary": false
5. Maximum 5 existing terms per taxonomy
6. Maximum 3 suggested new terms per taxonomy
7. Only suggest new terms that genuinely fill vocabulary gaps

Return JSON response only.
PROMPT;
}
```

#### 2.4 Modify prompt selection in classifyTwoStep/classifySingleStep

```php
// In classifyTwoStep():
$messages = [
    [
        'role'    => 'system',
        'content' => $this->audit_mode
            ? $this->getAuditSystemPrompt()
            : $this->getSystemPrompt(),
    ],
];

// Later:
$messages[] = [
    'role'    => 'user',
    'content' => $this->audit_mode
        ? $this->getAuditClassificationPrompt( $vocabulary )
        : $this->getClassificationPrompt( $vocabulary ),
];
```

#### 2.5 Modify validation to preserve new terms in audit mode

```php
// In classifyPost(), around line 124:
if ( $this->audit_mode ) {
    // In audit mode: validate vocab terms, preserve suggested new terms
    $classifications = $this->validateAndMarkTerms( $classifications, $vocabulary );
} else {
    // In benchmark mode: filter out non-vocab terms entirely
    $classifications = $this->validateTerms( $classifications, $vocabulary );
}
```

Add new method:

```php
/**
 * Validate vocabulary terms and mark in_vocabulary flag.
 * Preserves suggested new terms for audit mode.
 *
 * @param array<string, array> $classifications Classifications.
 * @param array<string, array> $vocabulary      Vocabulary.
 *
 * @return array<string, array>
 */
private function validateAndMarkTerms( array $classifications, array $vocabulary ): array {
    $result = [];

    foreach ( $classifications as $taxonomy => $terms ) {
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

            // Mark whether term is in vocabulary
            $term['in_vocabulary'] = in_array( $slug, $valid_slugs, true );
            $result[ $taxonomy ][] = $term;
        }

        if ( empty( $result[ $taxonomy ] ) ) {
            unset( $result[ $taxonomy ] );
        }
    }

    return $result;
}
```

### 3. Update Output Handlers

#### 3.1 CSVHandler.php

Add `in_vocabulary` column to CSV export.

#### 3.2 ScriptGenerator.php

Only generate commands for `in_vocabulary: true` terms. List suggested new terms separately as comments.

#### 3.3 SuggestionStore.php

Update summary to include:
```php
$summary = [
    'total_posts'            => count( $results ),
    'posts_with_suggestions' => $posts_with_suggestions,
    'confirmed_terms'        => $confirmed_count,
    'suggested_new_terms'    => $suggested_count,
    'by_taxonomy'            => $by_taxonomy,
];
```

### 4. Update GapAnalyzer Integration

The `gap-analysis` command can now find `suggested_new_terms` directly from audit output:

```php
// In gap-analysis command or GapAnalyzer:
foreach ( $results as $result ) {
    foreach ( $result['classifications'] as $taxonomy => $terms ) {
        foreach ( $terms as $term ) {
            if ( ! $term['in_vocabulary'] ) {
                // This is a suggested new term
                $suggested_new_terms[] = [
                    'term'       => $term['term'],
                    'taxonomy'   => $taxonomy,
                    'confidence' => $term['confidence'],
                    'reason'     => $term['reason'] ?? '',
                    'post_id'    => $result['post_id'],
                ];
            }
        }
    }
}
```

---

## CLI Usage Examples

### Benchmark Mode (Default)

```bash
# Validate existing taxonomy assignments
wp taxonomy-audit classify --post_type=clause --taxonomies=climate-or-nature-outcome,jurisdiction --limit=20
```

Output: Only confirms terms from vocabulary.

### Audit Mode

```bash
# Benchmark + gap-filling suggestions
wp taxonomy-audit classify --audit --post_type=clause --taxonomies=climate-or-nature-outcome,jurisdiction --limit=20
```

Output: Confirms vocabulary terms AND suggests new terms that should exist.

### Full Workflow

```bash
# 1. Run audit classification
wp taxonomy-audit classify --audit --post_type=clause --limit=50 --format=json --save-run

# 2. Review suggestions in output file
# - Confirmed terms can be applied directly
# - Suggested new terms require human review

# 3. Create approved new terms manually
wp term create climate-or-nature-outcome biodiversity-loss --slug=biodiversity-loss

# 4. Re-run to classify with new vocabulary
wp taxonomy-audit classify --post_type=clause --limit=50
```

---

## Testing Checklist

- [ ] `--benchmark` (default) produces vocab-only output (no `in_vocabulary` flag needed)
- [ ] `--audit` produces mixed output with `in_vocabulary` flags
- [ ] Suggested new terms use valid kebab-case slugs
- [ ] CSV export includes `in_vocabulary` column in audit mode
- [ ] Script generator only creates commands for confirmed terms
- [ ] Gap analysis correctly identifies suggested new terms from audit output
- [ ] Run storage correctly summarises confirmed vs suggested counts

---

## Files to Modify

| File | Changes |
|------|---------|
| `src/CLI/Commands.php` | Add `--audit` flag, pass to Classifier |
| `src/Core/Classifier.php` | Add audit mode, new prompts, preserve new terms |
| `src/Output/CSVHandler.php` | Add `in_vocabulary` column |
| `src/Output/ScriptGenerator.php` | Separate confirmed from suggested |
| `src/Output/SuggestionStore.php` | Update summary stats |

---

## Notes

- Keep `--benchmark` as implicit default for backwards compatibility
- The `--audit` flag is additive — always includes benchmark
- Consider adding `--gaps-only` in future to skip benchmark entirely
- Prompt engineering may need iteration to get quality gap suggestions
