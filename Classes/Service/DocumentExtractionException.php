<?php

declare(strict_types=1);

namespace Hn\Agent\Service;

/**
 * Thrown by DocumentExtractorService when a third-party parser fails or
 * input parameters (sheet name, range, …) are invalid. Tools convert
 * this into a CallToolResult with isError=true.
 */
class DocumentExtractionException extends \RuntimeException
{
}
