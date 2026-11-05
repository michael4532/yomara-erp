<?php

namespace App\Modules\Pos;

use App\Modules\Core\Events\DocumentCancelled;
use App\Modules\Core\Events\DocumentValidated;
use App\Modules\Pos\Models\PosSession;
use App\Modules\Ventes\Models\SalesPayment;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Un ticket ou un paiement peut arriver d'un autre appareil après la clôture
 * de la session (synchro tardive) : on recalcule alors les chiffres de clôture.
 */
class PosModuleProvider extends ServiceProvider
{
    public function boot(): void
    {
        $refresh = function (?string $sessionId) {
            if (! $sessionId) {
                return;
            }
            $session = PosSession::withoutGlobalScopes()->find($sessionId);
            if ($session && $session->status === 'closed') {
                $session->refreshTotals();
            }
        };

        Event::listen(DocumentValidated::class, fn ($e) => $refresh($e->document->pos_session_id));
        Event::listen(DocumentCancelled::class, fn ($e) => $refresh($e->document->pos_session_id));
        SalesPayment::saved(fn (SalesPayment $p) => $refresh($p->document()->value('pos_session_id')));
    }
}
