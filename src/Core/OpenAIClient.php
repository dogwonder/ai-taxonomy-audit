<?php
/**
 * OpenAI API Client
 *
 * @package DGWTaxonomyAudit\Core
 */

declare(strict_types=1);

namespace DGWTaxonomyAudit\Core;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Client for interacting with OpenAI API.
 */
class OpenAIClient implements LLMClientInterface {

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
		$defaults = \DGWTaxonomyAudit\get_config()['openai'] ?? [
			'model'       => 'gpt-4o-mini',
			'temperature' => 0.3,
			'timeout'     => 120,
		];

		$this->config = array_merge( $defaults, $config );

		// Get API key.
		$this->api_key = defined( 'OPENAI_API_KEY' ) ? OPENAI_API_KEY : '';

		if ( empty( $this->api_key ) && empty( $config['api_key'] ) ) {
			throw new \RuntimeException(
				'OpenAI API key not configured. Define OPENAI_API_KEY in wp-config.php'
			);
		}

		if ( ! empty( $config['api_key'] ) ) {
			$this->api_key = $config['api_key'];
		}

		$this->client = new Client( [
			'base_uri' => 'https://api.openai.com/v1/',
			'timeout'  => $this->config['timeout'],
			'headers'  => [
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
			],
		] );
	}

	/**
	 * Send a chat request to OpenAI.
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

		if ( $json_format ) {
			$payload['response_format'] = [ 'type' => 'json_object' ];
		}

		try {
			$response = $this->client->post( 'chat/completions', [
				'json' => $payload,
			] );

			$body = (string) $response->getBody();
			$data = json_decode( $body, true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				throw new \RuntimeException( 'Failed to parse OpenAI response: ' . json_last_error_msg() );
			}

			// Transform to match Ollama response format.
			return [
				'message' => [
					'role'    => $data['choices'][0]['message']['role'] ?? 'assistant',
					'content' => $data['choices'][0]['message']['content'] ?? '',
				],
				'usage' => $data['usage'] ?? [],
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

			throw new \RuntimeException( 'OpenAI API request failed: ' . $message, 0, $e );
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

		$parsed = json_decode( $message_content, true );

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
	 * Check if OpenAI is available.
	 *
	 * @return bool True if API key is valid.
	 */
	public function isAvailable(): bool {
		if ( empty( $this->api_key ) ) {
			return false;
		}

		try {
			$response = $this->client->get( 'models' );
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

			// Filter to GPT models only.
			$models = array_filter( $models, fn( $m ) => str_starts_with( $m, 'gpt-' ) );

			sort( $models );

			return array_values( $models );
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
		return 'openai';
	}
}
