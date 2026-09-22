<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts\Mcp;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Throwable;

/**
 * Shared MCP request pipeline pieces (auth, rate limit, error mapping).
 */
trait HandlesMcpToolRequest
{
    protected function requireAuthenticatedUser(McpRequest $request): Authenticatable|Response
    {
        $user = $request->user();

        if ($user === null) {
            return Response::error('Unauthenticated.');
        }

        return $user;
    }

    protected function enforceRateLimit(
        string $key,
        int $max_attempts,
        int $decay_seconds,
    ): ?Response {
        if ($max_attempts <= 0) {
            return null;
        }

        if (RateLimiter::tooManyAttempts($key, $max_attempts)) {
            return Response::error('Rate limit exceeded. Try again later.');
        }

        RateLimiter::hit($key, $decay_seconds);

        return null;
    }

    /**
     * @param  callable(): (Response|ResponseFactory|mixed)  $callback
     */
    protected function runMapped(callable $callback): Response|ResponseFactory
    {
        try {
            $result = $callback();
        } catch (AuthorizationException) {
            return Response::error('Permission denied.');
        } catch (ValidationException $exception) {
            return Response::error(collect($exception->errors())->flatten()->implode(' '));
        } catch (Throwable $exception) {
            return Response::error($exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.');
        }

        if ($result instanceof Response || $result instanceof ResponseFactory) {
            return $result;
        }

        return Response::structured($this->normalizeStructuredResult($result));
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeStructuredResult(mixed $result): array
    {
        if (is_array($result)) {
            return $result;
        }

        if (is_object($result) && method_exists($result, 'toArray')) {
            /** @var array<string, mixed> $array */
            $array = $result->toArray();

            return $array;
        }

        return ['data' => $result];
    }
}
