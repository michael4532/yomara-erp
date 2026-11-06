<?php

namespace App\Modules\Pos;

use App\Http\Controllers\Api\EntityController;
use App\Http\Controllers\Controller;
use App\Modules\Pos\Models\PosSession;
use Illuminate\Http\Request;

class PosController extends Controller
{
    public function report(Request $r, PosReport $report, string $id)
    {
        $session = EntityController::visibleRecord(PosSession::class, $id, $r->user()) ?? abort(404);

        return ['data' => $report->forSession($session)];
    }
}
