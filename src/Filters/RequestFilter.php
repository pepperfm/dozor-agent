<?php

declare(strict_types=1);

namespace Dozor\Filters;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;

final class RequestFilter
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private readonly array $config)
    {
    }

    /**
     * @var list<string>
     */
    private array $excludedRoutes = [];

    /**
     * @param list<string> $patterns
     */
    public function exclude(array $patterns): void
    {
        foreach ($patterns as $pattern) {
            if (!is_string($pattern)) {
                continue;
            }

            $normalized = trim($pattern);
            if ($normalized !== '' && !in_array($normalized, $this->excludedRoutes, true)) {
                $this->excludedRoutes[] = $normalized;
            }
        }
    }

    public function shouldIgnoreRequest(Request $request, bool $checkResolvedRoute = true): bool
    {
        if ($this->isIgnoredPath('/' . ltrim($request->path(), '/'))) {
            return true;
        }
        if ($checkResolvedRoute && $this->isIgnoredRouteName($request->route()?->getName())) {
            return true;
        }
        if ($this->isIgnoredUserAgent($request->userAgent())) {
            return true;
        }

        return false;
    }

    private function isIgnoredPath(string $path): bool
    {
        $ignoreExact = (array) Arr::get($this->config, 'ignore_paths', []);
        foreach ($ignoreExact as $ignoredPath) {
            if (!is_string($ignoredPath)) {
                continue;
            }

            $normalized = trim($ignoredPath);
            if ($normalized !== '' && $this->matches($normalized, $path)) {
                return true;
            }
        }

        $ignorePrefixes = (array) Arr::get($this->config, 'ignore_path_prefixes', []);
        foreach ($ignorePrefixes as $ignoredPrefix) {
            if (!is_string($ignoredPrefix)) {
                continue;
            }

            $normalized = trim($ignoredPrefix);
            if ($normalized !== '' && str_starts_with($path, $normalized)) {
                return true;
            }
        }

        return false;
    }

    private function isIgnoredRouteName(?string $routeName): bool
    {
        if (!is_string($routeName) || $routeName === '') {
            return false;
        }

        $ignoreRouteNames = (array) Arr::get($this->config, 'ignore_route_names', []);
        foreach ($ignoreRouteNames as $ignoredRouteName) {
            if (!is_string($ignoredRouteName)) {
                continue;
            }

            $normalized = trim($ignoredRouteName);
            if ($normalized !== '' && $this->matches($normalized, $routeName)) {
                return true;
            }
        }

        return array_any($this->excludedRoutes, fn($pattern) => $this->matches($pattern, $routeName));
    }

    private function isIgnoredUserAgent(?string $userAgent): bool
    {
        if (!is_string($userAgent) || $userAgent === '') {
            return false;
        }

        $needle = strtolower($userAgent);
        $ignoreUserAgents = (array) Arr::get($this->config, 'ignore_user_agents_contains', []);

        foreach ($ignoreUserAgents as $ignoredAgent) {
            if (!is_string($ignoredAgent)) {
                continue;
            }

            $normalized = strtolower(trim($ignoredAgent));
            if ($normalized !== '' && str_contains($needle, $normalized)) {
                return true;
            }
        }

        return false;
    }

    private function matches(string $pattern, string $value): bool
    {
        if (str_contains($pattern, '*')) {
            return str($value)->is($pattern);
        }

        return $pattern === $value;
    }
}
