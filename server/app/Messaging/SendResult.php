<?php

namespace App\Messaging;

class SendResult
{
    public function __construct(public bool $ok, public ?string $id = null, public ?string $error = null) {}

    public static function ok(?string $id = null): self
    {
        return new self(true, $id);
    }

    public static function fail(string $error): self
    {
        return new self(false, null, mb_substr($error, 0, 1000));
    }
}
