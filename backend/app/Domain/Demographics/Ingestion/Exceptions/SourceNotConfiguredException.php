<?php

namespace App\Domain\Demographics\Ingestion\Exceptions;

use RuntimeException;

/**
 * Raised when a source cannot run because it needs something the environment does
 * not provide (an API key, a downloaded bulk file). Surfaced verbatim in the fetch
 * run log so a scheduled job that cannot work says why instead of failing silently.
 */
class SourceNotConfiguredException extends RuntimeException {}
