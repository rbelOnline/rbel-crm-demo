<?php

namespace App\Exceptions;

use RuntimeException;

/** An email was not attempted because a precondition failed (nothing was logged as sent). */
class EmailNotSendable extends RuntimeException
{
    /** @param  array<string, list<string>>  $errors  field errors for a 422 response */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}
