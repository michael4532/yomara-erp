@php
  $types = \App\Modules\Interventions\Models\Intervention::TYPES;
  $statuses = \App\Modules\Interventions\Models\Intervention::STATUSES;
  $cur = $company->currency;
  $fmt = fn ($v) => number_format((float) $v, in_array($cur, ['XOF', 'XAF', 'GNF'], true) ? 0 : 2, ',', ' ').' '.(in_array($cur, ['XOF', 'XAF'], true) ? 'F CFA' : $cur);
  $h = $i->worked_minutes ? intdiv($i->worked_minutes, 60).' h '.str_pad($i->worked_minutes % 60, 2, '0', STR_PAD_LEFT) : null;
@endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Intervention {{ $i->number }} — {{ $company->name }}</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #F4F6FB; color: #0B1B3A; font-size: 15px; }
  .wrap { max-width: 760px; margin: 0 auto; padding: 24px 16px 40px; }
  .card { background: #fff; border-radius: 16px; padding: 20px; border: 1px solid #E3E8F1; margin-bottom: 14px; }
  h1 { font-size: 22px; margin: 2px 0 4px; } h2 { font-size: 15px; margin: 0 0 10px; } .muted { color: #5B6B85; font-size: 14px; }
  .kicker { font-size: 12px; letter-spacing: .08em; font-weight: 700; color: #5B6B85; }
  .pill { display: inline-block; padding: 4px 12px; border-radius: 20px; font-weight: 700; font-size: 13px; }
  .s-done { background: #E7F6EE; color: #12A150; } .s-en_route, .s-in_progress { background: #FFF6DB; color: #9A6B00; } .s-cancelled { background: #FCEAEA; color: #D93025; } .s-planned, .s-requested { background: #E8F0FE; color: #1F6FEB; }
  .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 8px 20px; }
  .kv span { display: block; font-size: 12px; color: #5B6B85; } .kv b { font-weight: 600; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; } td, th { padding: 7px 4px; border-bottom: 1px solid #EEF1F6; text-align: left; } th { color: #5B6B85; font-size: 12px; } td.r, th.r { text-align: right; }
  .check { display: flex; gap: 8px; padding: 4px 0; } .ok { color: #12A150; font-weight: 700; } .no { color: #B0B9C9; font-weight: 700; }
  .pre { white-space: pre-line; line-height: 1.5; }
  .photos { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 8px; } .photos img { width: 100%; height: 130px; object-fit: cover; border-radius: 10px; }
  img.sig { max-width: 280px; max-height: 120px; border: 1px dashed #D5DCE8; border-radius: 8px; background: #fff; }
  .stars { color: #E8A317; font-size: 18px; letter-spacing: 2px; }
  .btn { display: inline-block; padding: 9px 14px; border-radius: 10px; border: 1px solid #E3E8F1; background: #fff; font-weight: 700; cursor: pointer; font-size: 14px; }
  footer { text-align: center; color: #5B6B85; font-size: 12px; margin-top: 18px; }
  @media print { body { background: #fff; } .card { border: 0; padding: 8px 0; } .noprint { display: none; } }
</style>
</head>
<body><div class="wrap">
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
    <div style="width:40px;height:40px;border-radius:11px;background:#FFC531;display:flex;align-items:center;justify-content:center;font-weight:800">{{ mb_substr($company->name, 0, 1) }}</div>
    <div style="flex:1"><strong>{{ $company->name }}</strong><div class="muted">{{ $company->phone }} {{ $company->email }}</div></div>
    <button class="btn noprint" onclick="window.print()">Imprimer / PDF</button>
  </div>

  <div class="card">
    <div class="kicker">{{ $i->status === 'done' ? 'RAPPORT D\'INTERVENTION' : 'INTERVENTION' }}</div>
    <h1>{{ $i->number }} — {{ $i->title }}</h1>
    <div class="muted">{{ $types[$i->type] ?? '' }}@if($i->scheduled_at) · prévue le {{ $i->scheduled_at->format('d/m/Y à H:i') }}@endif</div>
    <div style="margin-top:10px"><span class="pill s-{{ $i->status }}">{{ $statuses[$i->status] ?? $i->status }}</span></div>
    <div class="grid" style="margin-top:16px">
      <div class="kv"><span>Client</span><b>{{ $partner?->name ?? $i->contact_name }}</b></div>
      <div class="kv"><span>Lieu</span><b>{{ trim(($i->address ?? '').' '.($i->city ?? '')) ?: '—' }}</b></div>
      @if($equipment)<div class="kv"><span>Équipement</span><b>{{ $equipment->name }}{{ $equipment->brand ? ' · '.$equipment->brand : '' }}{{ $equipment->model ? ' '.$equipment->model : '' }}</b>@if($equipment->serial_number)<div class="muted">N° série {{ $equipment->serial_number }}</div>@endif</div>@endif
      @if($technician)<div class="kv"><span>Technicien</span><b>{{ $technician }}</b></div>@endif
      @if($i->started_at)<div class="kv"><span>Arrivée</span><b>{{ $i->started_at->format('d/m/Y H:i') }}</b></div>@endif
      @if($i->finished_at)<div class="kv"><span>Fin</span><b>{{ $i->finished_at->format('d/m/Y H:i') }}</b></div>@endif
      @if($h)<div class="kv"><span>Temps passé</span><b>{{ $h }}</b></div>@endif
    </div>
  </div>

  @if($i->description)
  <div class="card"><h2>Demande</h2><div class="pre">{{ $i->description }}</div></div>
  @endif

  @if($i->report)
  <div class="card"><h2>Travaux réalisés</h2><div class="pre">{{ $i->report }}</div>
    @if($i->recommendations)<h2 style="margin-top:16px">Recommandations</h2><div class="pre">{{ $i->recommendations }}</div>@endif
  </div>
  @endif

  @if(!empty($i->checklist))
  <div class="card"><h2>Points de contrôle</h2>
    @foreach($i->checklist as $c)<div class="check"><span class="{{ !empty($c['done']) ? 'ok' : 'no' }}">{{ !empty($c['done']) ? '✓' : '○' }}</span><span>{{ $c['text'] }}</span></div>@endforeach
  </div>
  @endif

  @if(!empty($i->parts))
  <div class="card"><h2>Pièces et fournitures</h2>
    <table><tr><th>Désignation</th><th class="r">Qté</th></tr>
      @foreach($i->parts as $p)<tr><td>{{ $p['description'] }}</td><td class="r">{{ rtrim(rtrim(number_format($p['quantity'], 2, ',', ' '), '0'), ',') }}</td></tr>@endforeach
    </table>
  </div>
  @endif

  @if(!empty($i->photos))
  <div class="card"><h2>Photos</h2><div class="photos">@foreach($i->photos as $ph)<a href="{{ $ph['url'] }}" target="_blank"><img src="{{ $ph['url'] }}" alt="{{ $ph['caption'] ?? '' }}"></a>@endforeach</div></div>
  @endif

  @if($i->signature || $i->signed_by)
  <div class="card"><h2>Validation du client</h2>
    <div class="muted">{{ $i->signed_by }}{{ $i->signed_at ? ' · le '.$i->signed_at->format('d/m/Y à H:i') : '' }}</div>
    @if($i->signature)<img class="sig" src="{{ $i->signature }}" alt="Signature" style="margin-top:8px">@endif
    @if($i->satisfaction)<div style="margin-top:8px">Satisfaction : <span class="stars">{{ str_repeat('★', $i->satisfaction) }}{{ str_repeat('☆', 5 - $i->satisfaction) }}</span></div>@endif
  </div>
  @endif

  <footer>{{ $company->name }} · {{ $company->address }} {{ $company->city }}<br>Rapport généré par {{ config('erp.name') }}</footer>
</div></body>
</html>
