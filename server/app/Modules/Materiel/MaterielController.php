<?php

namespace App\Modules\Materiel;

use App\Http\Controllers\Controller;
use App\Modules\Materiel\Models\Checkout;
use Illuminate\Http\Request;

class MaterielController extends Controller
{
    protected function find(Request $r, string $id): Checkout
    {
        $x = Checkout::findOrFail($id);
        $u = $r->user();
        if (! $u->hasPermission('materiel.view|materiel.manage')) {
            $mine = \Illuminate\Support\Facades\DB::table('rh_employees')->where('company_id', app('tenant')->id)->where('user_id', $u->id)->value('id');
            abort_unless($x->holder_user_id === $u->id || $x->created_by === $u->id || ($mine && $x->employee_id === $mine), 403, 'Cette sortie ne vous concerne pas.');
        }

        return $x;
    }

    public function pdf(Request $r, string $id, MaterielPdf $pdf)
    {
        $x = $this->find($r, $id);

        return response($pdf->checkout(app('tenant'), $x), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$x->number.'.pdf"']);
    }

    /** (Re)demande de signature électronique du bon. */
    public function signature(Request $r, string $id)
    {
        $x = $this->find($r, $id);
        abort_unless(app('tenant')->hasModule('signature'), 422, 'Activez le module Signature électronique, ou imprimez le bon pour le faire signer sur papier.');
        abort_if(in_array($x->signature_status, ['sent', 'signed'], true), 422, $x->signature_status === 'signed' ? 'Le bon est déjà signé.' : 'La demande de signature est déjà en cours.');
        abort_unless(collect($x->signers)->contains(fn ($s) => ! empty($s['user_id']) || ! empty($s['email'])), 422, 'Aucun signataire joignable (compte ou e-mail) : renseignez l\'e-mail du porteur et du responsable dans leur fiche salarié.');
        $sid = $x->requestSignature();
        abort_unless($sid, 422, 'La demande de signature n\'a pas pu être envoyée.');

        return ['data' => $x->fresh()->toSyncArray()];
    }

    public function settings()
    {
        $c = app('tenant');

        return ['managers' => (object) data_get($c->settings, 'materiel.managers', []), 'signature' => $c->hasModule('signature')];
    }

    /** Responsables par département (signataires du bon). */
    public function updateSettings(Request $r)
    {
        $v = $r->validate(['managers' => 'present|array', 'managers.*' => 'array', 'managers.*.*' => 'uuid']);
        $c = app('tenant');
        $c->forceFill(['settings' => array_merge($c->settings ?? [], ['materiel' => ['managers' => array_filter($v['managers'])]])])->save();

        return $this->settings();
    }
}
