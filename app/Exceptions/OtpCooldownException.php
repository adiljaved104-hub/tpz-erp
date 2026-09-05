<?php

namespace App\Exceptions;

class OtpCooldownException extends OtpChallengeException
{
    public function __construct(public readonly int $secondsRemaining)
    {
        parent::__construct("Please wait {$secondsRemaining} seconds before requesting another code.");
    }
}
