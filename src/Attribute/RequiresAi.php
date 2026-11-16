<?php

namespace App\Attribute;

/**
 * An /admin action that calls the AI: refused by
 * EventSubscriber\AiAccessGate when Service\AiAccess doesn't allow
 * $feature for the user's restaurant. JSON actions get a 403 with the
 * message; a form post ($redirectRoute) a flash and a redirect there.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class RequiresAi
{
    public function __construct(
        public readonly string $feature,
        public readonly ?string $redirectRoute = null,
    ) {
    }
}
