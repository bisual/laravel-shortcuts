<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts\Mcp;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * Base MCP tool with auth → rate limit → validation → authorize → run, plus shared error mapping.
 *
 * Extend this for non-CRUD tools. CRUD actions use {@see CrudMcpActionTool}, which shares the same pipeline trait.
 */
abstract class AuthenticatedMcpTool extends Tool
{
    use HandlesMcpToolRequest;

    /**
     * Max attempts per decay window. Set to null or 0 to disable rate limiting.
     */
    protected ?int $rateLimitMaxAttempts = 30;

    protected int $rateLimitDecaySeconds = 60;

    final public function handle(McpRequest $request): Response|ResponseFactory
    {
        $user = $this->requireAuthenticatedUser($request);

        if ($user instanceof Response) {
            return $user;
        }

        if ($this->rateLimitMaxAttempts !== null && $this->rateLimitMaxAttempts > 0) {
            $rate_limit_error = $this->enforceRateLimit(
                $this->rateLimitKey($user, $request),
                $this->rateLimitMaxAttempts,
                $this->rateLimitDecaySeconds,
            );

            if ($rate_limit_error !== null) {
                return $rate_limit_error;
            }
        }

        return $this->runMapped(function () use ($request, $user): Response|ResponseFactory {
            $validated = Validator::make($request->all(), $this->rules())->validate();

            $this->authorize($request, $user, $validated);

            return $this->run($request, $user, $validated);
        });
    }

    /**
     * Laravel validation rules for tool arguments.
     *
     * @return array<string, mixed>
     */
    abstract protected function rules(): array;

    /**
     * Domain authorization after validation. Throw {@see AuthorizationException} on failure.
     *
     * @param  array<string, mixed>  $validated
     */
    protected function authorize(McpRequest $request, Authenticatable $user, array $validated): void
    {
        //
    }

    /**
     * Tool business logic. May return a Response/ResponseFactory or a value converted via Response::structured.
     *
     * @param  array<string, mixed>  $validated
     */
    abstract protected function run(McpRequest $request, Authenticatable $user, array $validated): mixed;

    protected function rateLimitKey(Authenticatable $user, McpRequest $request): string
    {
        $encoded = json_encode($request->all(), 64); // JSON_SORT_KEYS
        $payload_hash = hash('xxh128', $encoded === false ? serialize($request->all()) : $encoded);

        return 'mcp-tool:'.$this->name().':'.$user->getAuthIdentifier().':'.$payload_hash;
    }
}
