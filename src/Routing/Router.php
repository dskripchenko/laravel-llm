<?php

declare(strict_types=1);

namespace Dskripchenko\Llm\Routing;

use Dskripchenko\Llm\Exceptions\CloudProviderNotEnabled;
use Dskripchenko\Llm\Exceptions\NoRouteForPurpose;
use Dskripchenko\Llm\Models\Provider;
use Dskripchenko\Llm\Models\Route;
use Dskripchenko\Llm\Purpose;
use Psr\Log\LoggerInterface;

/**
 * Purpose (and scenario) → the chain of candidates: the route, its
 * fallback, the fallback's fallback… Disabled providers are skipped;
 * cloud providers that are not explicitly enabled are refused.
 */
final class Router
{
    /** Under Llm::fake(): with no route configured, calls go to a synthetic provider. */
    public bool $synthetic = false;

    private const MAX_CHAIN = 16;

    public function __construct(private readonly LoggerInterface $log) {}

    /**
     * @return non-empty-list<Candidate>
     *
     * @throws NoRouteForPurpose
     * @throws CloudProviderNotEnabled
     */
    public function candidates(Purpose $purpose, ?string $scenario = null): array
    {
        $route = $this->find($purpose, $scenario);
        if ($route === null) {
            if ($this->synthetic) {
                return [self::fakeCandidate()];
            }

            throw new NoRouteForPurpose($purpose->value, $scenario);
        }

        $candidates = [];
        $refused = [];
        $seen = [];
        while ($route !== null && ! isset($seen[$route->id]) && count($seen) < self::MAX_CHAIN) {
            $seen[$route->id] = true;
            $provider = $route->provider;
            if ($route->is_active && $provider !== null && $provider->is_enabled) {
                if ($provider->isBlockedCloud()) {
                    $refused[] = $provider->name;
                    $this->log->warning('LLM route to a cloud provider that is not enabled was skipped', [
                        'purpose' => $purpose->value, 'route' => $route->id, 'provider' => $provider->name,
                    ]);
                } else {
                    $candidates[] = new Candidate($provider, $route->model, $route->options ?? [], $route);
                }
            }
            $route = $route->fallback;
        }

        if ($candidates === []) {
            if ($refused !== []) {
                throw new CloudProviderNotEnabled($purpose->value, array_values(array_unique($refused)));
            }

            throw new NoRouteForPurpose($purpose->value, $scenario);
        }

        return $candidates;
    }

    /**
     * The entry route: among the active routes of the purpose (for the
     * scenario first, then without one), the one no other route falls back
     * to; the oldest if several are.
     */
    private function find(Purpose $purpose, ?string $scenario): ?Route
    {
        foreach ($scenario !== null ? [$scenario, null] : [null] as $narrow) {
            $query = Route::query()->with(['provider', 'fallback.provider'])->where('purpose', $purpose->value)->where('is_active', true);
            $narrow === null ? $query->whereNull('scenario') : $query->where('scenario', $narrow);
            $query->orderBy('id');
            $routes = $query->get();
            if ($routes->isEmpty()) {
                continue;
            }
            $fallbacks = $routes->pluck('fallback_route_id')->filter()->all();
            $entry = $routes->first(fn (Route $r) => ! in_array($r->id, $fallbacks, true));

            return $entry ?? $routes->first();
        }

        return null;
    }

    public static function fakeCandidate(): Candidate
    {
        $provider = new Provider;
        $provider->forceFill(['name' => 'fake', 'base_url' => 'http://llm.fake/v1', 'is_cloud' => false, 'is_enabled' => true]);

        return new Candidate($provider, 'fake-model');
    }
}
