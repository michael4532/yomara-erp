<?php

namespace App\Modules\Tresorerie\Bank;

use App\Models\Company;
use App\Modules\Tresorerie\Models\TreasuryAccount;
use App\Modules\Tresorerie\TreasuryService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Synchronisation bancaire : récupère les nouvelles opérations des sources connectées et les rapproche. */
class BankSync
{
    public function __construct(protected TreasuryService $treasury) {}

    public function sync(BankConnection $c, bool $manual = false): array
    {
        $acc = TreasuryAccount::withoutGlobalScopes()->where('company_id', $c->company_id)->find($c->account_id);
        if (! $acc) {
            throw new \DomainException('Compte de trésorerie introuvable.');
        }
        if ($c->provider === 'email') {
            return ['imported' => 0, 'message' => 'Les relevés arrivent par e-mail : rien à récupérer.'];
        }
        // Fenêtre : depuis la dernière synchronisation (avec 3 jours de recouvrement pour les opérations tardives)
        $from = ($c->last_sync_at ? $c->last_sync_at->copy()->subDays(3) : ($c->sync_from ?? now()->subDays(30)))->toDateString();
        $to = now()->toDateString();
        try {
            $r = match ($c->provider) {
                'wave' => Providers::wave($c, $from, $to),
                'gocardless' => Providers::gocardless($c, $from, $to),
                default => throw new \DomainException('Source inconnue.'),
            };
            $res = $this->treasury->importLines($acc, $r['lines'], (BankConnection::PROVIDERS[$c->provider] ?? $c->provider).' · du '.$from.' au '.$to, $manual ? (auth()->user()?->name) : 'Synchronisation automatique', $c->provider, $r['balance']);
            $c->forceFill(['status' => 'active', 'last_sync_at' => now(), 'next_sync_at' => now()->addHours(4), 'last_error' => null,
                'imported' => $c->imported + $res['imported'], 'last_balance' => $r['balance'] ?? $c->last_balance])->save();

            return $res + ['balance' => $r['balance']];
        } catch (\DomainException $e) {
            $c->forceFill(['status' => str_contains($e->getMessage(), 'expirée') ? 'expired' : 'error', 'last_error' => mb_substr($e->getMessage(), 0, 250), 'next_sync_at' => now()->addHours(2)])->save();
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('Synchronisation bancaire '.$c->id.' : '.$e->getMessage());
            $c->forceFill(['status' => 'error', 'last_error' => 'Service injoignable : '.mb_substr($e->getMessage(), 0, 200), 'next_sync_at' => now()->addHours(1)])->save();
            throw new \DomainException('La source ne répond pas pour le moment. Nouvel essai automatique dans une heure.');
        }
    }

    /** Tâche planifiée : toutes les connexions actives arrivées à échéance. */
    public function runDue(): array
    {
        $out = ['synced' => 0, 'failed' => 0, 'imported' => 0];
        $due = BankConnection::withoutGlobalScopes()->whereIn('provider', ['wave', 'gocardless'])->whereIn('status', ['active', 'error'])
            ->where(fn ($q) => $q->whereNull('next_sync_at')->orWhere('next_sync_at', '<=', now()))->limit(200)->get();
        foreach ($due as $c) {
            $company = Company::find($c->company_id);
            if (! $company || $company->isSuspended() || ! $company->hasModule('tresorerie')) {
                continue;
            }
            app()->instance('tenant', $company);
            try {
                $r = $this->sync($c);
                $out['synced']++;
                $out['imported'] += $r['imported'];
            } catch (\Throwable $e) {
                $out['failed']++;
            } finally {
                app()->forgetInstance('tenant');
            }
        }

        return $out;
    }

    /**
     * Relevés reçus par e-mail (transfert automatique depuis la banque) : chaque pièce jointe OFX, MT940, CAMT, CFONB,
     * CSV ou Excel est lue et importée sur le compte de la connexion.
     *
     * @param  array<int, array{name: string, data: string}>  $files
     */
    public function inbound(BankConnection $c, array $files, ?string $subject = null): array
    {
        $acc = TreasuryAccount::withoutGlobalScopes()->where('company_id', $c->company_id)->findOrFail($c->account_id);
        $total = ['files' => 0, 'imported' => 0, 'duplicates' => 0, 'skipped' => []];
        foreach ($files as $f) {
            $name = (string) ($f['name'] ?? 'releve');
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            try {
                if ($fmt = StatementFormats::detect($f['data'])) {
                    $p = StatementFormats::parse($f['data'], $fmt);
                    $r = $this->treasury->importLines($acc, $p['lines'], $name, 'E-mail'.($subject ? ' : '.mb_substr($subject, 0, 80) : ''), $fmt, $p['closing']);
                } elseif (in_array($ext, ['csv', 'txt', 'xlsx'], true)) {
                    $path = tempnam(sys_get_temp_dir(), 'stm').'.'.$ext;
                    file_put_contents($path, $f['data']);
                    $a = $this->treasury->analyzeStatement($path);
                    $m = $a['mapping'];
                    if (! isset($m['date']) || (! isset($m['amount']) && ! isset($m['debit']) && ! isset($m['credit']))) {
                        @unlink($path);
                        $total['skipped'][] = $name.' : colonnes Date / Montant non reconnues';

                        continue;
                    }
                    $r = $this->treasury->importStatement($acc, $path, $name, $m, false, 'E-mail');
                    @unlink($path);
                } else {
                    $total['skipped'][] = $name.' : format non pris en charge';

                    continue;
                }
                $total['files']++;
                $total['imported'] += $r['imported'];
                $total['duplicates'] += $r['duplicates'];
            } catch (\DomainException $e) {
                $total['skipped'][] = $name.' : '.$e->getMessage();
            }
        }
        $c->forceFill(['status' => 'active', 'last_sync_at' => now(), 'imported' => $c->imported + $total['imported'],
            'last_error' => $total['files'] ? null : (implode(' ; ', $total['skipped']) ?: 'Aucune pièce jointe exploitable')])->save();

        return $total;
    }

    public static function token(): string
    {
        return Str::lower(Str::random(32));
    }
}
