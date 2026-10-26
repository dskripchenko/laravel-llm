<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Exceptions;

/**
 * Every route left for a purpose leads to a cloud provider an
 * administrator has not enabled. The package never sends a prompt there.
 */
final class CloudProviderNotEnabled extends LlmException
{
    /**
     * @param  list<string>  $providers
     */
    public function __construct(public readonly string $purpose, public readonly array $providers)
    {
        parent::__construct(sprintf(
            'Refusing to route purpose `%s` to cloud provider(s) %s: a cloud provider has to be enabled explicitly (cloud_enabled)',
            $purpose,
            implode(', ', array_map(fn (string $p) => "`{$p}`", $providers)),
        ));
    }

    public function errorCode(): string
    {
        return 'cloud_not_enabled';
    }

    public function details(): array
    {
        return ['providers' => $this->providers];
    }
}
