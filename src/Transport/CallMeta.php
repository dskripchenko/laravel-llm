<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Transport;

use Dskripchenko\Llm\Purpose;

/**
 * Who is calling and why. Transports ignore it; the fake matches on it.
 */
final class CallMeta
{
    public function __construct(
        public readonly Purpose $purpose,
        public readonly string $scenario,
        public readonly ?string $promptKey = null,
    ) {}
}
