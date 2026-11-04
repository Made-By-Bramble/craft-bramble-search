<?php

declare(strict_types=1);

namespace MadeByBramble\BrambleSearch\helpers;

/** Distinguishes invalid derived data from a retryable storage connection failure. */
final class PrefixIntegrityException extends \RuntimeException
{
}
