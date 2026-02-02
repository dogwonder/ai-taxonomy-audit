<?php
/**
 * Cost Tracker for LLM API usage
 *
 * @package DGWTaxonomyAudit\Core
 */

declare(strict_types=1);

namespace DGWTaxonomyAudit\Core;

/**
 * Tracks token usage and calculates costs for LLM API calls.
 */
class CostTracker {

	/**
	 * Pricing per million tokens (USD).
	 * Updated as of January 2025.
	 *
	 * @var array<string, array{input: float, output: float}>
	 */
	private const PRICING = [
		// OpenAI models.
		'gpt-4o'              => [ 'input' => 2.50, 'output' => 10.00 ],
		'gpt-4o-mini'         => [ 'input' => 0.15, 'output' => 0.60 ],
		'gpt-4-turbo'         => [ 'input' => 10.00, 'output' => 30.00 ],
		'gpt-4'               => [ 'input' => 30.00, 'output' => 60.00 ],
		'gpt-3.5-turbo'       => [ 'input' => 0.50, 'output' => 1.50 ],

		// OpenRouter models (approximate/typical).
		'anthropic/claude-3.5-sonnet'   => [ 'input' => 3.00, 'output' => 15.00 ],
		'anthropic/claude-3-haiku'      => [ 'input' => 0.25, 'output' => 1.25 ],
		'google/gemini-pro'             => [ 'input' => 0.125, 'output' => 0.375 ],
		'google/gemma-2-9b-it:free'     => [ 'input' => 0.00, 'output' => 0.00 ],
		'meta-llama/llama-3-70b'        => [ 'input' => 0.59, 'output' => 0.79 ],
		'mistralai/mistral-7b-instruct' => [ 'input' => 0.06, 'output' => 0.06 ],

		// Ollama (local) - no API cost, but we track for comparison.
		'ollama'              => [ 'input' => 0.00, 'output' => 0.00 ],
	];

	/**
	 * Average characters per token (approximation).
	 */
	private const CHARS_PER_TOKEN = 4;

	/**
	 * Accumulated usage data.
	 *
	 * @var array{input_tokens: int, output_tokens: int, requests: int}
	 */
	private array $usage = [
		'input_tokens'  => 0,
		'output_tokens' => 0,
		'requests'      => 0,
	];

	/**
	 * Provider name.
	 *
	 * @var string
	 */
	private string $provider;

	/**
	 * Model name.
	 *
	 * @var string
	 */
	private string $model;

	/**
	 * Constructor.
	 *
	 * @param string $provider Provider name (ollama, openai, openrouter).
	 * @param string $model    Model name.
	 */
	public function __construct( string $provider, string $model ) {
		$this->provider = $provider;
		$this->model    = $model;
	}

	/**
	 * Record token usage from an API response.
	 *
	 * @param array{prompt_tokens?: int, completion_tokens?: int, total_tokens?: int} $usage Usage data from API.
	 *
	 * @return void
	 */
	public function recordUsage( array $usage ): void {
		$this->usage['input_tokens']  += $usage['prompt_tokens'] ?? 0;
		$this->usage['output_tokens'] += $usage['completion_tokens'] ?? 0;
		$this->usage['requests']++;
	}

	/**
	 * Estimate tokens from text content.
	 *
	 * @param string $text Text to estimate.
	 *
	 * @return int Estimated token count.
	 */
	public function estimateTokens( string $text ): int {
		// Simple estimation: ~4 characters per token.
		return (int) ceil( mb_strlen( $text ) / self::CHARS_PER_TOKEN );
	}

	/**
	 * Estimate cost for a classification run.
	 *
	 * @param int   $post_count      Number of posts to classify.
	 * @param int   $avg_content_len Average content length in characters.
	 * @param int   $vocab_size      Vocabulary size in characters.
	 * @param bool  $two_step        Whether using two-step classification.
	 *
	 * @return array{estimated_input_tokens: int, estimated_output_tokens: int, estimated_cost: float, cost_formatted: string}
	 */
	public function estimateCost( int $post_count, int $avg_content_len, int $vocab_size, bool $two_step = true ): array {
		// System prompt ~500 tokens.
		$system_tokens = 500;

		// Per-post estimation.
		$content_tokens = $this->estimateTokens( str_repeat( 'x', $avg_content_len ) );
		$vocab_tokens   = $this->estimateTokens( str_repeat( 'x', $vocab_size ) );

		// Two-step uses 2 requests per post; single-step uses 1.
		$requests_per_post = $two_step ? 2 : 1;

		// Input tokens per post.
		$input_per_post = $system_tokens + $content_tokens + $vocab_tokens;
		if ( $two_step ) {
			// Second request includes previous context.
			$input_per_post += 200; // Context summary response.
		}

		// Output tokens per post (~200 for context, ~300 for classification).
		$output_per_post = $two_step ? 500 : 300;

		$total_input  = $input_per_post * $post_count;
		$total_output = $output_per_post * $post_count;

		$cost = $this->calculateCost( $total_input, $total_output );

		return [
			'estimated_input_tokens'  => $total_input,
			'estimated_output_tokens' => $total_output,
			'estimated_requests'      => $post_count * $requests_per_post,
			'estimated_cost'          => $cost,
			'cost_formatted'          => $this->formatCost( $cost ),
		];
	}

