<?php

namespace App\Domain\Exceptions;

use App\Models\Vendor;
use RuntimeException;

/** Someone else changed the vendor's stage after the caller loaded it. */
final class StaleVendorException extends RuntimeException
{
    public function __construct(public readonly Vendor $current)
    {
        parent::__construct('This vendor was updated by someone else since you loaded it.');
    }
}
