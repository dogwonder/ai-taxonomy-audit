<?php
/**
 * Ollama API Client
 *
 * @package DGWTaxonomyAudit\Core
 */

declare(strict_types=1);

namespace DGWTaxonomyAudit\Core;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Client for interacting with local Ollama API.
 */
class OllamaClient implements LLMClientInterface {

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
	 * Constructor.
	 *
	 * @param array<string, mixed> $config Configuration array.
	 */
	public function __construct( array $config = [] ) {
		$defaults = \DGWTaxonomyAudit\get_config()['ollama'];
		$this->config = array_merge( $defaults, $config );

		$this->client = new Client( [
			'base_uri' => $this->config['base_uri'],
			'timeout'  => $this->config['timeout'],
		] );
	}

	/**
	 * Send a chat request to Ollama.
	 *
	 * @param array<array{role: string, content: string}> $messages Chat messages.
	 * @param bool                                        $json_format Whether to request JSON response.
	 *
	 * @return array<string, mixed> Parsed response.
	 *
	 * @throws \RuntimeException If the request fails.
	 */
	public function chat( array $messages, bool $json_format = true ): array {
		$payload = [
			'model'    => $this->config['model'],
			'messages' => $messages,
			'stream'   => false,
			'options'  => [
				'temperature' => $this->config['temperature'],
			],
		];

		if ( $json_format ) {
			$payload['format'] = 'json';
		}

		try {
			$response = $this->client->post( '/api/chat', [
				'json' => $payload,
			] );

			$body = (string) $response->getBody();
			$data = json_decode( $body, true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				throw new \RuntimeException( 'Failed to parse Ollama response: ' . json_last_error_msg() );
			}

			// Add usage data (Ollama provides eval_count and prompt_eval_count).
			$data['usage'] = $this->extractUsage( $data, $messages );

			return $data;
		} catch ( GuzzleException $e ) {
			throw new \RuntimeException( 'Ollama API request failed: ' . $e->getMessage(), 0, $e );
		}
	}

	/**
	 * Extract or estimate usage data from Ollama response.
	 *
	 * @param array<string, mixed>                        $response Response data.
	 * @param array<array{role: string, content: string}> $messages Input messages.
	 *
	 * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int}
	 */
	private function extractUsage( array $response, array $messages ): array {
		// Ollama may provide token counts in different fields.
		$prompt_tokens = $response['prompt_eval_count'] ?? 0;
		$completion_tokens = $response['eval_count'] ?? 0;

		// If not provided, estimate from character count (~4 chars per token).
		if ( 0 === $prompt_tokens ) {
			$prompt_text = '';
			foreach ( $messages as $msg ) {
				$prompt_text .= $msg['content'] ?? '';
			}
			$prompt_tokens = (int) ceil( mb_strlen( $prompt_text ) / 4 );
		}

		if ( 0 === $completion_tokens ) {
			$output_text = $response['message']['content'] ?? '';
			$completion_tokens = (int) ceil( mb_strlen( $output_text ) / 4 );
		}

		return [
			'prompt_tokens'     => $prompt_tokens,
			'completion_tokens' => $completion_tokens,
			'total_tokens'      => $prompt_tokens + $completion_tokens,
		];
	}

	/**
	 * Classify content against a vocabulary.
	 *
	 * @param string               $content    Post content to classify.
	 * @param array<string, mixed> $vocabulary Taxonomy vocabulary.
	 * @param string               $system_prompt System prompt.
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
	 * Parse classification response from Ollama.
	 *
	 * @param array<string, mixed> $response Raw Ollama response.
	 *
	 * @return array<string, mixed> Parsed classification.
	 */
	private function parseClassificationResponse( array $response ): array {
		$message_content = $response['message']['content'] ?? '';

		if ( empty( $message_content ) ) {
			return [ 'classifications' => [], 'error' => 'Empty response from LLM' ];
		}

		$parsed = json_decode( $message_content, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return [
				'classifications' => [],
				'error'           => 'Failed to parse JSON from LLM: ' . json_last_error_msg(),
				'raw_response'    => $message_content,
			];
		}

		return $parsed;
	}

	/**
	 * Check if Ollama is available.
	 *
	 * @return bool True if Ollama is reachable.
	 */
	public function isAvailable(): bool {
		try {
			$response = $this->client->get( '/api/tags' );
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
			$response = $this->client->get( '/api/tags' );
			$data = json_decode( (string) $response->getBody(), true );

			return array_map(
				fn( $model ) => $model['name'],
				$data['models'] ?? []
			);
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
		return 'ollama';
	}
}
