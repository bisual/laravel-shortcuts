<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * Shared CrudRepository query dialect (with / where / scopes / append).
 * Auto-registered once when using {@see CrudMcpResource::tools()} / {@see CrudMcpResource::toolsFrom()}.
 */
#[Name(self::TOOL_NAME)]
#[Description('CrudRepository MCP query dialect: how to use with (nested relations via ..), WHERE filters, scopes, append, order_by, and select. Call this when you need syntax details; model-specific catalogs stay on each *-index / *-show tool.')]
final class CrudQueryGuideTool extends Tool
{
    public const TOOL_NAME = 'crud-query-guide';

    public function handle(Request $request): Response
    {
        return Response::text(ModelMcpQueryGuide::dialectGuide());
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function annotations(): array
    {
        return [
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ];
    }
}
