<?php

namespace App\Modules\Evenements;

use App\Http\Controllers\Controller;
use App\Modules\Evenements\Models\Booking;
use App\Modules\Evenements\Models\Event;
use App\Modules\Evenements\Models\Space;
use App\Services\EntityWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EvenementsController extends Controller
{
    public function __construct(private EventService $svc) {}

    private function guard(callable $fn)
    {
        try {
            return $fn();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function stats(string $id)
    {
        return ['data' => $this->svc->stats(Event::findOrFail($id))];
    }

    public function invoiceBalance(string $id)
    {
        return $this->guard(fn () => ['data' => $this->svc->invoiceBalance(Booking::findOrFail($id))->fresh('lines')->toSyncArray(), 'booking' => Booking::find($id)->toSyncArray()]);
    }

    public function checkin(Request $r, string $id)
    {
        $b = Booking::findOrFail($id);
        if ($b->status !== 'confirmed') {
            return response()->json(['message' => 'Seul un exposant confirmé peut être accueilli.'], 422);
        }
        $b->checked_in_at = $r->boolean('undo') ? null : now();
        $b->save();

        return ['data' => $b->toSyncArray()];
    }

    /** Génère une rangée d'emplacements (A01…A10) sur le plan. */
    public function generateSpaces(Request $r, string $id)
    {
        $event = Event::findOrFail($id);
        $v = $r->validate([
            'prefix' => 'required|string|max:8', 'start' => 'integer|min:0|max:999', 'count' => 'required|integer|min:1|max:80',
            'x' => 'required|integer|min:0|max:79', 'y' => 'required|integer|min:0|max:79', 'w' => 'integer|min:1|max:20', 'h' => 'integer|min:1|max:20',
            'direction' => 'in:horizontal,vertical', 'gap' => 'integer|min:0|max:10', 'category' => 'nullable|string|max:80', 'zone' => 'nullable|string|max:80',
            'price' => 'nullable|numeric|min:0', 'area' => 'nullable|numeric|min:0',
        ]);
        $w = $v['w'] ?? 2;
        $h = $v['h'] ?? 2;
        $gap = $v['gap'] ?? 0;
        $start = $v['start'] ?? 1;
        $digits = max(2, strlen((string) ($start + $v['count'] - 1)));

        return $this->guard(function () use ($v, $event, $w, $h, $gap, $start, $digits) {
            $ids = DB::transaction(function () use ($v, $event, $w, $h, $gap, $start, $digits) {
                $ids = [];
                for ($i = 0; $i < $v['count']; $i++) {
                    $horizontal = ($v['direction'] ?? 'horizontal') === 'horizontal';
                    $data = array_filter([
                        'event_id' => $event->id, 'code' => $v['prefix'].str_pad((string) ($start + $i), $digits, '0', STR_PAD_LEFT),
                        'x' => $v['x'] + ($horizontal ? $i * ($w + $gap) : 0), 'y' => $v['y'] + ($horizontal ? 0 : $i * ($h + $gap)), 'w' => $w, 'h' => $h,
                        'category' => $v['category'] ?? null, 'zone' => $v['zone'] ?? null, 'price' => $v['price'] ?? null, 'area' => $v['area'] ?? null,
                    ], fn ($x) => $x !== null);
                    $res = app(EntityWriter::class)->upsert(Space::class, $id = (string) Str::uuid(), $data);
                    if ($res['status'] !== EntityWriter::APPLIED) {
                        throw new \DomainException($res['message'] === 'Données invalides.' ? 'Rangée invalide : '.collect($res['errors'])->flatten()->first() : $res['message']);
                    }
                    $ids[] = $id;
                }

                return $ids;
            });

            return response()->json(['data' => Space::whereIn('id', $ids)->orderBy('code')->get()->map->toSyncArray()], 201);
        });
    }

    public function run()
    {
        return ['data' => $this->svc->runDaily(app('tenant'))];
    }

    public function plan(string $id)
    {
        return ['data' => $this->svc->planState(Event::findOrFail($id))];
    }

    public function markPaid(Request $r, string $id)
    {
        $v = $r->validate(['method' => 'required|in:cash,mobile_money,bank_transfer,cheque,card,other', 'reference' => 'nullable|string|max:100']);
        $b = Booking::findOrFail($id);

        return $this->guard(fn () => ['data' => ['amount' => $this->svc->markPaid($b, $v['method'], $v['reference'] ?? null), 'booking' => $b->fresh()->toSyncArray()]]);
    }

    /** Fiche exposant / sponsor saisie par l'équipe (même contenu que l'espace en ligne de l'exposant). */
    public function profile(Request $r, string $id)
    {
        $b = Booking::findOrFail($id);
        $r->validate(['logo' => 'nullable|file|max:2048', 'published' => 'nullable|boolean'] + array_fill_keys(EventService::PROFILE_FIELDS, 'nullable|string|max:4000'));

        return $this->guard(fn () => ['data' => $this->svc->saveProfile($b, $r->only(EventService::PROFILE_FIELDS), $r->file('logo'), $r->has('published') ? $r->boolean('published') : null)->toSyncArray()]);
    }
}
