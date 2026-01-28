<?php
/**
 * OpenRouter API Client
 *
 * @package DGWTaxonomyAudit\Core
 */

declare(strict_types=1);

namespace DGWTaxonomyAudit\Core;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Client for interacting with OpenRouter API.
 *
 * OpenRouter provides access to many models (DeepSeek, Llama, Mistral, etc.)
 * through a single API endpoint compatible with OpenAI's format.
 */
class OpenRouterClient implements LLMClientInterface {

	/**
	 * HTTP client.
	 *
	 * @var Client
	 */
	private Client $client;

	/**
	 * Configuration array.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * API key.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $config Configuration array.
	 *
	 * @throws \RuntimeException If API key is not configured.
	 */
	public function __construct( array $config = [] ) {
		$defaults = \DGWTaxonomyAudit\get_config()['openrouter'] ?? [
			'model'       => 'google/gemma-2-9b-it:free',
			'temperature' => 0.3,
			'timeout'     => 120,
		];

		$this->config = array_merge( $defaults, $config );

		// Get API key.
		$this->api_key = defined( 'OPENROUTER_API_KEY' ) ? OPENROUTER_API_KEY : '';

		if ( empty( $this->api_key ) && empty( $config['api_key'] ) ) {
			throw new \RuntimeException(
				'OpenRouter API key not configured. Define OPENROUTER_API_KEY in wp-config.php'
			);
		}

		if ( ! empty( $config['api_key'] ) ) {
			$this->api_key = $config['api_key'];
		}

		$this->client = new Client( [
			'base_uri' => 'https://openrouter.ai/api/v1/',
			'timeout'  => $this->config['timeout'],
			'headers'  => [
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
				'HTTP-Referer'  => home_url(),
				'X-Title'       => 'AI Taxonomy Audit',
			],
		] );
	}

	/**
	 * Send a chat request to OpenRouter.
	 *
	 * @param array<array{role: string, content: string}> $messages    Chat messages.
	 * @param bool                                        $json_format Request JSON response.
	 *
	 * @return array<string, mixed> Parsed response.
	 *
	 * @throws \RuntimeException If the request fails.
	 */
	public function chat( array $messages, bool $json_format = true ): array {
		$payload = [
			'model'       => $this->config['model'],
			'messages'    => $messages,
			'temperature' => $this->config['temperature'],
		];

		// Note: Not all OpenRouter models support response_format.
		// We rely on prompt instructions for JSON formatting.

		try {
			$response = $this->client->post( 'chat/completions', [
				'json' => $payload,
			] );

			$body = (string) $response->getBody();
			$data = json_decode( $body, true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				throw new \RuntimeException( 'Failed to parse OpenRouter response: ' . json_last_error_msg() );
			}

			// Transform to match common response format.
			return [
				'message' => [
					'role'    => $data['choices'][0]['message']['role'] ?? 'assistant',
					'content' => $data['choices'][0]['message']['content'] ?? '',
				],
				'usage' => $data['usage'] ?? [],
				'model' => $data['model'] ?? $this->config['model'],
			];
		} catch ( GuzzleException $e ) {
			$message = $e->getMessage();

			// Extract more useful error from response if available.
			if ( method_exists( $e, 'getResponse' ) && $e->getResponse() ) {
				$error_body = json_decode( (string) $e->getResponse()->getBody(), true );
				if ( isset( $error_body['error']['message'] ) ) {
					$message = $error_body['error']['message'];
				}
			}

			throw new \RuntimeException( 'OpenRouter API request failed: ' . $message, 0, $e );
		}
	}

	/**
	 * Classify content against a vocabulary.
	 *
	 * @param string               $content              Post content.
	 * @param array<string, mixed> $vocabulary           Taxonomy vocabulary.
	 * @param string               $system_prompt        System prompt.
	 * @param string               $user_prompt_template User prompt template.
	 *
	 * @return array<string, mixed> Classification result.
	 */
	public function classify(
		string $content,
		array $vocabulary,
		string $system_prompt,
		string $user_prompt_template
	): array {
		$user_prompt = $this->buildUserPrompt( $content, $vocabulary, $user_prompt_template );

		$messages = [
			[ 'role' => 'system', 'content' => $system_prompt ],
			[ 'role' => 'user', 'content' => $user_prompt ],
		];

		$response = $this->chat( $messages, true );

		return $this->parseClassificationResponse( $response );
	}

