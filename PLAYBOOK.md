# Content Analysis Pipeline Playbook

A step-by-step guide for auditing WordPress taxonomies against actual content using LLM-powered analysis.

## Overview

This pipeline:
1. Exports content and schema from WordPress
2. Audits content against existing taxonomies using AI
3. Reviews and approves suggestions
4. Applies approved taxonomy changes

## Prerequisites

- WP-CLI installed and configured
- Ollama running locally (for local LLM runs)
- OpenAI API key configured (for production runs)

### Plugin Dependencies

| Plugin | Location | Purpose |
|--------|----------|---------|
| wp-to-file | `mu-plugins/wp-to-file` | Content export to JSON |
| wp-to-file-graph | `plugins/wp-to-file-graph` | Schema discovery |
| ai-taxonomy-audit | `plugins/ai-taxonomy-audit` | AI-powered taxonomy classification |

### Check Plugin Status

```bash
# Verify ai-taxonomy-audit is working
wp taxonomy-audit status

# Check Ollama is running
curl http://localhost:11434/api/tags
```

---

## Phase 1: Export Content & Schema

### 1.1 Create Export Directories

```bash
mkdir -p wp-content/export/clause-json
mkdir -p vocab
```

### 1.2 Export Posts (100 posts)

```bash
wp wptofile clause-json --post_type=post --file_type=json --limit=100
```

For a specific custom post type (e.g., `clause`):

```bash
wp wptofile clause-json --post_type=clause --file_type=json --limit=100
```

or

```
wp wptofile clause-json --profile=content-analysis
```

### 1.3 Discover Content Schema

```bash
wp wptofile-graph discover clause --format=json --output=vocab/schema.json
```

For multiple post types:

```bash
wp wptofile-graph discover post page --format=json --output=vocab/schema.json
```

### 1.4 Export Existing Taxonomy Vocabulary

```bash
wp taxonomy-audit stats --post_type=clause --taxonomies=jurisdiction,climate-or-nature-outcome
```

---

## Phase 2: Taxonomy Audit (Local LLM - Subset)

Run a smaller batch first to validate the approach and tune parameters.

### 2.1 Check Classification Stats

```bash
wp taxonomy-audit stats --post_type=post --taxonomies=category,post_tag
```

### 2.2 Dry Run (20 posts)

```bash
wp taxonomy-audit classify \
  --post_type=clause \
  --limit=20 \
  --taxonomies=jurisdiction,climate-or-nature-outcome \
  --provider=ollama \
  --model=qwen2.5:14b \
  --format=json \
  --dry-run
```

### 2.3 Run Classification (Local - 20 posts)

```bash
wp taxonomy-audit classify \
  --post_type=clause \
  --limit=20 \
  --taxonomies=jurisdiction,climate-or-nature-outcome \
  --provider=ollama \
  --model=qwen2.5:14b \
  --format=json \
  --min-confidence=0.7
```

Output: `wp-content/plugins/ai-taxonomy-audit/output/suggestions-<timestamp>.json`

### 2.4 Review Suggestions

```bash
wp taxonomy-audit list
```

---

## Phase 3: Taxonomy Audit (Production - Full Run)

After validating the local run, execute the full 100-post analysis with GPT-4o-mini.

### 3.1 Full Classification (GPT-4o-mini)

```bash
wp taxonomy-audit classify \
  --post_type=post \
  --limit=100 \
  --taxonomies=category,post_tag \
  --provider=openai \
  --model=gpt-4o-mini \
  --format=csv \
  --min-confidence=0.7
```

Output: CSV file for human review and approval.

### 3.2 Generate Shell Script for Approved Changes

```bash
wp taxonomy-audit generate-script \
  --file=wp-content/plugins/ai-taxonomy-audit/output/suggestions-<timestamp>.csv \
  --output=apply-taxonomies.sh \
  --approved-only \
  --prefix="wp"
```

---

## Phase 4: Apply Changes

After reviewing and approving suggestions:

### 4.1 Dry Run Application

```bash
wp taxonomy-audit apply \
  --file=wp-content/plugins/ai-taxonomy-audit/output/suggestions-<timestamp>.csv \
  --approved-only \
  --dry-run
```

### 4.2 Apply Approved Taxonomy Terms

```bash
wp taxonomy-audit apply \
  --file=wp-content/plugins/ai-taxonomy-audit/output/suggestions-<timestamp>.csv \
  --approved-only
```

### 4.3 Or Execute Generated Script

```bash
chmod +x apply-taxonomies.sh
./apply-taxonomies.sh
```

---

## Quick Reference

### Export Commands

```bash
# Export posts
wp wptofile clause-json --post_type=post --file_type=json --limit=100

# Export schema
wp wptofile-graph discover clause --format=json --output=vocab/schema.json

# Export taxonomy vocabulary
wp taxonomy-audit export-vocab --taxonomies=category,post_tag --file=vocab/taxonomy-vocab.json
```

### Classification Commands

```bash
# Local LLM (development)
wp taxonomy-audit classify --limit=20 --provider=ollama --model=qwen2.5:14b

# OpenAI (production)
wp taxonomy-audit classify --limit=100 --provider=openai --model=gpt-4o-mini
```

---

## Output Files

| File | Description |
|------|-------------|
| `wp-content/export/clause-json/*.json` | Exported post content |
| `vocab/schema.json` | Content structure schema |
| `vocab/taxonomy-vocab.json` | Existing taxonomy terms |
| `suggestions-<timestamp>.json` | AI taxonomy suggestions |
| `suggestions-<timestamp>.csv` | CSV for human review |
| `apply-taxonomies.sh` | Shell script for applying changes |

---

## Troubleshooting

### Ollama Not Available

```bash
# Start Ollama
ollama serve

# Pull required model
ollama pull qwen2.5:14b
```

### OpenAI API Key Not Set

Add to `wp-config.php`:

```php
define('OPENAI_API_KEY', 'sk-...');
```

#ai #content