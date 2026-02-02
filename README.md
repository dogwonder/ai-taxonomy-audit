# AI Taxonomy Audit

WP-CLI tool for human-in-the-loop taxonomy enrichment using LLM (Ollama, OpenAI, or OpenRouter).

Classifies WordPress posts against controlled vocabularies and generates WP-CLI commands for applying suggested terms. All suggestions require human review before application.

## Features

- **Three provider options**: Ollama (local), OpenAI, or OpenRouter (access to 100+ models)
- **Two classification modes**: Benchmark (vocabulary-only) or Audit (benchmark + gap-filling suggestions)
- **Two-step classification**: Context-aware conversation for higher accuracy
- **Retry logic**: Automatically corrects invalid term suggestions
- **Confidence scoring**: Filter results by confidence threshold
- **CSV workflow**: Export, review in spreadsheets, then apply approved changes
- **Gap analysis**: Identify taxonomy health issues, unused terms, and coverage gaps
- **Audit mode**: Suggest new taxonomy terms that should exist (gap-filling)
- **Pruning tools**: Safely remove unused taxonomy terms with generated scripts
- **Stratified sampling**: Sample across date ranges and categories for representative analysis
- **Provider comparison**: Compare results between Ollama, OpenAI, and OpenRouter
- **Run storage**: Track analysis runs with metadata, compare runs over time
- **Cost tracking**: Estimate costs before running, track actual usage, compare provider pricing

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
| `--audit` | — | Enable audit mode: suggest new terms that should exist |
| `--single-step` | — | Use single API call instead of two-step conversation |
| `--dry-run` | — | Preview posts and estimate costs without calling LLM |
| `--unclassified-only` | — | Only process posts without terms |
| `--sampling=<strategy>` | `sequential` | Sampling strategy: `sequential` or `stratified` |
| `--save-run` | — | Save results to structured run for historical tracking |
| `--run-notes=<notes>` | — | Notes to attach to the run (requires `--save-run`) |
| `--skos-context=<file>` | — | Path to SKOS Turtle file for hierarchical vocabulary context |

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

# Preview posts and estimate costs before running
wp taxonomy-audit classify --provider=openai --limit=50 --dry-run

# Output directly to terminal (copyable commands)
wp taxonomy-audit classify --format=terminal --limit=5

# Use stratified sampling (across dates and categories)
wp taxonomy-audit classify --limit=20 --sampling=stratified

# Save results to a structured run for historical tracking
wp taxonomy-audit classify --limit=100 --provider=openai --save-run --run-notes="Initial baseline"

# Enable audit mode to discover vocabulary gaps (suggests new terms)
wp taxonomy-audit classify --audit --provider=openai --limit=20 --format=csv

# Use SKOS context for hierarchical vocabulary (requires wp-to-file-graph)
wp taxonomy-audit classify --skos-context=vocab/category.skos.ttl --taxonomies=category --limit=20
```

**SKOS Context:**

When you provide a SKOS Turtle file via `--skos-context`, the LLM receives hierarchical vocabulary information:
- **Broader/narrower relationships** — helps LLM understand term specificity
- **SKOS definitions** — richer context than WordPress term descriptions
- **Hierarchical prompt formatting** — terms displayed as a tree, encouraging specific term selection

Generate SKOS files using [wp-to-file-graph](https://github.com/dogwonder/wp-to-file-graph):

```bash
# Export taxonomy as SKOS
wp wptofile-graph skos category --output=vocab/category.skos.ttl

# Use in classification
wp taxonomy-audit classify \
    --taxonomies=category \
    --skos-context=vocab/category.skos.ttl \
    --provider=openai \
    --limit=20
```

**Audit Mode:**

In audit mode (`--audit`), the LLM will:
1. Classify content against your existing vocabulary (benchmark)
2. Suggest new terms that should exist but don't (gap-filling)

Output includes an `in_vocabulary` column:
- `TRUE` — term exists in your vocabulary
- `FALSE` — suggested new term (requires manual creation before applying)

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
| `--save-run=<run-id>` | — | Add gap analysis to an existing run |

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

# Add gap analysis to an existing run
wp taxonomy-audit gap-analysis --suggestions=output/runs/2025-01-29T103000/suggestions.json --save-run=2025-01-29T103000
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

### runs-list

List all analysis runs.

```bash
wp taxonomy-audit runs-list [options]
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--format=<fmt>` | `table` | Output: `table`, `json`, or `csv` |
| `--limit=<n>` | `20` | Maximum runs to show |

### runs-show

Show details of a specific run.

```bash
wp taxonomy-audit runs-show <run-id> [options]
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--format=<fmt>` | `yaml` | Output: `yaml` or `json` |

**Example:**

```bash
wp taxonomy-audit runs-show 2025-01-29T103000
```

### runs-compare

Compare two analysis runs.

```bash
wp taxonomy-audit runs-compare <run-id-a> <run-id-b> [options]
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--format=<fmt>` | `table` | Output: `table` or `json` |

**Output includes:**
- Configuration differences between runs
- Summary statistic differences
- Files present in each run
- Suggestion comparison (posts changed, unchanged, added, removed)

**Example:**

```bash
wp taxonomy-audit runs-compare 2025-01-27T140000 2025-01-29T103000
```

### runs-delete

Delete an analysis run.

```bash
wp taxonomy-audit runs-delete <run-id> [--yes]
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--yes` | — | Skip confirmation prompt |

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

### 3. Estimate Costs (Optional)

```bash
wp taxonomy-audit classify --provider=openai --limit=50 --dry-run
```

Review the cost estimate and provider comparison before running.

### 4. Classify Posts

```bash
wp taxonomy-audit classify --provider=openai --limit=50 --format=csv
```

This generates a CSV file in `output/suggestions-{timestamp}.csv` and displays usage summary.

### 5. Review Suggestions

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
| `in_vocabulary` | `TRUE` if term exists, `FALSE` if suggested new term (audit mode) |
| `approved` | Mark `TRUE` or `YES` to approve |

Delete rows you don't want, or mark the `approved` column.

**Note:** When applying suggestions, only terms with `in_vocabulary: TRUE` (or empty) will be applied. Suggested new terms (`in_vocabulary: FALSE`) must be created manually first using `wp term create <taxonomy> <term-slug>`.

### 6. Analyze Taxonomy Gaps

```bash
# Run gap analysis to identify issues
wp taxonomy-audit gap-analysis --suggestions=output/suggestions-{timestamp}.json
```

Review the report for:
- Terms to potentially add to vocabulary
- Unused terms to potentially prune
- Ambiguous terms that need clarification
- Content without adequate coverage

### 7. Prune Unused Terms (Optional)

```bash
# Find terms with zero posts
wp taxonomy-audit unused-terms

