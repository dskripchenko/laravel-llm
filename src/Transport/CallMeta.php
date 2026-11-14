<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Transport;

use Dskripchenko\Llm\Purpose;

/**
 * Who is calling and why. The fake matches on it; a transport sends only
 * the attribution, and only to a provider that takes it.
 */
final class CallMeta
{
    public function __construct(
        public readonly Purpose $purpose,
        public readonly string $scenario,
        public readonly ?string $promptKey = null,
        public readonly ?Attribution $attribution = null,
    ) {}
}
