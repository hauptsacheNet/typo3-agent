<?php

declare(strict_types=1);

namespace Hn\Agent\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Backend AJAX endpoint that proxies a GET request to {apiUrl}/models on
 * behalf of the extension configuration form. Proxying (rather than letting
 * the browser call the provider directly) avoids CORS issues and keeps the
 * API key out of cross-origin traffic.
 *
 * Accepts the currently-entered (unsaved) apiUrl + apiKey so the dropdown
 * can refresh live as the editor types, without requiring a save-reload cycle.
 */
#[AsController]
class ModelFetchController
{
    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {}

    public function fetchAction(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $apiUrl = trim((string)($params['apiUrl'] ?? ''));
        $apiKey = trim((string)($params['apiKey'] ?? ''));

        if ($apiUrl === '' || $apiKey === '') {
            return new JsonResponse([
                'ok' => false,
                'error' => 'apiUrl and apiKey are required',
            ], 400);
        }

        if (!preg_match('#^https?://#i', $apiUrl)) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'apiUrl must start with http:// or https://',
            ], 400);
        }

        $modelsUrl = rtrim($apiUrl, '/') . '/models';

        try {
            $response = $this->requestFactory->request($modelsUrl, 'GET', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Accept' => 'application/json',
                ],
                'connect_timeout' => 5,
                'timeout' => 15,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'Request to ' . $modelsUrl . ' failed: ' . $e->getMessage(),
            ], 502);
        }

        $status = $response->getStatusCode();
        $body = (string)$response->getBody();

        if ($status < 200 || $status >= 300) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'Provider responded with HTTP ' . $status,
                'detail' => $this->truncate($body, 500),
            ], 502);
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'Provider returned non-JSON response',
            ], 502);
        }

        $models = $this->normalizeModels($data);
        if ($models === []) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'No models found in provider response',
            ], 502);
        }

        sort($models, SORT_STRING | SORT_FLAG_CASE);

        return new JsonResponse([
            'ok' => true,
            'models' => $models,
        ]);
    }

    /**
     * Extract a flat list of model IDs. Handles the canonical OpenAI shape
     * ({"data": [{"id": "..."}]}) as well as a few common variants.
     *
     * @return string[]
     */
    private function normalizeModels(array $data): array
    {
        $items = $data['data'] ?? $data['models'] ?? $data;
        if (!is_array($items)) {
            return [];
        }

        $ids = [];
        foreach ($items as $item) {
            if (is_string($item) && $item !== '') {
                $ids[$item] = true;
                continue;
            }
            if (!is_array($item)) {
                continue;
            }
            $id = $item['id'] ?? $item['name'] ?? $item['model'] ?? null;
            if (is_string($id) && $id !== '') {
                $ids[$id] = true;
            }
        }
        return array_keys($ids);
    }

    private function truncate(string $s, int $max): string
    {
        return strlen($s) > $max ? substr($s, 0, $max) . '…' : $s;
    }
}
