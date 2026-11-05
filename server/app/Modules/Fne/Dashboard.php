<?php

namespace App\Modules\Fne;

use App\Models\Company;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $s = app(FneService::class)->stats();
        $p = 'fne.view|fne.manage';
        $out = [
            ['key' => 'fne_month', 'label' => 'Factures certifiées FNE ce mois', 'value' => $s['certified_month'], 'format' => 'number', 'icon' => 'verified', 'permission' => $p],
            ['key' => 'fne_waiting', 'label' => 'FNE en attente / refusées', 'value' => $s['pending'] + $s['failed'], 'format' => 'number', 'icon' => 'sync_problem', 'permission' => $p],
        ];
        if ($s['sticker_balance'] !== null) {
            $out[] = ['key' => 'fne_stickers', 'label' => 'Stickers FNE restants', 'value' => $s['sticker_balance'], 'format' => 'number', 'icon' => 'confirmation_number', 'permission' => $p];
        }

        return $out;
    }
}