	/**
	 * Calculate cost from token counts.
	 *
	 * @param int $input_tokens  Input token count.
	 * @param int $output_tokens Output token count.
	 *
	 * @return float Cost in USD.
	 */
	public function calculateCost( int $input_tokens, int $output_tokens ): float {
		$pricing = $this->getPricing();

		$input_cost  = ( $input_tokens / 1_000_000 ) * $pricing['input'];
		$output_cost = ( $output_tokens / 1_000_000 ) * $pricing['output'];

		return $input_cost + $output_cost;
	}

	/**
	 * Get pricing for current model.
	 *
	 * @return array{input: float, output: float}
	 */
	public function getPricing(): array {
		// Check exact model match first.
		if ( isset( self::PRICING[ $this->model ] ) ) {
			return self::PRICING[ $this->model ];
		}

		// For Ollama, always free.
		if ( 'ollama' === $this->provider ) {
			return self::PRICING['ollama'];
		}

		// Try partial match for OpenRouter models.
		foreach ( self::PRICING as $model_key => $pricing ) {
			if ( str_contains( $this->model, $model_key ) || str_contains( $model_key, $this->model ) ) {
				return $pricing;
			}
		}

		// Default fallback: assume gpt-4o-mini pricing.
		return self::PRICING['gpt-4o-mini'];
	}

	/**
	 * Get accumulated usage.
	 *
	 * @return array{input_tokens: int, output_tokens: int, requests: int, total_tokens: int, cost: float, cost_formatted: string}
	 */
	public function getUsage(): array {
		$cost = $this->calculateCost( $this->usage['input_tokens'], $this->usage['output_tokens'] );

		return [
			'input_tokens'   => $this->usage['input_tokens'],
			'output_tokens'  => $this->usage['output_tokens'],
			'total_tokens'   => $this->usage['input_tokens'] + $this->usage['output_tokens'],
			'requests'       => $this->usage['requests'],
			'cost'           => $cost,
			'cost_formatted' => $this->formatCost( $cost ),
		];
	}

	/**
	 * Format cost as string.
	 *
	 * @param float $cost Cost in USD.
	 *
	 * @return string Formatted cost.
	 */
	public function formatCost( float $cost ): string {
		if ( $cost < 0.001 ) {
			return 'Free (local)';
		}

		if ( $cost < 0.01 ) {
			return sprintf( '$%.4f', $cost );
		}

		return sprintf( '$%.2f', $cost );
	}

	/**
	 * Reset accumulated usage.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->usage = [
			'input_tokens'  => 0,
			'output_tokens' => 0,
			'requests'      => 0,
		];
	}

	/**
	 * Get comparison between local and cloud costs.
	 *
	 * @param int $input_tokens  Input tokens.
	 * @param int $output_tokens Output tokens.
	 *
	 * @return array<string, array{cost: float, cost_formatted: string}>
	 */
	public static function compareProviders( int $input_tokens, int $output_tokens ): array {
		$comparison = [];

		$models_to_compare = [
			'ollama'       => 'Ollama (local)',
			'gpt-4o-mini'  => 'OpenAI gpt-4o-mini',
			'gpt-4o'       => 'OpenAI gpt-4o',
			'google/gemma-2-9b-it:free' => 'OpenRouter Gemma (free)',
			'anthropic/claude-3-haiku'  => 'OpenRouter Claude Haiku',
		];

		foreach ( $models_to_compare as $model => $label ) {
			$pricing = self::PRICING[ $model ] ?? self::PRICING['gpt-4o-mini'];
			$cost    = ( $input_tokens / 1_000_000 ) * $pricing['input']
					 + ( $output_tokens / 1_000_000 ) * $pricing['output'];

			$comparison[ $label ] = [
				'cost'           => $cost,
				'cost_formatted' => $cost < 0.001 ? 'Free' : sprintf( '$%.4f', $cost ),
			];
		}

		return $comparison;
	}

	/**
	 * Get provider name.
	 *
	 * @return string
	 */
	public function getProvider(): string {
		return $this->provider;
	}

	/**
	 * Get model name.
	 *
	 * @return string
	 */
	public function getModel(): string {
		return $this->model;
	}
}
