@php
  $steps = ['planned' => 'Commande préparée', 'in_transit' => 'En route', 'delivered' => 'Livrée'];
  $order = ['draft' => 0, 'planned' => 0, 'in_transit' => 1, 'delivered' => 2, 'failed' => 1, 'returned' => 1, 'cancelled' => 0];
  $pos = $order[$d->status] ?? 0;
  $labels = \App\Modules\Logistique\Models\Delivery::STATUSES;
@endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Livraison {{ $d->number }} — {{ $company->name }}</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #F4F6FB; color: #0B1B3A; }
  .wrap { max-width: 560px; margin: 0 auto; padding: 24px 16px 40px; }
  .card { background: #fff; border-radius: 16px; padding: 20px; border: 1px solid #E3E8F1; margin-bottom: 14px; }
  h1 { font-size: 20px; margin: 0 0 4px; } .muted { color: #5B6B85; font-size: 14px; }
  .status { display: inline-block; padding: 4px 12px; border-radius: 20px; font-weight: 700; font-size: 13px; margin-top: 10px; }
  .s-delivered { background: #E7F6EE; color: #12A150; } .s-in_transit { background: #E8F0FE; color: #1F6FEB; }
  .s-failed, .s-returned, .s-cancelled { background: #FCEAEA; color: #D93025; } .s-planned, .s-draft { background: #EEF1F6; color: #5B6B85; }
  .steps { display: flex; justify-content: space-between; margin: 22px 0 6px; position: relative; }
  .steps:before { content: ""; position: absolute; top: 13px; left: 12%; right: 12%; height: 3px; background: #E3E8F1; }
  .step { flex: 1; text-align: center; position: relative; font-size: 12px; color: #5B6B85; }
  .dot { width: 28px; height: 28px; border-radius: 50%; background: #E3E8F1; margin: 0 auto 6px; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; position: relative; }
  .done .dot { background: #12A150; } .done { color: #0B1B3A; font-weight: 600; }
  .ev { display: flex; gap: 12px; padding: 8px 0; border-bottom: 1px solid #F0F2F7; font-size: 14px; } .ev:last-child { border: 0; }
  .ev time { color: #5B6B85; min-width: 110px; }
  .row { display: flex; justify-content: space-between; padding: 6px 0; font-size: 14px; } .row b { text-align: right; }
  img.sig { max-width: 100%; max-height: 120px; border: 1px dashed #D5DCE8; border-radius: 8px; background: #fff; }
  .brand { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; font-weight: 700; color: #1F6FEB; }
  footer { text-align: center; color: #8A97AD; font-size: 12px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="brand">{{ $company->name }}</div>
  <div class="card">
    <h1>Livraison {{ $d->number }}</h1>
    <div class="muted">
      {{ $d->recipient_name }}@if ($d->city) · {{ $d->city }}@endif
      @if ($d->scheduled_date) · prévue le {{ $d->scheduled_date->format('d/m/Y') }}@if ($d->time_slot) ({{ $d->time_slot }})@endif @endif
    </div>
    <span class="status s-{{ $d->status }}">{{ $labels[$d->status] ?? $d->status }}</span>
    @if (! in_array($d->status, ['cancelled', 'failed', 'returned'], true))
      <div class="steps">
        @foreach ($steps as $k => $label)
          <div class="step {{ $loop->index <= $pos ? 'done' : '' }}"><div class="dot">{{ $loop->index <= $pos ? '✓' : '' }}</div>{{ $label }}</div>
        @endforeach
      </div>
    @endif
    @if ($d->status === 'in_transit' && $driver)
      <p class="muted">Votre livreur {{ $driver }} est en route.</p>
    @endif
    @if (in_array($d->status, ['failed', 'returned'], true))
      <p class="muted">La livraison n'a pas pu être effectuée{{ $d->failed_reason ? ' : '.$d->failed_reason : '' }}. Nous vous recontactons pour convenir d'une nouvelle date.</p>
    @endif
  </div>

  @if ($d->status === 'delivered')
    <div class="card">
      <div class="row"><span>Livrée le</span><b>{{ $d->delivered_at?->timezone(config('app.timezone'))->format('d/m/Y à H:i') }}</b></div>
      @if ($d->signed_by)<div class="row"><span>Remise à</span><b>{{ $d->signed_by }}</b></div>@endif
      @if ($d->signature)<p><img class="sig" src="{{ $d->signature }}" alt="Signature"></p>@endif
    </div>
  @endif

  @if ($d->items)
    <div class="card">
      <b>Contenu</b>
      @foreach ($d->items as $it)
        <div class="row"><span>{{ $it['description'] ?? '' }}</span><b>{{ isset($it['quantity']) ? rtrim(rtrim(number_format((float) $it['quantity'], 2, ',', ' '), '0'), ',') : '' }} {{ $it['unit'] ?? '' }}</b></div>
      @endforeach
      @if ($d->packages > 1)<div class="row"><span>Colis</span><b>{{ $d->packages }}</b></div>@endif
      @if ($d->cod_amount > 0 && $d->status !== 'delivered')<div class="row"><span>À régler à la livraison</span><b>{{ number_format($d->cod_amount, 0, ',', ' ') }} {{ $company->currency === 'XOF' ? 'F CFA' : $company->currency }}</b></div>@endif
    </div>
  @endif

  <div class="card">
    <b>Historique</b>
    @foreach ($events->reverse() as $e)
      <div class="ev"><time>{{ $e->created_at?->timezone(config('app.timezone'))->format('d/m H:i') }}</time><span>{{ $labels[$e->status] ?? $e->status }}@if ($e->note) — {{ $e->note }}@endif</span></div>
    @endforeach
  </div>
  <footer>{{ $company->name }}@if ($company->phone) · {{ $company->phone }}@endif</footer>
</div>
</body>
</html>
