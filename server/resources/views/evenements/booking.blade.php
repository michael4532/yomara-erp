@extends('rdv.layout')
@section('title', 'Réservation '.$b->number)
@section('content')
  <div class="card">
    <h2 style="margin-bottom:4px">{{ $event->name }}</h2>
    <div class="muted">{{ $event->start_date->format('d/m/Y') }}{{ $event->venue ? ' · '.$event->venue : '' }}</div>
  </div>
  <div class="card">
    <div class="row"><span>Réservation</span><b>{{ $b->number }}</b></div>
    <div class="row"><span>Exposant</span><b>{{ $b->exhibitor_name }}</b></div>
    @if($codes)<div class="row"><span>Emplacement(s)</span><b>{{ $codes }}</b></div>@endif
    @foreach($b->items ?? [] as $it)<div class="row"><span>{{ $it['name'] }}</span><b>× {{ $it['quantity'] }}</b></div>@endforeach
    <div class="row"><span>Total TTC</span><b>{{ number_format($a['total'], 0, ',', ' ') }} {{ $cur }}</b></div>
    <div class="row"><span>État</span><b>{{ ['option' => 'En attente de l\'acompte', 'confirmed' => 'Confirmée', 'expired' => 'Option expirée', 'cancelled' => 'Annulée'][$b->status] ?? $b->status }}</b></div>
  </div>
  @if($b->status === 'confirmed')
    <div class="flash ok">Merci ! Votre réservation est confirmée. Nous vous contactons pour la suite (badges, montage).</div>
  @endif
  @if(in_array($b->status, ['option', 'confirmed'], true))
    <a class="btn ghost" style="margin-bottom:14px" href="{{ url()->current() }}/espace">Présenter mon entreprise (logo, description, liens)</a>
  @endif
  @if($b->status === 'confirmed')
  @elseif($payUrl)
    <div class="card">
      @php
        $inv = collect($invoices)->first(fn ($i) => $i['status'] === 'validated' && $i['payment_status'] !== 'paid');
      @endphp
      <p>Vos emplacements sont réservés jusqu'au <b>{{ $b->option_until?->format('d/m/Y') }}</b>. Réglez l'acompte @if($inv)de <b>{{ number_format($inv['total'] - $inv['amount_paid'], 0, ',', ' ') }} {{ $cur }}</b>@endif pour confirmer immédiatement votre réservation.</p>
      <a class="btn" href="{{ $payUrl }}">Payer l'acompte en ligne</a>
    </div>
  @endif
@endsection
