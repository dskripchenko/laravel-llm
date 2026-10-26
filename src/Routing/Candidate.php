<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Routing;

use Dskripchenko\Llm\Models\Provider;
use Dskripchenko\Llm\Models\Route;

/**
 * One step of a route chain: where a call goes if every earlier step failed.
 */
final class Candidate
{
    /**
     * @param  array<string, mixed>  $options  the route's default request options
     */
    public function __construct(
        public readonly Provider $provider,
        public readonly string $model,
        public readonly array $options = [],
        public readonly ?Route $route = null,
    ) {}

    public function label(): string
    {
        return $this->provider->name.'/'.$this->model;
    }
}
