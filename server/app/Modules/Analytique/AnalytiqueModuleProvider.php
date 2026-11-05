<?php

namespace App\Modules\Analytique;

use App\Modules\Core\Events\DocumentCancelled;
use App\Modules\Core\Events\DocumentValidated;
use App\Modules\Core\Models\TradeDocument;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AnalytiqueModuleProvider extends ServiceProvider
{
    public function boot(): void
    {
        $an = fn () => app(AnalyticService::class);
        $on = fn () => app()->bound('tenant') && app('tenant')->hasModule('analytique');
        $alerts = function (array $sections) {
            if ($sections) {
                app(BudgetService::class)->checkAlerts(app('tenant'), $sections);
            }
        };

        Event::listen(DocumentValidated::class, function (DocumentValidated $e) use ($an, $on, $alerts) {
            if (! $on()) {
                return;
            }
            $doc = $e->document;
            if ($doc::DIRECTION === 'purchase' && in_array($doc->type, ['supplier_invoice', 'purchase_order'], true)) {
                $an()->assertRequiredAxes($doc); // refus : la validation est annulée
            }
            $touched = $an()->syncDocument($doc);
            if ($doc::DIRECTION === 'purchase') {
                if ($doc->type === 'purchase_order') {
                    $touched = $this->orderSections($doc);
                }
                $alerts($touched);
            }
        });
        Event::listen(DocumentCancelled::class, function (DocumentCancelled $e) use ($an, $on, $alerts) {
            if ($on()) {
                $touched = $an()->syncDocument($e->document);
                if ($e->document::DIRECTION === 'purchase') {
                    $alerts($touched ?: $this->orderSections($e->document));
                }
            }
        });

        if (class_exists(\App\Modules\NotesFrais\Models\Expense::class)) {
            $E = \App\Modules\NotesFrais\Models\Expense::class;
            $E::saving(function ($e) use ($an, $on) {
                if ($on() && $e->isDirty('status') && $e->status === 'submitted') {
                    $an()->assertExpenseAxes($e);
                }
            });
            $E::saved(function ($e) use ($an, $on, $alerts) {
                if ($on() && ($e->wasChanged(['status', 'analytic', 'amount', 'category_id', 'date']) || $e->wasRecentlyCreated)) {
                    $alerts($an()->syncExpense($e));
                }
            });
            $E::deleted(function ($e) use ($an, $on) {
                if ($on()) {
                    $an()->removeSource('expense', $e->id);
                }
            });
        }

        if (class_exists(\App\Modules\Paie\Models\PayrollRun::class)) {
            \App\Modules\Paie\Models\PayrollRun::updated(function ($run) use ($an, $on, $alerts) {
                if ($on() && $run->wasChanged('status')) {
                    $alerts($an()->syncPayroll($run));
                }
            });
        }

        if (class_exists(\App\Modules\Projets\Models\Project::class)) {
            $P = \App\Modules\Projets\Models\Project::class;
            $P::saved(function ($p) use ($an, $on) {
                if ($on() && ($p->wasRecentlyCreated || $p->wasChanged(['name', 'code', 'status', 'manager_id']))) {
                    $an()->projectSection($p);
                }
            });
            $P::deleted(function ($p) use ($an, $on) {
                if ($on() && ($s = $an()->projectSection($p, false))) {
                    $s->active = false;
                    $s->save();
                }
            });
        }
    }

    /** Sections touchées par un bon de commande (engagements). */
    private function orderSections(TradeDocument $doc): array
    {
        $doc->loadMissing('lines');
        $ids = array_keys((array) $doc->analytic);
        foreach ($doc->lines as $l) {
            $ids = array_merge($ids, array_keys((array) $l->analytic));
        }

        return array_values(array_unique($ids));
    }
}
