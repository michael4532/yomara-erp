<?php

namespace App\Modules\Core\Events;

use App\Modules\Core\Models\TradeDocument;

class DocumentValidated
{
    public function __construct(public TradeDocument $document) {}
}
