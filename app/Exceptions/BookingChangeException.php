<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when an instructor/admin tries to move or cancel a booking that is not
 * in a state where that is allowed.
 */
class BookingChangeException extends RuntimeException
{
    //
}