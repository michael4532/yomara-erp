<?php

namespace App\Modules\Helpdesk\Inbound;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;

/** Webhooks de réception : e-mails (Mailgun, SendGrid, Postmark, Brevo, MIME brut) et WhatsApp (Meta, UltraMsg, Green API). */
class InboundController extends Controller
{
    public function __construct(private InboundService $svc) {}

    protected function box(string $token): array
    {
        abort_unless(strlen($token) >= 32, 404);
        $box = Mailbox::where('token', $token)->first();
        abort_unless($box, 404);
        $c = Company::find($box->company_id);
        abort_unless($c && $c->status !== 'suspended' && $c->hasModule('helpdesk'), 404);

        return [$c, $box];
    }

    public function mail(Request $r, string $token)
    {
        [$c, $box] = $this->box($token);
        $out = [];
        foreach (InboundService::mailsFromRequest($r) as $m) {
            $res = $this->svc->handleMail($c, $m, $box);
            $out[] = ['status' => $res['status'], 'ticket' => $res['ticket']?->number, 'reason' => $res['reason'] ?? null];
        }

        return response()->json(['results' => $out]);
    }

    /** Meta vérifie l'adresse du webhook (hub.verify_token = jeton de la société). */
    public function verify(Request $r, string $token)
    {
        [, $box] = $this->box($token);
        abort_unless($r->query('hub_mode') === 'subscribe' && hash_equals($box->token, (string) $r->query('hub_verify_token')), 403);

        return response((string) $r->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']);
    }

    public function whatsapp(Request $r, string $token)
    {
        [$c, $box] = $this->box($token);
        if (! $box->whatsapp_enabled) {
            return response()->json(['results' => [], 'disabled' => true]);
        }
        $out = [];
        foreach (InboundService::whatsappMessages($r->all()) as $m) {
            $res = $this->svc->handleWhatsapp($c, $m, $box);
            $out[] = ['status' => $res['status'], 'ticket' => $res['ticket']?->number];
        }

        return response()->json(['results' => $out]);
    }
}
