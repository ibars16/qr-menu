<?php

namespace App\Service\AI;

/**
 * Why a single provider attempt failed — recorded on SmartWaiterExchangeLog
 * so provider health is visible in analytics without ever storing what was
 * actually asked.
 */
enum AIFailureReason: string
{
    case TIMEOUT = 'timeout';
    case RATE_LIMITED = 'rate_limited';
    case HTTP_ERROR = 'http_error';
    case INVALID_RESPONSE = 'invalid_response';
    case NETWORK_ERROR = 'network_error';
}
