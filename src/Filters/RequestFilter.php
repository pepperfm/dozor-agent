<?php

declare(strict_types=1);

namespace Dozor\Filters;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;

use function is_string;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function trim;

final readonly class RequestFilter
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private array $config)
    {
    }

    public function shouldIgnoreRequest(Request $request): bool
    {
        if ($this->isIgnoredPath('/' . ltrim($request->path(), '/'))) {
            return true;
        }

        $routeName = $request->route()?->getName();
        if ($this->isIgnoredRouteName($routeName)) {
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
            if ($normalized !== '' && $path === $normalized) {
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
            if ($normalized !== '' && $routeName === $normalized) {
                return true;
            }
        }

        return false;
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
}
