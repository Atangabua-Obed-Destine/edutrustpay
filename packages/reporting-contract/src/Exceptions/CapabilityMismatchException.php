<?php

declare(strict_types=1);

namespace EdutrustPay\Contract\Exceptions;

/**
 * Raised when declared capabilities and supplied figures disagree in either
 * direction — the single rule the whole platform rests on (brief §2, §7.3).
 */
class CapabilityMismatchException extends ContractException
{
}
