# Measuring Impact

How to track whether your semantic web investments (SKOS, JSON-LD, SHACL, taxonomy enrichment) are making a difference.

---

## The Right Question

Instead of "Is the LLM accurate?", ask:

> "Is our content more discoverable — by humans AND AI agents?"

---

## Honest Assessment: What's Actually Measurable?

| Investment | Claimed Benefit | Measurable? |
|------------|-----------------|-------------|
| SKOS taxonomies | Better LLM classification | ✅ Yes (approval rate, A/B test) |
| SHACL validation | Content quality | ✅ Yes (validation reports) |
| JSON-LD structured data | Rich results, AI understanding | ⚠️ Partially (rich results yes, AI understanding harder) |
| RDF exports | Semantic web interop | ❌ Mostly theoretical |
| Taxonomy enrichment | Better discoverability | ⚠️ Indirect (analytics proxies) |

**The uncomfortable truth:** Most semantic web benefits are internal (better tooling, consistent content). External AI consumption is now trackable with the right tools.

---

## Tracking AI Agent Consumption

### Known Agents (Recommended)

[knownagents.com](https://knownagents.com) — formerly Dark Visitors — tracks AI agent and bot traffic to your site.

**What it measures:**

| Feature | What It Tells You |
|---------|-------------------|
| Agent Analytics | Which AI crawlers visit (GPTBot, ClaudeBot, PerplexityBot) |
| LLM Referral Tracking | Traffic from ChatGPT, Perplexity, Gemini responses |
| Session Analysis | Which pages AI agents browse, how long they stay |
| Traffic Alerts | When AI bot activity spikes |

**Why this matters:** If your structured data and taxonomy work is making content more AI-accessible, you should see:
- Increased AI crawler visits
- Growth in LLM referral traffic
- More pages being crawled per session

**Integration:** Supports WordPress, Cloudflare, and other backends.

### Track These Metrics

| Metric | Source | Shows |
|--------|--------|-------|
| AI crawler visits/month | Known Agents | Are AI bots finding you? |
| LLM referral traffic | Known Agents | Are LLMs recommending you? |
| Pages per AI session | Known Agents | Is content well-linked? |
| Referral growth trend | Known Agents | Is AI visibility improving? |

---

## Quick Metrics (No Additional Tools)

These require no setup beyond commands you already have.

### 1. Approval Rate

After each CSV review:

```
Approval Rate = Approved Rows / Total Rows
```

**Track over time.** Increasing = LLM suggestions getting more reliable.

```bash
wp taxonomy-audit classify ... --save-run --run-notes="Approval rate: 45/52 (87%)"
```

### 2. Health Score Trend

```bash
wp taxonomy-audit gap-analysis --suggestions=output/runs/<id>/suggestions.json
```

**Track the health score (0-100).** Increasing = vocabulary becoming more complete.

### 3. Coverage Metrics

```bash
wp taxonomy-audit stats --post_type=post --taxonomies=category,post_tag
```

**Track uncovered posts.** Decreasing = better coverage.

### 4. SHACL Validation Pass Rate

```bash
wp wptofile-graph validate export/rdf/ --shapes=shapes.ttl
```

**Track violations.** Decreasing = content quality improving.

---

## Search Engine Metrics

### Google Search Console

| Metric | Location | Shows |
|--------|----------|-------|
| Rich result impressions | Performance → Search Appearance | Is Google using your structured data? |
| Rich result CTR | Performance → Search Appearance | Are rich results driving clicks? |
| Enhancement errors | Enhancements reports | Schema markup issues |
| Taxonomy page performance | Performance → Pages | Are archives ranking? |

**This is the clearest ROI for JSON-LD.** If you're not getting rich results, the structured data isn't paying off for search.

---

## Site Analytics (Proxy Metrics)

| Metric | Source | Shows |
|--------|--------|-------|
| Taxonomy archive pageviews | GA4/Plausible | Are people using categories? |
| Related content CTR | Analytics events | Is taxonomy-driven discovery working? |
| Time on taxonomy pages | Analytics | Content organization quality |
| Internal search success | Search logs | Is content findable? |

---

## SKOS Context: Does It Help?

Quick A/B test:

```bash
# Pick 10-20 representative posts
export TEST_POSTS="123,456,789,..."

# Run without SKOS
wp taxonomy-audit classify --post-ids=$TEST_POSTS --provider=openai \
    --save-run --run-notes="Control: No SKOS"

# Run with SKOS
wp taxonomy-audit classify --post-ids=$TEST_POSTS --provider=openai \
    --skos-context=vocab/taxonomies.skos.ttl \
    --save-run --run-notes="Treatment: With SKOS"

# Compare
wp taxonomy-audit runs-compare <control-id> <treatment-id>
```

**What to look for:**
- More specific terms suggested? (child vs parent)
- Higher approval rate?
- Fewer obvious misses?

---

## Monthly Check-In

Copy this checklist for your monthly review:

```markdown
## Monthly Semantic Web Health Check — [DATE]

### AI Agent Visibility (Known Agents)
- [ ] AI crawler visits this month: _____ (trend: ↑/↓/→)
- [ ] LLM referral traffic: _____ visits (trend: ↑/↓/→)
- [ ] Top referring LLMs: _____
- [ ] Pages per AI session: _____ (trend: ↑/↓/→)

### Search Performance (Search Console)
- [ ] Rich result impressions: _____ (trend: ↑/↓/→)
- [ ] Rich result CTR: _____%
- [ ] Schema errors to fix: _____

### Content Quality (Internal Tools)
- [ ] SHACL validation pass rate: _____%
- [ ] Gap analysis health score: _____/100
- [ ] Uncovered posts: _____
- [ ] Unused terms: _____

### Classification Quality
- [ ] Last approval rate: _____%
- [ ] SKOS context in use: Yes/No

### Actions for Next Month
- [ ] _____
- [ ] _____
```

---

## Quarterly Business Review

For proving ROI to stakeholders:

| Area | Key Metric | Baseline | Current | Change |
|------|-----------|----------|---------|--------|
| AI Visibility | LLM referral traffic | — | — | — |
| Search | Rich result impressions | — | — | — |
| Quality | SHACL pass rate | — | — | — |
| Coverage | Uncovered posts | — | — | — |
| Efficiency | Approval rate | — | — | — |

---

## What NOT to Track

| Approach | Why Skip It |
|----------|-------------|
| DeepEval / LLM evaluation suites | Designed for chatbots, not classification |
| Precision/Recall/F1 formulas | Human approval already does this |
| Gold standard datasets | Expensive to maintain, low ROI |
| Semantic similarity scores | Complexity without clear benefit |

The human-in-the-loop **is** your evaluation framework. Known Agents fills the AI visibility gap.

---

## Tracking Cadence Summary

| When | What | Tool |
|------|------|------|
| After each review | Approval rate | `--run-notes` |
| Weekly | AI agent traffic | Known Agents |
| Bi-weekly | Health score | `gap-analysis` |
| Monthly | Full check-in | Checklist above |
| Quarterly | Business review | Stakeholder report |

---

## The Bottom Line

### What You Can Measure

1. **Approval rate** → Is the LLM reliable?
2. **Health score** → Is vocabulary complete?
3. **SHACL pass rate** → Is content valid?
4. **Rich results** → Is Google using structured data?
5. **AI agent traffic** → Are AI crawlers visiting? (Known Agents)
6. **LLM referrals** → Are LLMs recommending you? (Known Agents)

### What Remains Theoretical

- Whether your RDF is consumed by external systems
- Whether complex ontologies provide external benefit
- Exact impact of structured data on LLM training

**Focus on 1-6. Accept uncertainty on the rest.**
