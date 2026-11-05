<?php

namespace App\Modules\Core\Events;

use App\Modules\Core\Models\TradeDocument;

class DocumentCancelled
{
    public function __construct(public TradeDocument $document) {}
}
