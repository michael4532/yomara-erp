@extends('portail.layout')
@section('title', 'Fidélité')
@php($types = \App\Modules\Fidelite\Models\LoyaltyTransaction::TYPES)
@section('content')
  <h1>Programme de fidélité</h1>
  @if(! $sum)
    <div class="card"><p>Vous n'avez pas encore de carte de fidélité. Elle est créée automatiquement lors de votre prochain achat : vous gagnez 1 point par tranche de {{ $fmt($settings['per_amount']) }}.</p></div>
  @else
    <div class="grid">
      <div class="card kpi" style="margin:0;background:var(--night);color:#fff;border:0">
        <div style="color:#C9D4EA;font-size:13px">Carte n° {{ $sum['card']->number }}</div>
        <div class="v" style="font-size:34px">{{ number_format($sum['card']->points, 0, ',', ' ') }} <span style="font-size:16px;font-weight:600">points</span></div>
        <div style="color:#FFC531;font-weight:700">{{ $sum['card']->tier ?: 'Membre' }}</div>
      </div>
      <div class="card kpi" style="margin:0"><div class="muted">Valeur de vos points</div><div class="v">{{ $fmt($sum['value']) }}</div><div class="muted">utilisables en caisse{{ $settings['min_redeem'] ? ' dès '.$settings['min_redeem'].' points' : '' }}</div></div>
      <div class="card kpi" style="margin:0"><div class="muted">Gagnés sur 12 mois</div><div class="v">{{ number_format($sum['card']->earned_12m, 0, ',', ' ') }}</div>@if($sum['next'])<div class="muted">Encore {{ number_format($sum['next']['min'] - $sum['card']->earned_12m, 0, ',', ' ') }} pour le niveau {{ $sum['next']['name'] }} (× {{ rtrim(rtrim(number_format($sum['next']['multiplier'], 2, ',', ''), '0'), ',') }})</div>@endif</div>
    </div>
    @if($sum['expiring'] > 0)<div class="flash bad">{{ $sum['expiring'] }} point(s) expirent dans les 30 prochains jours : pensez à les utiliser.</div>@endif
    <div class="card">
      <h2>Historique</h2>
      <table>
        <tr><th>Date</th><th>Opération</th><th class="r">Points</th></tr>
        @foreach($sum['history'] as $t)
          <tr><td>{{ $t->created_at->format('d/m/Y') }}</td><td>{{ $types[$t->type] ?? $t->type }}{{ $t->note ? ' — '.$t->note : '' }}</td><td class="r" style="font-weight:700;color:{{ $t->points >= 0 ? 'var(--ok)' : 'var(--bad)' }}">{{ $t->points >= 0 ? '+' : '' }}{{ $t->points }}</td></tr>
        @endforeach
      </table>
    </div>
  @endif
  @if($rewards->isNotEmpty())
    <div class="card"><h2>Récompenses</h2>
      @foreach($rewards as $r)<div class="row" style="padding:8px 0;border-bottom:1px solid var(--line)"><span class="sp"><strong>{{ $r->name }}</strong></span><span class="pill {{ $sum && $sum['card']->points >= $r->points_cost ? 'ok' : 'grey' }}">{{ number_format($r->points_cost, 0, ',', ' ') }} points</span></div>@endforeach
      <p class="muted">À demander en caisse lors de votre prochain passage.</p>
    </div>
  @endif
  @if($gifts->isNotEmpty())
    <div class="card"><h2>Vos cartes cadeaux</h2>
      @foreach($gifts as $g)<div class="row" style="padding:8px 0"><span class="sp"><strong>{{ $g->code }}</strong><div class="muted">{{ $g->expires_at ? 'valable jusqu\'au '.$g->expires_at->format('d/m/Y') : '' }}</div></span><strong>{{ $fmt($g->balance) }}</strong></div>@endforeach
    </div>
  @endif
  <p class="muted">Règle du programme : 1 point par tranche de {{ $fmt($settings['per_amount']) }} d'achat ; 1 point = {{ $fmt($settings['point_value']) }}{{ $settings['expiry_months'] ? ' ; les points expirent '.$settings['expiry_months'].' mois après leur gain' : '' }}.</p>
@endsection
