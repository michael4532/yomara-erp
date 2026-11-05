@extends('rdv.layout')
@section('title', 'Paiements')
@section('content')
  @include('fournisseurs.nav')
  <div class="card">
    <div class="row"><span>Total payé</span><b>{{ number_format($paid, 0, ',', ' ') }} {{ $company->currency }}</b></div>
    <div class="row"><span>Reste à payer</span><b>{{ number_format($due, 0, ',', ' ') }} {{ $company->currency }}</b></div>
  </div>
  <div class="card">
    <h2>Factures et règlements</h2>
    @forelse($docs as $d)
      <div class="item" style="align-items:flex-start">
        <div class="sp"><b>Facture {{ $d->reference ?: $d->number }}</b><div class="muted">du {{ $d->date->format('d/m/Y') }}{{ $d->due_date ? ' · échéance '.$d->due_date->format('d/m/Y') : '' }} · {{ number_format($d->total, 0, ',', ' ') }} {{ $d->currency }}</div>
          @foreach($payments[$d->id] ?? [] as $p)<div class="muted">✓ {{ number_format($p->amount, 0, ',', ' ') }} payés le {{ $p->date->format('d/m/Y') }}{{ $p->reference ? ' · '.$p->reference : '' }}</div>@endforeach
        </div>
        <span class="pill {{ $d->payment_status === 'paid' ? 'p-ok' : ($d->payment_status === 'partial' ? 'p-info' : 'p-wait') }}">{{ ['paid' => 'Payée', 'partial' => 'Payée en partie', 'unpaid' => 'À payer'][$d->payment_status] ?? $d->payment_status }}</span>
      </div>
    @empty
      <p class="muted">Aucune facture enregistrée.</p>
    @endforelse
  </div>
@endsection
