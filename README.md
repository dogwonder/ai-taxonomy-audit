# AI Taxonomy Audit

WP-CLI tool for human-in-the-loop taxonomy enrichment using LLM (Ollama or OpenAI).

Classifies WordPress posts against controlled vocabularies and generates WP-CLI commands for applying suggested terms. All suggestions require human review before application.

## Requirements

- PHP 8.0+
- WordPress 6.0+
- WP-CLI
- One of:
  - **Ollama** (local, free, private) — [ollama.ai](https://ollama.ai)
  - **OpenAI API key** (cloud, paid, higher quality)

## Installation

1. Clone or copy the plugin to `wp-content/plugins/ai-taxonomy-audit/`
2. Run `composer install` in the plugin directory
3. Activate the plugin (optional — only needed for admin features)

## Configuration

Add to `wp-config.php`:

```php
// For OpenAI (required if using --provider=openai)
define( 'OPENAI_API_KEY', 'sk-...' );

// Optional: Override default models
define( 'DGW_OPENAI_MODEL', 'gpt-4o-mini' );    // Default: gpt-4o-mini
define( 'DGW_OLLAMA_MODEL', 'gemma3:27b' );     // Default: gemma3:27b

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
| `--provider=<name>` | `ollama` | LLM provider: `ollama` or `openai` |
| `--model=<name>` | varies | Model to use |
| `--format=<fmt>` | `csv` | Output: `csv`, `json`, or `terminal` |
| `--prefix=<cmd>` | `ddev wp` | WP-CLI prefix for generated commands |
| `--min-confidence=<n>` | `0.7` | Minimum confidence threshold (0-1) |
| `--dry-run` | — | Preview without calling LLM |
| `--unclassified-only` | — | Only process posts without terms |

**Examples:**

```bash
# Classify with OpenAI (better results)
wp taxonomy-audit classify --provider=openai --model=gpt-4o-mini --limit=20

# Classify specific posts
wp taxonomy-audit classify --post-ids=123,456,789

# Classify against custom taxonomies
wp taxonomy-audit classify --taxonomies=topic,region,document_type

# Preview what would be processed
wp taxonomy-audit classify --limit=50 --dry-run

# Output directly to terminal (copyable commands)
wp taxonomy-audit classify --format=terminal --limit=5
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

### 5. Apply Approved Suggestions

```bash
# Apply only approved rows
wp taxonomy-audit apply --file=output/suggestions.csv --approved-only

# Or generate a script for manual execution
wp taxonomy-audit generate-script --file=output/suggestions.csv --approved-only --output=apply.sh
```

## Model Recommendations

### OpenAI

| Model | Quality | Speed | Cost |
|-------|---------|-------|------|
| `gpt-4o-mini` | High | Fast | ~$0.15/1M tokens |
| `gpt-4o` | Highest | Medium | ~$2.50/1M tokens |

### Ollama (32GB Mac)

| Model | Quality | Speed |
|-------|---------|-------|
| `gemma3:27b` | Good | Medium |
| `qwen2.5:32b` | Better | Slower |
| `qwen2.5:14b` | Moderate | Fast |

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
- OpenAI: Content is sent to OpenAI API
- Ollama: Content stays on your local machine
- All database queries use `$wpdb->prepare()`
- Generated shell commands are properly escaped

## License

GPL-2.0+
