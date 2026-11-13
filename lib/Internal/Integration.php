<?php

namespace PostHog\Internal;

/**
 * The `<name>/<version>` an integration sends as `X-PostHog-Integration`, checked by Emails and PostHogTransport.
 *
 * @internal
 */
final class Integration
{
    public const RULE = "integration must be <name>/<version>, such as 'laravel/1.4.0': a name of lowercase "
        . 'letters, digits and hyphens, and a version of letters, digits, dots, plus signs and hyphens, '
        . 'at most 40 each.';

    private const PATTERN = '#^[a-z0-9-]{1,40}/[0-9A-Za-z.+-]{1,40}$#D';

    private function __construct()
    {
    }

    public static function isValid(mixed $integration): bool
    {
        return is_string($integration) && preg_match(self::PATTERN, $integration) === 1;
    }
}
