# AI Taxonomy Audit

WP-CLI tool for human-in-the-loop taxonomy enrichment using LLM (Ollama, OpenAI, or OpenRouter).

Classifies WordPress posts against controlled vocabularies and generates WP-CLI commands for applying suggested terms. All suggestions require human review before application.

## Features

- **Three provider options**: Ollama (local), OpenAI, or OpenRouter (access to 100+ models)
- **Two-step classification**: Context-aware conversation for higher accuracy
- **Retry logic**: Automatically corrects invalid term suggestions
- **Confidence scoring**: Filter results by confidence threshold
- **CSV workflow**: Export, review in spreadsheets, then apply approved changes
- **Gap analysis**: Identify taxonomy health issues, unused terms, and coverage gaps
- **Pruning tools**: Safely remove unused taxonomy terms with generated scripts
- **Stratified sampling**: Sample across date ranges and categories for representative analysis
- **Provider comparison**: Compare results between Ollama, OpenAI, and OpenRouter

## Requirements

- PHP 8.0+
- WordPress 6.0+
- WP-CLI
- One of:
  - **Ollama** (local, free, private) — [ollama.ai](https://ollama.ai)
  - **OpenAI API key** (cloud, paid, higher quality)
  - **OpenRouter API key** (access to many models) — [openrouter.ai](https://openrouter.ai)

## Installation

1. Clone or copy the plugin to `wp-content/plugins/ai-taxonomy-audit/`
2. Run `composer install` in the plugin directory
3. Activate the plugin (optional — only needed for admin features)

## Configuration

Add to `wp-config.php`:

```php
// For OpenAI (required if using --provider=openai)
define( 'OPENAI_API_KEY', 'sk-...' );

// For OpenRouter (required if using --provider=openrouter)
define( 'OPENROUTER_API_KEY', 'sk-or-...' );

// Optional: Override default models
define( 'DGW_OPENAI_MODEL', 'gpt-4o-mini' );              // Default: gpt-4o-mini
define( 'DGW_OLLAMA_MODEL', 'qwen2.5:latest' );               // Default: qwen2.5:latest
define( 'DGW_OPENROUTER_MODEL', 'google/gemma-2-9b-it:free' ); // Default: gemma-2-9b-it:free

// Optional: Ollama server location
define( 'DGW_OLLAMA_BASE_URI', 'http://localhost:11434' );
```

## Quick Start

```bash
# Check provider status
wp taxonomy-audit status

# Classify 10 posts using OpenAI
wp taxonomy-audit classify --provider=openai --limit=10

# Classify using local Ollama
wp taxonomy-audit classify --provider=ollama --limit=10

# Classify using OpenRouter (access to many models)
wp taxonomy-audit classify --provider=openrouter --model=deepseek/deepseek-chat --limit=10
wp taxonomy-audit classify --provider=ollama --model=gemma3:27b --limit=5 --format=csv

# Review the generated CSV, then apply approved suggestions
wp taxonomy-audit apply --file=output/suggestions-2024-01-28-120000.csv --approved-only
```

## Commands

### classify

Classify posts against taxonomy vocabularies.

```bash
wp taxonomy-audit classify [options]
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--post_type=<type>` | `post` | Post type to classify |
| `--post-ids=<ids>` | — | Comma-separated post IDs |
| `--limit=<n>` | `10` | Maximum posts to process |
| `--taxonomies=<list>` | `category,post_tag` | Taxonomies to classify against |
| `--provider=<name>` | `ollama` | LLM provider: `ollama`, `openai`, or `openrouter` |
| `--model=<name>` | varies | Model to use |
| `--format=<fmt>` | `csv` | Output: `csv`, `json`, or `terminal` |
| `--prefix=<cmd>` | `ddev wp` | WP-CLI prefix for generated commands |
| `--min-confidence=<n>` | `0.7` | Minimum confidence threshold (0-1) |
| `--single-step` | — | Use single API call instead of two-step conversation |
| `--dry-run` | — | Preview without calling LLM |
| `--unclassified-only` | — | Only process posts without terms |
| `--sampling=<strategy>` | `sequential` | Sampling strategy: `sequential` or `stratified` |

**Examples:**

```bash
# Classify with OpenAI (better results)
wp taxonomy-audit classify --provider=openai --model=gpt-4o-mini --limit=20

# Classify with OpenRouter (access to DeepSeek, Llama, Mistral, etc.)
wp taxonomy-audit classify --provider=openrouter --model=deepseek/deepseek-chat --limit=20

# Use single-step mode (faster, less accurate)
wp taxonomy-audit classify --provider=openai --single-step --limit=10

# Classify specific posts
wp taxonomy-audit classify --post-ids=123,456,789

# Classify against custom taxonomies
wp taxonomy-audit classify --taxonomies=topic,region,document_type

# Preview what would be processed
wp taxonomy-audit classify --limit=50 --dry-run

# Output directly to terminal (copyable commands)
wp taxonomy-audit classify --format=terminal --limit=5

# Use stratified sampling (across dates and categories)
wp taxonomy-audit classify --limit=20 --sampling=stratified
```

### export-vocab

Export taxonomy vocabulary for review.

```bash
wp taxonomy-audit export-vocab [options]
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--taxonomies=<list>` | `category,post_tag` | Taxonomies to export |
| `--format=<fmt>` | `json` | Output: `json` or `table` |
| `--file=<path>` | — | Save to file |

**Examples:**

```bash
# View vocabulary as table
wp taxonomy-audit export-vocab --format=table

# Export to JSON file
wp taxonomy-audit export-vocab --taxonomies=topic,category --file=vocabulary.json
```

### apply

Apply taxonomy suggestions from reviewed CSV or JSON file.

```bash
wp taxonomy-audit apply --file=<path> [options]
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--file=<path>` | required | CSV or JSON file path |
| `--approved-only` | — | Only apply rows marked approved |
| `--dry-run` | — | Preview without applying |

**Examples:**

```bash
# Apply all suggestions
wp taxonomy-audit apply --file=suggestions.csv

# Apply only approved rows
wp taxonomy-audit apply --file=suggestions.csv --approved-only

# Preview what would be applied
wp taxonomy-audit apply --file=suggestions.csv --dry-run
```

### generate-script

Generate shell script from suggestions file.

```bash
wp taxonomy-audit generate-script --file=<path> [options]
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--file=<path>` | required | CSV or JSON file path |
| `--output=<path>` | — | Output script path |
| `--prefix=<cmd>` | `ddev wp` | WP-CLI command prefix |
| `--approved-only` | — | Only include approved rows |

**Examples:**

```bash
# Generate script to stdout
wp taxonomy-audit generate-script --file=suggestions.csv

# Save to file
wp taxonomy-audit generate-script --file=suggestions.csv --output=apply-terms.sh

# Use different WP-CLI prefix
wp taxonomy-audit generate-script --file=suggestions.csv --prefix="lando wp"
```

### status

Check LLM provider status and configuration.

```bash
wp taxonomy-audit status
```

### stats

Show classification statistics for a post type.

```bash
wp taxonomy-audit stats [options]
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--post_type=<type>` | `post` | Post type to analyze |
| `--taxonomies=<list>` | `category,post_tag` | Taxonomies to check |

### list

List saved suggestion files.

```bash
wp taxonomy-audit list [--format=<fmt>]
```

### gap-analysis

Analyze taxonomy gaps between suggestions and vocabulary.

```bash
wp taxonomy-audit gap-analysis --suggestions=<path> [options]
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--suggestions=<path>` | required | Path to suggestions JSON file |
| `--taxonomies=<list>` | `category,post_tag` | Taxonomies to analyze |
| `--format=<fmt>` | `table` | Output: `table` or `json` |
| `--output=<path>` | — | Save JSON report to file |

**Output includes:**

- **Suggested new terms**: Terms the LLM suggested that don't exist in vocabulary
- **Unused existing terms**: Terms in vocabulary that were never suggested
- **Ambiguous terms**: Terms with low average confidence (may need clarification)
- **Uncovered content**: Posts without adequate taxonomy suggestions
- **Health score**: Overall taxonomy fitness (0-100)

**Examples:**

```bash
# Run gap analysis with table output
wp taxonomy-audit gap-analysis --suggestions=output/suggestions-2024-01-28.json

# Save report as JSON
wp taxonomy-audit gap-analysis --suggestions=output/suggestions.json --output=gap-report.json

# Analyze specific taxonomies
wp taxonomy-audit gap-analysis --suggestions=output/suggestions.json --taxonomies=topic,region
```

### unused-terms

Find taxonomy terms with zero posts.

```bash
wp taxonomy-audit unused-terms [options]
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--taxonomies=<list>` | `category,post_tag` | Taxonomies to check |
| `--format=<fmt>` | `table` | Output: `table`, `json`, or `csv` |

**Examples:**

```bash
# Find unused terms
wp taxonomy-audit unused-terms

# Check specific taxonomies
wp taxonomy-audit unused-terms --taxonomies=category,topic

# Export as JSON
wp taxonomy-audit unused-terms --format=json
```

### mismatched-terms

Find terms that don't semantically match analyzed content.

These are terms that have posts assigned but were never suggested by the LLM, indicating they may be outdated, misapplied, or need description updates.

```bash
wp taxonomy-audit mismatched-terms --suggestions=<path> [options]
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--suggestions=<path>` | required | Path to suggestions JSON file |
| `--taxonomies=<list>` | `category,post_tag` | Taxonomies to check |
| `--format=<fmt>` | `table` | Output: `table`, `json`, or `csv` |

**Examples:**

```bash
# Find mismatched terms
wp taxonomy-audit mismatched-terms --suggestions=output/suggestions.json

# Export as CSV for review
wp taxonomy-audit mismatched-terms --suggestions=output/suggestions.json --format=csv
```

### generate-prune-script

Generate shell script to safely delete unused taxonomy terms.

```bash
wp taxonomy-audit generate-prune-script [options]
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--taxonomies=<list>` | `category,post_tag` | Taxonomies to check |
| `--output=<path>` | — | Output script path |
| `--prefix=<cmd>` | `ddev wp` | WP-CLI command prefix |
| `--no-confirm` | — | Skip confirmation prompts in script |

**Examples:**

```bash
# Generate script to stdout
wp taxonomy-audit generate-prune-script

# Save to file
wp taxonomy-audit generate-prune-script --output=prune-terms.sh

# Use different WP-CLI prefix
wp taxonomy-audit generate-prune-script --prefix="wp" --output=prune-terms.sh
```

The generated script includes:
- Safety confirmation prompts (unless `--no-confirm`)
- Comments showing term names
- Grouping by taxonomy
- Error handling with `set -e`

### compare

Compare classification results between different LLM providers.

```bash
wp taxonomy-audit compare --post-ids=<ids> [options]
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--post-ids=<ids>` | required | Comma-separated list of post IDs |
| `--providers=<list>` | `ollama,openai` | Providers to compare |
| `--taxonomies=<list>` | `category,post_tag` | Taxonomies to classify |
| `--format=<fmt>` | `table` | Output: `table` or `json` |
| `--output=<path>` | — | Save JSON report to file |

**Examples:**

```bash
# Compare Ollama vs OpenAI on specific posts
wp taxonomy-audit compare --post-ids=123,456,789

# Compare all three providers
wp taxonomy-audit compare --post-ids=123,456 --providers=ollama,openai,openrouter

# Save comparison report
wp taxonomy-audit compare --post-ids=123,456,789 --output=comparison.json
```

**Output includes:**
- Per-post term suggestions from each provider
- Agreement analysis (which providers agree on which terms)
- Summary statistics (full agreement, partial, no agreement)

## Workflow

### 1. Check Status

```bash
wp taxonomy-audit status
```

Verify your chosen provider is available.

### 2. Review Vocabulary

```bash
wp taxonomy-audit export-vocab --taxonomies=category,post_tag --format=table
```

Ensure your taxonomies have the terms you expect.

### 3. Classify Posts

```bash
wp taxonomy-audit classify --provider=openai --limit=50 --format=csv
```

This generates a CSV file in `output/suggestions-{timestamp}.csv`.

### 4. Review Suggestions

Open the CSV in a spreadsheet application. The columns are:

| Column | Description |
|--------|-------------|
| `post_id` | WordPress post ID |
| `post_title` | Post title for reference |
| `post_url` | Post URL |
| `taxonomy` | Target taxonomy |
| `suggested_term` | Suggested term slug |
| `confidence` | Confidence score (0-1) |
| `reason` | LLM's reasoning |
| `approved` | Mark `TRUE` or `YES` to approve |

Delete rows you don't want, or mark the `approved` column.

### 5. Analyze Taxonomy Gaps

```bash
# Run gap analysis to identify issues
wp taxonomy-audit gap-analysis --suggestions=output/suggestions-{timestamp}.json
```

Review the report for:
- Terms to potentially add to vocabulary
- Unused terms to potentially prune
- Ambiguous terms that need clarification
- Content without adequate coverage

### 6. Prune Unused Terms (Optional)

```bash
# Find terms with zero posts
wp taxonomy-audit unused-terms

# Generate safe deletion script
wp taxonomy-audit generate-prune-script --output=prune-terms.sh

# Review and run
cat prune-terms.sh
bash prune-terms.sh
```

### 7. Apply Approved Suggestions

```bash
# Apply only approved rows
wp taxonomy-audit apply --file=output/suggestions.csv --approved-only

# Or generate a script for manual execution
wp taxonomy-audit generate-script --file=output/suggestions.csv --approved-only --output=apply.sh
```

## How Classification Works

The plugin uses a sophisticated two-step classification process:

### Step 1: Context Analysis
The LLM first reads the post content and provides a brief analysis of the main topics and themes. This establishes context without prematurely making term selections.

### Step 2: Term Selection
With the context established, the LLM then receives the controlled vocabulary and selects appropriate terms, providing confidence scores and reasoning for each selection.

### Retry Logic
If the LLM suggests terms that don't exist in your vocabulary (hallucination), the system automatically:
1. Identifies the invalid terms
2. Asks the LLM to correct its response
3. Returns only valid term suggestions

This approach significantly improves accuracy compared to single-step classification.

Use `--single-step` flag to disable this behaviour and use faster single-call classification.

## Model Recommendations

### OpenAI

| Model | Quality | Speed | Cost |
|-------|---------|-------|------|
| `gpt-4o-mini` | High | Fast | ~$0.15/1M tokens |
| `gpt-4o` | Highest | Medium | ~$2.50/1M tokens |

### OpenRouter

| Model | Quality | Speed | Cost |
|-------|---------|-------|------|
| `google/gemma-2-9b-it:free` | Good | Fast | Free |
| `meta-llama/llama-3.1-8b-instruct:free` | Good | Fast | Free |
| `deepseek/deepseek-chat` | High | Medium | Very low |
| `anthropic/claude-3.5-sonnet` | Highest | Medium | Higher |

## Output Directory

Generated files are saved to `wp-content/plugins/ai-taxonomy-audit/output/`:

- `suggestions-{timestamp}.csv` — CSV format
- `suggestions-{timestamp}.json` — JSON format
- `set-terms-{timestamp}.sh` — Shell scripts

The `output/` directory has a `.gitignore` that excludes generated files.

## Hooks

Filter the configuration:

```php
add_filter( 'dgw_taxonomy_audit_config', function( $config ) {
    $config['classification']['min_confidence_threshold'] = 0.8;
    return $config;
} );
```

## Security

- All suggestions require human review before application
- **Ollama**: Content stays on your local machine (private)
- **OpenAI**: Content is sent to OpenAI API
- **OpenRouter**: Content is routed through OpenRouter to various model providers
- All database queries use `$wpdb->prepare()`
- Generated shell commands are properly escaped

## License

GPL-2.0+
