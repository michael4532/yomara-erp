<?php

namespace App\Messaging\Providers;

use App\Messaging\Message;
use App\Messaging\SendResult;

interface Provider
{
    /** @param array $config paramètres déchiffrés du canal */
    public function send(Message $m, array $config): SendResult;
}
