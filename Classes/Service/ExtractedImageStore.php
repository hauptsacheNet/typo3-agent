<?php

declare(strict_types=1);

namespace Hn\Agent\Service;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Disposable on-disk cache of the images extracted from a document, so the
 * extract step and the later view/store steps share the decoded originals
 * without re-parsing the source.
 *
 * Lives under `var/transient/` and is keyed by the *source document* (sys_file
 * UID + content SHA1), not by the chat task — tools have no task context, and
 * keying on the document makes the cache deterministic across calls. The cache
 * is best-effort: if an entry was cleaned away, callers re-extract via
 * {@see getOrProduce()}.
 */
class ExtractedImageStore
{
    private const BASE_SUBPATH = '/transient/agent/extracted-images/';

    /**
     * Persist the extracted originals + a manifest for one document.
     *
     * @param list<array{bytes: string, mime: string, sourceName: string, width: ?int, height: ?int}> $images
     */
    public function put(File $file, array $images): void
    {
        $dir = $this->dirFor($file);
        GeneralUtility::mkdir_deep($dir);

        $manifest = [];
        foreach (array_values($images) as $index => $image) {
            $filename = $index . '.' . $this->extensionFor((string)$image['mime']);
            GeneralUtility::writeFile($dir . $filename, (string)$image['bytes'], true);
            $manifest[] = [
                'index' => $index,
                'file' => $filename,
                'mime' => $image['mime'],
                'sourceName' => $image['sourceName'],
                'width' => $image['width'] ?? null,
                'height' => $image['height'] ?? null,
            ];
        }
        GeneralUtility::writeFile($dir . 'manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR), true);
    }

    /**
     * @return list<array{index: int, file: string, mime: string, sourceName: string, width: ?int, height: ?int}>|null
     */
    public function manifest(File $file): ?array
    {
        $path = $this->dirFor($file) . 'manifest.json';
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array{bytes: string, mime: string, name: string}|null
     */
    public function get(File $file, int $index): ?array
    {
        $manifest = $this->manifest($file);
        if ($manifest === null) {
            return null;
        }
        foreach ($manifest as $entry) {
            if ((int)($entry['index'] ?? -1) !== $index) {
                continue;
            }
            $path = $this->dirFor($file) . (string)($entry['file'] ?? '');
            if (!is_file($path)) {
                return null;
            }
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                return null;
            }
            return [
                'bytes' => $bytes,
                'mime' => (string)($entry['mime'] ?? 'application/octet-stream'),
                'name' => (string)($entry['sourceName'] ?? ('image-' . $index)),
            ];
        }
        return null;
    }

    /**
     * Read image $index, re-extracting (and re-caching) on a cache miss.
     *
     * @param callable(): list<array{bytes: string, mime: string, sourceName: string, width: ?int, height: ?int}> $produce
     * @return array{bytes: string, mime: string, name: string}|null
     */
    public function getOrProduce(File $file, int $index, callable $produce): ?array
    {
        $hit = $this->get($file, $index);
        if ($hit !== null) {
            return $hit;
        }
        $this->put($file, $produce());
        return $this->get($file, $index);
    }

    private function dirFor(File $file): string
    {
        $ns = substr(sha1($file->getUid() . ':' . $file->getSha1()), 0, 16);
        return Environment::getVarPath() . self::BASE_SUBPATH . $ns . '/';
    }

    private function extensionFor(string $mime): string
    {
        return match (strtolower($mime)) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'img',
        };
    }
}
