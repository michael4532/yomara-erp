<?php

namespace App\Modules\Relances;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Partner;
use App\Modules\Relances\Models\Reminder;
use Illuminate\Http\Request;

class RelancesController extends Controller
{
    public function __construct(private DunningService $svc) {}

    public function aging()
    {
        return ['data' => $this->svc->aging(app('tenant'))];
    }

    /** Relances prioritaires : clients dont le règlement relève le plus le point bas de trésorerie prévu. */
    public function priorities()
    {
        return response()->json(['data' => app(\App\Modules\Tresorerie\CashForecastService::class)->priorities()]);
    }

    public function settings()
    {
        return ['data' => $this->svc->settings(app('tenant')) + ['variables' => DunningService::VARIABLES, 'defaults' => DunningService::defaultLevels()]];
    }

    public function updateSettings(Request $r)
    {
        $v = $r->validate([
            'enabled' => 'boolean', 'min_amount' => 'numeric|min:0', 'skip_weekends' => 'boolean', 'reply_to' => 'nullable|email',
            'levels' => 'array|max:6', 'levels.*.days' => 'required|integer|min:0|max:365', 'levels.*.name' => 'required|string|max:80',
            'levels.*.subject' => 'nullable|string|max:200', 'levels.*.body' => 'required|string|max:5000',
            'levels.*.channels' => 'nullable|array', 'levels.*.channels.*' => 'in:email,whatsapp,sms',
            'before' => 'array', 'before.enabled' => 'boolean', 'before.days' => 'integer|min:1|max:30', 'before.subject' => 'nullable|string|max:200',
            'before.body' => 'string|max:5000', 'before.channels' => 'nullable|array', 'before.channels.*' => 'in:email,whatsapp,sms',
        ]);
        if (isset($v['levels'])) {
            $days = array_column($v['levels'], 'days');
            if (count($days) !== count(array_unique($days))) {
                return response()->json(['message' => 'Deux paliers ne peuvent pas avoir le même délai.'], 422);
            }
        }
        $c = app('tenant');
        $s = $c->settings ?? [];
        $s['relances'] = array_merge($this->svc->settings($c), $v);
        $c->forceFill(['settings' => $s])->save();

        return $this->settings();
    }

    public function partner(string $id)
    {
        $p = Partner::findOrFail($id);
        $docs = $this->svc->overdue(app('tenant'), $p->id);

        return ['data' => [
            'partner' => $p->only(['id', 'name', 'email', 'phone', 'dunning_paused']),
            'invoices' => $docs->map(fn ($d) => ['id' => $d->id, 'number' => $d->number, 'date' => $d->date->toDateString(), 'due_date' => $this->svc->dueOf($d)->toDateString(),
                'balance' => $d->balance(), 'overdue' => $d->overdueAmount(), 'currency' => $d->currency, 'days' => $this->svc->lateDays($d), 'level' => $d->dunning_level, 'schedule' => $d->schedule])->values(),
            'history' => Reminder::where('partner_id', $p->id)->latest('id')->limit(50)->get(),
        ]];
    }

    public function preview(Request $r, string $id)
    {
        $level = (int) $r->query('level', 1);
        $p = Partner::findOrFail($id);
        $docs = $this->svc->overdue(app('tenant'), $p->id);
        $cfg = $this->svc->settings(app('tenant'))['levels'][$level - 1] ?? null;
        if (! $cfg || $docs->isEmpty()) {
            return response()->json(['message' => 'Rien à relancer.'], 422);
        }
        [$text] = $this->svc->render(app('tenant'), $p, $docs, (string) $cfg['body']);

        return ['data' => ['subject' => $cfg['subject'] ?? '', 'text' => $text]];
    }

    public function send(Request $r, string $id)
    {
        $v = $r->validate(['level' => 'nullable|integer|min:1|max:6']);
        try {
            $rem = $this->svc->sendNow(app('tenant'), Partner::findOrFail($id), $v['level'] ?? null);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return ['data' => $rem];
    }

    public function pause(Request $r, string $id)
    {
        $p = Partner::findOrFail($id);
        $p->dunning_paused = $r->boolean('paused');
        $p->save();

        return ['data' => $p->only(['id', 'dunning_paused'])];
    }

    public function history()
    {
        $rows = Reminder::latest('id')->limit(200)->get();
        $names = Partner::withTrashed()->whereIn('id', $rows->pluck('partner_id'))->pluck('name', 'id');

        return ['data' => $rows->map(fn ($r) => $r->toArray() + ['partner_name' => $names[$r->partner_id] ?? '—'])];
    }

    public function run()
    {
        return ['data' => $this->svc->run(app('tenant'))];
    }
}
