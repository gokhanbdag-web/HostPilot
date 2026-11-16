<?php
declare(strict_types=1);

namespace HostPilot\Core;

/**
 * Context-safe escaping for plain HTML text and quoted HTML attributes.
 * Callers must still use URL validation for URL schemes and JS/ CSS-specific encoders
 * when embedding values in those contexts.
 */
final class Html
{
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
