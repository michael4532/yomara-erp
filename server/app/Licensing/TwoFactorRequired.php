<?php

namespace App\Licensing;

/** Le compte en ligne demande le code de double authentification pour relier l'installation. */
class TwoFactorRequired extends \RuntimeException
{
    public function __construct(public readonly array $data)
    {
        parent::__construct('Code de double authentification demandé.');
    }
}
