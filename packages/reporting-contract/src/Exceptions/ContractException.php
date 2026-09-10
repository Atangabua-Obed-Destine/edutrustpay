<?php

declare(strict_types=1);

namespace EdutrustPay\Contract\Exceptions;

use RuntimeException;

/**
 * Base for every failure raised by this package.
 *
 * These are deliberately exceptions rather than return values: building a payload
 * that conflates "cannot report" with "zero" is a correctness failure that must
 * stop the client, not a warning it can log and push anyway.
 */
class ContractException extends RuntimeException
{
}