	/**
	 * Build user prompt from template.
	 *
	 * @param string               $content    Post content.
	 * @param array<string, mixed> $vocabulary Taxonomy vocabulary.
	 * @param string               $template   Prompt template.
	 *
	 * @return string Built prompt.
	 */
	private function buildUserPrompt( string $content, array $vocabulary, string $template ): string {
		$vocab_text = '';
		foreach ( $vocabulary as $taxonomy => $terms ) {
			$vocab_text .= sprintf( "\n%s:\n", strtoupper( $taxonomy ) );
			foreach ( $terms as $term ) {
				$vocab_text .= sprintf( "- %s", $term['slug'] );
				if ( ! empty( $term['description'] ) ) {
					$vocab_text .= sprintf( " (%s)", $term['description'] );
				}
				$vocab_text .= "\n";
			}
		}

		$replacements = [
			'{content}'    => $content,
			'{vocabulary}' => $vocab_text,
		];

		return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
	}

	/**
	 * Parse classification response.
	 *
	 * @param array<string, mixed> $response Raw response.
	 *
	 * @return array<string, mixed> Parsed classification.
	 */
	private function parseClassificationResponse( array $response ): array {
		$message_content = $response['message']['content'] ?? '';

		if ( empty( $message_content ) ) {
			return [ 'classifications' => [], 'error' => 'Empty response from LLM' ];
		}

		// Try to extract JSON from response (may be wrapped in markdown code blocks).
		$json_content = $this->extractJson( $message_content );

		$parsed = json_decode( $json_content, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return [
				'classifications' => [],
				'error'           => 'Failed to parse JSON from LLM: ' . json_last_error_msg(),
				'raw_response'    => $message_content,
			];
		}

		// Include usage stats if available.
		if ( ! empty( $response['usage'] ) ) {
			$parsed['usage'] = $response['usage'];
		}

		return $parsed;
	}

	/**
	 * Extract JSON from response that may contain markdown.
	 *
	 * @param string $content Response content.
	 *
	 * @return string JSON string.
	 */
	private function extractJson( string $content ): string {
		// Try to extract JSON from markdown code block.
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
	 * Check if OpenRouter is available.
	 *
	 * @return bool True if API key is valid.
	 */
	public function isAvailable(): bool {
		if ( empty( $this->api_key ) ) {
			return false;
		}

		try {
			// OpenRouter doesn't have a simple health endpoint, so we check models.
			$response = $this->client->get( 'models', [
				'query' => [ 'supported_parameters' => 'temperature' ],
			] );
			return $response->getStatusCode() === 200;
		} catch ( GuzzleException $e ) {
			return false;
		}
	}

	/**
	 * Get available models.
	 *
	 * @return array<string> List of model names.
	 */
	public function getModels(): array {
		try {
			$response = $this->client->get( 'models' );
			$data = json_decode( (string) $response->getBody(), true );

			$models = array_map(
				fn( $model ) => $model['id'],
				$data['data'] ?? []
			);

			// Return just a subset of popular free/cheap models.
			$recommended = [
				'google/gemma-2-9b-it:free',
				'meta-llama/llama-3.1-8b-instruct:free',
				'mistralai/mistral-7b-instruct:free',
				'deepseek/deepseek-chat',
				'anthropic/claude-3.5-sonnet',
				'openai/gpt-4o-mini',
			];

			return array_values( array_intersect( $recommended, $models ) );
		} catch ( GuzzleException $e ) {
			return [];
		}
	}

	/**
	 * Get the configured model name.
	 *
	 * @return string Model name.
	 */
	public function getModel(): string {
		return $this->config['model'];
	}

	/**
	 * Get the provider name.
	 *
	 * @return string Provider name.
	 */
	public function getProvider(): string {
		return 'openrouter';
	}
}
