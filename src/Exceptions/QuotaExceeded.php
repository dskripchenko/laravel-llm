<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Exceptions;

/**
 * Thrown before a model is called when a daily token quota is used up.
 */
final class QuotaExceeded extends LlmException
{
    /**
     * @param  'tenant'|'project'  $scope
     */
    public function __construct(
        public readonly string $scope,
        public readonly string $key,
        public readonly int $limit,
        public readonly int $used,
    ) {
        parent::__construct("Daily LLM token quota of {$scope} `{$key}` is used up: {$used} of {$limit}");
    }

    public function errorCode(): string
    {
        return 'quota_exceeded';
    }

    public function details(): array
    {
        return ['scope' => $this->scope, 'key' => $this->key, 'limit' => $this->limit, 'used' => $this->used];
    }
}
