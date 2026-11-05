@extends('portail.layout')
@section('title', 'Accueil')
@section('content')
  <h1>Bonjour {{ $partner->name }}</h1>
  <p class="muted">Retrouvez ici tous vos documents avec {{ $company->name }}.</p>
  <div class="grid">
    <div class="card kpi {{ $overdue > 0 ? 'alert' : '' }}" style="margin:0"><div class="muted">Reste à payer</div><div class="v">{{ $fmt($due) }}</div>@if($overdue > 0)<div class="muted" style="color:var(--bad)">dont {{ $fmt($overdue) }} en retard</div>@endif</div>
    <div class="card kpi" style="margin:0"><div class="muted">Devis en attente</div><div class="v">{{ $quotes->count() }}</div></div>
    <div class="card kpi" style="margin:0"><div class="muted">Achats {{ now()->year }}</div><div class="v">{{ $fmt($year) }}</div></div>
  </div>

  @if($unpaid->isNotEmpty())
  <div class="card">
    <div class="row"><h2 class="sp" style="margin:0">Factures à régler</h2><a href="{{ url('/portail/'.$token.'/releve') }}" class="muted">Relevé de compte →</a></div>
    <table style="margin-top:8px">
      <tr><th>Facture</th><th class="hide-sm">Échéance</th><th class="r">Reste dû</th><th></th></tr>
      @foreach($unpaid as $d)
        <tr>
          <td><a href="{{ url('/portail/'.$token.'/document/'.$d->id) }}"><strong>{{ $d->number }}</strong></a><div class="muted">{{ $d->date->format('d/m/Y') }}</div></td>
          <td class="hide-sm">{{ $d->due_date?->format('d/m/Y') }} @if($d->due_date && $d->due_date->lt(today()))<span class="pill bad">En retard</span>@endif</td>
          <td class="r"><strong>{{ $fmt($d->balance(), $d->currency) }}</strong></td>
          <td class="r"><a class="btn sm" href="{{ url('/portail/'.$token.'/document/'.$d->id.'/payer') }}">Payer</a></td>
        </tr>
      @endforeach
    </table>
  </div>
  @endif

  @if($quotes->isNotEmpty())
  <div class="card">
    <h2>Devis en attente de votre accord</h2>
    <div class="list">
      @foreach($quotes as $q)
        <a class="item" href="{{ url('/portail/'.$token.'/document/'.$q->id) }}">
          <div class="sp"><strong>{{ $q->number }}</strong><div class="muted">du {{ $q->date->format('d/m/Y') }}@if($q->due_date) · valable jusqu'au {{ $q->due_date->format('d/m/Y') }}@endif</div></div>
          <strong>{{ $fmt($q->total, $q->currency) }}</strong><span class="btn sm green">Voir et accepter</span>
        </a>
      @endforeach
    </div>
  </div>
  @endif

  @if($deliveries->isNotEmpty())
  <div class="card">
    <h2>Livraisons en cours</h2>
    <div class="list">
      @foreach($deliveries as $l)
        <a class="item" href="{{ $l->trackingUrl() }}">
          <div class="sp"><strong>{{ $l->number }}</strong><div class="muted">prévue le {{ $l->scheduled_date?->format('d/m/Y') }}{{ $l->time_slot ? ' · '.$l->time_slot : '' }}</div></div>
          <span class="pill {{ $l->status === 'in_transit' ? 'wait' : ($l->status === 'failed' ? 'bad' : 'info') }}">{{ ['planned' => 'Prévue', 'in_transit' => 'En route', 'failed' => 'À reprogrammer'][$l->status] ?? $l->status }}</span>
        </a>
      @endforeach
    </div>
  </div>
  @endif

  @if($orders->isNotEmpty())
  <div class="card">
    <div class="row"><h2 class="sp" style="margin:0">Dernières commandes</h2><a href="{{ url('/portail/'.$token.'/commandes') }}" class="muted">Toutes →</a></div>
    <div class="list">
      @foreach($orders as $o)
        <a class="item" href="{{ url('/portail/'.$token.'/document/'.$o->id) }}"><div class="sp"><strong>{{ $o->number }}</strong><div class="muted">{{ $o->date->format('d/m/Y') }}</div></div><strong>{{ $fmt($o->total, $o->currency) }}</strong></a>
      @endforeach
    </div>
  </div>
  @endif

  <div class="card row">
    <div class="sp"><strong>Une question ?</strong><div class="muted">Écrivez-nous depuis votre espace, nous vous répondons ici et par e-mail.</div></div>
    <a class="btn ghost" href="{{ url('/portail/'.$token.'/messages') }}">Nous écrire</a>
  </div>
@endsection
