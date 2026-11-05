@extends('rdv.layout')
@section('title', 'Appels d\'offres')
@section('content')
  @include('fournisseurs.nav')
  <div class="card">
    <h2>Appels d'offres</h2>
    @forelse($rows as $t)
      @php($b = $bids[$t->id] ?? null)
      @php($label = match (true) { $t->status === 'awarded' && $b?->status === 'awarded' => ['Retenue', 'p-ok'], $t->status === 'awarded' => ['Non retenue', 'p-bad'], $t->isOpen() => [$b ? 'Offre déposée' : 'À répondre', $b ? 'p-ok' : 'p-wait'], default => ['Clos', 'p-info'] })
      <a class="item" href="{{ url('/fournisseur/'.$token.'/ao/'.$t->id) }}">
        <div class="sp"><b>{{ $t->title }}</b><div class="muted">{{ $t->number }} · date limite {{ $t->deadline->format('d/m/Y H:i') }}@if($b) · votre offre : {{ number_format($b->total, 0, ',', ' ') }} {{ $t->currency }}@endif</div></div>
        <span class="pill {{ $label[1] }}">{{ $label[0] }}</span>
      </a>
    @empty
      <p class="muted">Aucun appel d'offres pour le moment.</p>
    @endforelse
  </div>
@endsection
