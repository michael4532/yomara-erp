@extends('rdv.layout')
@section('title', 'Espace fournisseur')
@section('content')
  @include('fournisseurs.nav')
  <div class="card">
    <h2>Appels d'offres ouverts</h2>
    @forelse($tenders as $t)
      <a class="item" href="{{ url('/fournisseur/'.$token.'/ao/'.$t->id) }}">
        <div class="sp"><b>{{ $t->title }}</b><div class="muted">{{ $t->number }} · {{ count($t->lines) }} article(s) · réponse avant le {{ $t->deadline->format('d/m/Y à H:i') }}</div></div>
        <span class="pill {{ isset($bids[$t->id]) ? 'p-ok' : 'p-wait' }}">{{ isset($bids[$t->id]) ? 'Offre déposée' : 'À répondre' }}</span>
      </a>
    @empty
      <p class="muted">Aucun appel d'offres en cours.</p>
    @endforelse
  </div>
  <div class="card">
    <h2>Commandes à confirmer</h2>
    @forelse($toConfirm as $o)
      <a class="item" href="{{ url('/fournisseur/'.$token.'/commandes/'.$o->id) }}">
        <div class="sp"><b>{{ $o->number }}</b><div class="muted">du {{ $o->date->format('d/m/Y') }} · {{ number_format($o->total, 0, ',', ' ') }} {{ $o->currency }}</div></div>
        <span class="pill p-wait">À confirmer</span>
      </a>
    @empty
      <p class="muted">Aucune commande en attente de votre confirmation.</p>
    @endforelse
  </div>
  <div class="card">
    <div class="row" style="border:0;align-items:center"><h2 style="margin:0">Dernières factures</h2><a href="{{ url('/fournisseur/'.$token.'/factures') }}">Déposer une facture →</a></div>
    @forelse($invoices as $i)
      @php($st = $svc->invoiceState($i))
      <div class="item"><div class="sp"><b>N° {{ $i->number }}</b><div class="muted">{{ $i->date->format('d/m/Y') }} · {{ number_format($i->amount, 0, ',', ' ') }} {{ $i->currency }}</div></div><span class="pill {{ $st['status'] === 'paid' ? 'p-ok' : ($i->status === 'rejected' ? 'p-bad' : 'p-info') }}">{{ $st['label'] }}</span></div>
    @empty
      <p class="muted">Aucune facture déposée.</p>
    @endforelse
  </div>
@endsection
