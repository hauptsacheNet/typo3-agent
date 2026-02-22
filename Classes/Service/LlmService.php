<?php

declare(strict_types=1);

namespace Hn\Agent\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

class LlmService
{
    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * Send a chat completion request to the OpenAI-compatible LLM API.
     *
     * @param array $messages The messages array (OpenAI format)
     * @param array $tools    The tools array (OpenAI function calling format)
     * @return array The assistant message from choices[0].message
     * @throws \RuntimeException on HTTP or API errors
     */
    public function chatCompletion(array $messages, array $tools = []): array
    {
        $config = $this->extensionConfiguration->get('agent');
        $apiUrl = rtrim($config['apiUrl'] ?? '', '/') . '/chat/completions';
        $apiKey = $config['apiKey'] ?? '';
        $model = $config['model'] ?? 'anthropic/claude-haiku-4-5';

        if (empty($apiKey)) {
            throw new \RuntimeException('Agent extension: apiKey is not configured. Set it in Settings > Extension Configuration > agent.');
        }

        $body = [
            'model' => $model,
            'messages' => $messages,
        ];

        if (!empty($tools)) {
            $body['tools'] = $tools;
        }

        $response = $this->requestFactory->request($apiUrl, 'POST', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $body,
            'connect_timeout' => 10,
            'timeout' => 120,
        ]);

        $statusCode = $response->getStatusCode();
        $responseBody = (string)$response->getBody();

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(
                'LLM API returned HTTP ' . $statusCode . ': ' . $responseBody
            );
        }

        $data = json_decode($responseBody, true);
        if (!is_array($data)) {
            throw new \RuntimeException('LLM API returned invalid JSON: ' . $responseBody);
        }

        $message = $data['choices'][0]['message'] ?? null;
        if (!is_array($message)) {
            throw new \RuntimeException('LLM API response missing choices[0].message: ' . $responseBody);
        }

        return $message;
    }
}
