<?php

declare(strict_types=1);

namespace Hn\Agent\Service;

/**
 * Converts the RTE (HTML) body of an agent instruction into clean plain text /
 * lightweight Markdown for LLM consumption.
 *
 * The instruction body is authored with a rich-text editor and stored as HTML,
 * but the LLM should receive readable text rather than raw markup. This keeps
 * the structure that carries meaning (lists, paragraphs, emphasis) while
 * dropping the tags. Used by both the system-prompt injection (mode "always")
 * and the GetInstruction tool (mode "on_demand") so the model never sees raw
 * HTML.
 *
 * Deliberately dependency-free (no HTML-to-Markdown library): a small,
 * predictable transform covering the structures the default RTE produces.
 */
class InstructionTextFormatter
{
    public function toPromptText(string $html): string
    {
        $text = $html;

        // Normalize line breaks and block boundaries to newlines.
        $text = preg_replace('#<\s*br\s*/?\s*>#i', "\n", $text) ?? $text;
        $text = preg_replace('#</\s*(p|div|h[1-6]|tr)\s*>#i', "\n", $text) ?? $text;

        // List items → "- " bullets (closing tag becomes a newline above for <li> too).
        $text = preg_replace('#<\s*li[^>]*>#i', "- ", $text) ?? $text;
        $text = preg_replace('#</\s*li\s*>#i', "\n", $text) ?? $text;

        // Bold / strong → Markdown emphasis.
        $text = preg_replace('#<\s*(strong|b)\s*>#i', '**', $text) ?? $text;
        $text = preg_replace('#</\s*(strong|b)\s*>#i', '**', $text) ?? $text;

        // Drop all remaining tags, then decode entities.
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Collapse runs of spaces/tabs, trim trailing space on lines, and
        // squeeze 3+ blank lines down to a single blank line.
        $text = preg_replace('#[ \t]+#', ' ', $text) ?? $text;
        $text = preg_replace('#[ \t]+\n#', "\n", $text) ?? $text;
        $text = preg_replace('#\n{3,}#', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
