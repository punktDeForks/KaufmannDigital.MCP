<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Utility;

/**
 * Thrown when target dimensions given by a tool caller are incomplete, unknown or not allowed.
 * The message is meant to be passed on to the MCP client.
 */
class DimensionResolutionException extends \InvalidArgumentException
{
}
