<?php
/**
 * LLM Client Interface
 *
 * @package DGWTaxonomyAudit\Core
 */

declare(strict_types=1);

namespace DGWTaxonomyAudit\Core;

/**
 * Interface for LLM clients (Ollama, OpenAI, etc.).
 */
interface LLMClientInterface {

	/**
	 * Send a chat request.
	 *
	 * @param array<array{role: string, content: string}> $messages    Chat messages.
	 * @param bool                                        $json_format Request JSON response.
	 *
	 * @return array<string, mixed> Parsed response.
	 */
	public function chat( array $messages, bool $json_format = true ): array;

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
	): array;

	/**
	 * Check if the service is available.
	 *
	 * @return bool True if available.
	 */
	public function isAvailable(): bool;

	/**
	 * Get available models.
	 *
	 * @return array<string> List of model names.
	 */
	public function getModels(): array;

	/**
	 * Get the configured model name.
	 *
	 * @return string Model name.
	 */
	public function getModel(): string;

	/**
	 * Get the provider name.
	 *
	 * @return string Provider name (e.g., 'ollama', 'openai').
	 */
	public function getProvider(): string;
}
