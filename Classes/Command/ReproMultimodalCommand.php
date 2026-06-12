<?php

declare(strict_types=1);

namespace Hn\Agent\Command;

use Hn\Agent\Service\LlmService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'agent:repro-multimodal',
    description: 'Send a multimodal tool-result (image or PDF in tool message) to multiple LLMs and report which providers accept it',
)]
class ReproMultimodalCommand extends Command
{
    private const MODELS = [
        'anthropic/claude-haiku-4.5',
        'openai/gpt-5.4-mini',
        'google/gemini-3.1-flash-lite',
        'mistralai/mistral-small-2603',
    ];

    private const COUNTS = [1, 3];

    public function __construct(
        private readonly LlmService $llmService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('image', 'i', InputOption::VALUE_REQUIRED, 'Absolute path to an image file', '/var/www/html/ai-tasks-current.png');
        $this->addOption('pdf', 'p', InputOption::VALUE_REQUIRED, 'Absolute path to a PDF file', null);
        // Legacy alias kept so older invocations keep working.
        $this->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Deprecated alias for --image', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $assets = [];

        $imagePath = (string)($input->getOption('file') ?? $input->getOption('image'));
        if ($imagePath !== '') {
            if (!is_file($imagePath) || !is_readable($imagePath)) {
                $output->writeln('<error>Image not readable: ' . $imagePath . '</error>');
                return Command::FAILURE;
            }
            $assets[] = $this->loadAsset($imagePath, 'image', 'image/png');
        }

        $pdfPath = (string)($input->getOption('pdf') ?? '');
        if ($pdfPath !== '') {
            if (!is_file($pdfPath) || !is_readable($pdfPath)) {
                $output->writeln('<error>PDF not readable: ' . $pdfPath . '</error>');
                return Command::FAILURE;
            }
            $assets[] = $this->loadAsset($pdfPath, 'pdf', 'application/pdf');
        }

        if ($assets === []) {
            $output->writeln('<error>No assets to test. Pass --image and/or --pdf.</error>');
            return Command::FAILURE;
        }

        foreach ($assets as $asset) {
            $output->writeln(sprintf(
                '<comment>Asset %s: %s (%s, %d bytes)</comment>',
                $asset['label'],
                $asset['path'],
                $asset['mime'],
                $asset['bytes'],
            ));
        }
        $output->writeln(sprintf(
            '<comment>Testing tool-result-multimodality against %d models × %d counts × %d asset(s)</comment>',
            count(self::MODELS),
            count(self::COUNTS),
            count($assets),
        ));
        $output->writeln('');

        $rows = [];
        $errors = [];
        foreach (self::MODELS as $model) {
            $row = [$model];
            foreach ($assets as $asset) {
                foreach (self::COUNTS as $count) {
                    $output->writeln(sprintf('  → %s, %s×%d ...', $model, $asset['label'], $count));
                    $error = $this->runOne($asset, $count, $model);
                    if ($error === null) {
                        $row[] = '<info>OK</info>';
                    } else {
                        $ref = count($errors) + 1;
                        $errors[$ref] = sprintf('%s, %s×%d: %s', $model, $asset['label'], $count, $error);
                        $row[] = '<error>FAIL [' . $ref . ']</error>';
                    }
                }
            }
            $rows[] = $row;
        }

        $output->writeln('');
        $headers = ['Model'];
        foreach ($assets as $asset) {
            foreach (self::COUNTS as $count) {
                $headers[] = $asset['label'] . '×' . $count;
            }
        }
        $table = new Table($output);
        $table->setHeaders($headers);
        $table->setRows($rows);
        $table->render();

        if ($errors !== []) {
            $output->writeln('');
            $output->writeln('<comment>Errors:</comment>');
            foreach ($errors as $ref => $msg) {
                $output->writeln(sprintf('  [%d] %s', $ref, $msg));
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{label: string, path: string, mime: string, bytes: int, filename: string, data_uri: string}
     */
    private function loadAsset(string $path, string $label, string $mimeFallback): array
    {
        $bytes = (string)file_get_contents($path);
        $mime = mime_content_type($path) ?: $mimeFallback;
        return [
            'label' => $label,
            'path' => $path,
            'mime' => $mime,
            'bytes' => strlen($bytes),
            'filename' => basename($path),
            'data_uri' => 'data:' . $mime . ';base64,' . base64_encode($bytes),
        ];
    }

    /**
     * @param array{label: string, mime: string, filename: string, data_uri: string} $asset
     * @return string|null Error message, or null on success
     */
    private function runOne(array $asset, int $count, string $model): ?string
    {
        $toolCalls = [];
        for ($i = 1; $i <= $count; $i++) {
            $toolCalls[] = [
                'id' => 'call_repro_' . $i,
                'type' => 'function',
                'function' => ['name' => 'ReadFile', 'arguments' => json_encode(['uid' => $i])],
            ];
        }

        $messages = [
            ['role' => 'system', 'content' => 'Du bist ein Test-Assistent. Beschreibe kurz die Dateien, die du in den Tool-Resultaten siehst.'],
            ['role' => 'user', 'content' => 'Bitte lies die ' . $count . ' angehängten Dateien.'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => $toolCalls],
        ];
        foreach ($toolCalls as $i => $tc) {
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $tc['id'],
                'content' => [
                    ['type' => 'text', 'text' => 'Inhalt der Datei #' . ($i + 1) . ':'],
                    $this->buildMediaBlock($asset),
                ],
            ];
        }

        try {
            $this->llmService->chatCompletion($messages, [], $model);
            return null;
        } catch (\Throwable $e) {
            return $this->shortError($e->getMessage());
        }
    }

    /**
     * @param array{mime: string, filename: string, data_uri: string} $asset
     * @return array<string, mixed>
     */
    private function buildMediaBlock(array $asset): array
    {
        if (str_starts_with($asset['mime'], 'image/')) {
            return ['type' => 'image_url', 'image_url' => ['url' => $asset['data_uri']]];
        }
        return ['type' => 'file', 'file' => ['filename' => $asset['filename'], 'file_data' => $asset['data_uri']]];
    }

    private function shortError(string $message): string
    {
        // Strip leading "LLM API returned HTTP NNN: " wrapper
        if (preg_match('/HTTP \d+:\s*(.*)$/s', $message, $m)) {
            $message = $m[1];
        }
        $decoded = json_decode($message, true);
        if (is_array($decoded)) {
            $err = $decoded['error'] ?? [];
            // OpenRouter nests the provider's real message inside metadata.raw (escaped JSON)
            $raw = $err['metadata']['raw'] ?? null;
            if (is_string($raw)) {
                $inner = json_decode($raw, true);
                if (is_array($inner)) {
                    $innerMsg = $inner['error']['message'] ?? $inner['message'] ?? null;
                    if (is_string($innerMsg) && $innerMsg !== '') {
                        return substr($innerMsg, 0, 200);
                    }
                }
            }
            // Fall back to outer message, but append metadata if present (some providers
            // omit metadata.raw and put the detail elsewhere)
            $outer = (string)($err['message'] ?? '');
            $extra = '';
            if (isset($err['metadata']) && is_array($err['metadata'])) {
                $extra = ' [' . json_encode($err['metadata']) . ']';
            }
            if ($outer !== '') {
                return substr($outer . $extra, 0, 240);
            }
        }
        return substr($message, 0, 240);
    }
}