# Generate safe deletion script
wp taxonomy-audit generate-prune-script --output=prune-terms.sh

# Review and run
cat prune-terms.sh
bash prune-terms.sh
```

### 8. Apply Approved Suggestions

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

### SKOS Context Enhancement

When a SKOS Turtle file is provided via `--skos-context`, the vocabulary prompt changes from flat to hierarchical:

**Without SKOS (flat):**
```
CATEGORY:
  - climate ("Climate") - Actions addressing climate change
  - mitigation ("Mitigation") - Actions to reduce emissions
  - carbon-reduction ("Carbon Reduction")
```

**With SKOS (hierarchical):**
```
CATEGORY:
(Terms organized hierarchically. Prefer specific terms when content is specific.)

  - climate ("Climate") - Actions addressing climate change
    - mitigation ("Mitigation") - Actions to reduce emissions
      - carbon-reduction ("Carbon Reduction") - Reducing carbon output
    - adaptation ("Adaptation") - Adjusting to climate impacts
```

This helps the LLM:
1. Understand parent/child relationships between terms
2. Choose more specific terms when content warrants it
3. Use richer SKOS definitions instead of WordPress term descriptions

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

## Cost Tracking

The plugin tracks token usage and calculates costs for API-based providers.

### Estimating Costs Before Running

Use `--dry-run` to see cost estimates before committing:

```bash
wp taxonomy-audit classify --provider=openai --limit=50 --dry-run
```

Output includes:

```
=== Cost Estimate ===
Provider: openai
Model: gpt-4o-mini
Estimated input tokens: 45,000
Estimated output tokens: 15,000
Estimated API calls: 100
Estimated cost: $0.0158

--- Provider Comparison ---
  Ollama (local): Free
  OpenAI gpt-4o-mini: $0.0158
  OpenAI gpt-4o: $0.2625
  OpenRouter Gemma (free): Free
  OpenRouter Claude Haiku: $0.0300
```

### Actual Usage Summary

After classification completes, you'll see actual usage:

```
=== Usage Summary ===
Provider: openai
Model: gpt-4o-mini
API requests: 100
Input tokens: 43,521
Output tokens: 14,892
Total tokens: 58,413
Cost: $0.0155
```

### Stored Usage Data

When using `--save-run`, usage data is stored with the run metadata for historical tracking:

```bash
wp taxonomy-audit classify --provider=openai --limit=100 --save-run

# View run details including usage
wp taxonomy-audit runs-show 2025-01-30T120000
```

### Local vs Cloud Cost Comparison

| Factor | Ollama (Local) | Cloud API |
|--------|----------------|-----------|
| Per-request cost | Free | Per token |
| Hardware | GPU investment (~$300-1500) | None |
| Electricity | ~$0.02-0.05/hour during use | None |
| Speed | Varies (GPU dependent) | Fast |
| Privacy | Data stays local | Data sent to API |
| Breakeven | ~10,000+ classifications/month | Under ~1,000/month |

For small projects or occasional use, cloud APIs are often more cost-effective. For high-volume or privacy-sensitive workloads, local Ollama may be preferable.

## Output Directory

Generated files are saved to `wp-content/plugins/ai-taxonomy-audit/output/`:

```
output/
├── suggestions-{timestamp}.csv     # CSV format
├── suggestions-{timestamp}.json    # JSON format
├── set-terms-{timestamp}.sh        # Shell scripts
├── runs/                           # Structured run storage
│   └── {run-id}/
│       ├── manifest.json           # Run metadata and config
│       ├── suggestions.json        # Classification results
│       └── gap-analysis.json       # Gap analysis (if run)
└── applied/
    └── {run-id}.json               # Record of applied changes
```

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

## Measuring Improvement

See [BENCHMARKING.md](BENCHMARKING.md) for strategies to track classification accuracy over time:
- Establishing baseline metrics
- A/B testing configurations (e.g., with/without SKOS)
- Precision, recall, and agreement rate tracking
- Interpreting improvement trends

## License

GPL-2.0+
