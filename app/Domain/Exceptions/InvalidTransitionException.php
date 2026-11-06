<?php

namespace App\Domain\Exceptions;

use DomainException;

/** The requested stage change breaks a workflow rule. */
final class InvalidTransitionException extends DomainException {}
