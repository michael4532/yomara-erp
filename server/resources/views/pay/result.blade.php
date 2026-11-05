@extends('pay.layout')
@section('title', 'Résultat du paiement')
@section('content')
  <div class="card" style="text-align:center">
    @if ($tx->status === 'paid')
      <div style="font-size:48px">✅</div>
      <h1>Paiement reçu</h1>
      <p class="muted">{{ number_format($tx->amount, 0, ',', ' ') }} {{ $tx->currency === 'XOF' ? 'F CFA' : $tx->currency }} via {{ $tx->gateway->label }}.@if($title) Le module « {{ $title }} » est activé.@endif</p>
    @elseif ($cancelled || $tx->status === 'failed')
      <div style="font-size:48px">⚠️</div>
      <h1>Paiement non abouti</h1>
      <p class="muted">Aucun montant n'a été prélevé. Vous pouvez réessayer.</p>
    @else
      <div style="font-size:48px">⏳</div>
      <h1>Paiement en cours de confirmation</h1>
      <p class="muted">Le prestataire n'a pas encore confirmé. Cette page se met à jour automatiquement.</p>
      <script>setTimeout(() => location.reload(), 5000)</script>
    @endif
    @if ($back)<a class="btn alt" style="text-align:center;text-decoration:none" href="{{ $back }}">Retour</a>@endif
  </div>
@endsection
