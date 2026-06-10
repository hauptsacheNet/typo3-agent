<?php

declare(strict_types=1);

namespace Hn\Agent\Service\Llm\Content;

use Symfony\AI\Platform\Message\Content\ContentInterface;

/**
 * A user-message content part for non-image documents (e.g. PDFs).
 *
 * Symfony AI ships content normalizers only for Text/Image/ImageUrl/Audio.
 * Non-image documents are an OpenRouter/OpenAI feature expressed as a
 * `{type:'file', file:{filename, file_data}}` block. Implementing
 * \JsonSerializable lets the (already registered) JsonSerializableNormalizer
 * emit exactly that wire shape without us having to register a custom
 * normalizer on the Contract.
 */
final class DocumentContent implements ContentInterface, \JsonSerializable
{
    public function __construct(
        private readonly string $filename,
        private readonly string $dataUrl,
    ) {}

    /**
     * @return array{type: 'file', file: array{filename: string, file_data: string}}
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => 'file',
            'file' => [
                'filename' => $this->filename,
                'file_data' => $this->dataUrl,
            ],
        ];
    }
}
