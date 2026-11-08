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
    // The provider answered but didn't end normally (AIResponse::
    // endedNormally(): cut off at max tokens, or stopped for any other
    // reason) — only raised for a caller that asks AIModelRouter to reject
    // those (Smart Waiter). Fits the log's VARCHAR(20) column as is.
    case CUT_OFF = 'cut_off';
}
