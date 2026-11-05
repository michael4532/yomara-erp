<?php

namespace App\Modules\Pos;

use App\Http\Controllers\Controller;
use App\Modules\Pos\Models\PosSession;

class PosController extends Controller
{
    public function report(PosReport $report, string $id)
    {
        return ['data' => $report->forSession(PosSession::findOrFail($id))];
    }
}
