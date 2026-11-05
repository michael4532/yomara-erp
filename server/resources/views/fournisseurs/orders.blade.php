@extends('rdv.layout')
@section('title', 'Commandes')
@section('content')
  @include('fournisseurs.nav')
  <div class="card">
    <h2>Bons de commande</h2>
    @forelse($rows as $o)
      @php($a = ($acks[$o->id] ?? collect())->first())
      <a class="item" href="{{ url('/fournisseur/'.$token.'/commandes/'.$o->id) }}">
        <div class="sp"><b>{{ $o->number }}</b><div class="muted">du {{ $o->date->format('d/m/Y') }} · {{ number_format($o->total, 0, ',', ' ') }} {{ $o->currency }} TTC{{ $o->receipt_status === 'received' ? ' · livrée' : ($o->receipt_status === 'partial' ? ' · livrée en partie' : '') }}</div></div>
        <span class="pill {{ ! $a ? 'p-wait' : ($a->status === 'accepted' ? 'p-ok' : 'p-bad') }}">{{ ! $a ? 'À confirmer' : ($a->status === 'accepted' ? 'Confirmée · livraison le '.$a->delivery_date->format('d/m') : 'Refusée') }}</span>
      </a>
    @empty
      <p class="muted">Aucune commande.</p>
    @endforelse
  </div>
@endsection
