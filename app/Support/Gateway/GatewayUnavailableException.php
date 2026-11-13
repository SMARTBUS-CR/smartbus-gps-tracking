<?php

namespace App\Support\Gateway;

use RuntimeException;

/**
 * The API Gateway could not say who owns a token: it did not answer in time,
 * could not be reached, or answered 5xx / 429. Not the same as rejecting the
 * token (401): the token may be perfectly valid.
 */
class GatewayUnavailableException extends RuntimeException {}
