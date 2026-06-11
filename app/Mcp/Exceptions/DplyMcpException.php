<?php

declare(strict_types=1);

namespace App\Mcp\Exceptions;

use RuntimeException;

/**
 * Thrown by MCP tools/resources for expected, user-facing failures
 * (missing auth context, cross-organization access, unsupported site type, …).
 *
 * AbstractDplyTool catches it and converts the message into a structured
 * MCP error response so the AI client sees a clean explanation rather than a
 * 500. Use it for "the caller did something we can explain" — not for bugs.
 */
class DplyMcpException extends RuntimeException {}
