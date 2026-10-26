<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a student tries to file a booking request that the flow does not
 * allow: a duplicate request for the same slot, too many open requests, or a
 * slot that is too close to its start time to be serviceable.
 */
class BookingRequestException extends RuntimeException
{
    //
}