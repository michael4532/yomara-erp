@extends('pay.layout')
@section('title', 'Paiement KKiaPay')
@section('content')
  <div class="brand"><img class="logo" src="/brand/mark-512.png" alt="" style="padding:0;background:none"><div><strong>{{ config('erp.name') }}</strong><div class="muted">Paiement sécurisé KKiaPay</div></div></div>
  <div class="card" style="text-align:center">
    <h1>{{ $meta['description'] ?? 'Paiement' }}</h1>
    <div class="amount">{{ number_format($tx->amount, 0, ',', ' ') }} {{ in_array($tx->currency, ['XOF', 'XAF']) ? 'F CFA' : $tx->currency }}</div>
    <p class="muted">Mobile Money (MTN, Moov, Orange, Wave…) ou carte bancaire.</p>
    <button class="btn" style="text-align:center" onclick="pay()">Payer maintenant</button>
    <a class="btn alt" style="text-align:center;text-decoration:none" href="{{ url('/payments/'.$tx->id.'/return?cancel=1') }}">Annuler</a>
  </div>
  <script src="https://cdn.kkiapay.me/k.js"></script>
  <script>
    const back = @json(url('/payments/'.$tx->id.'/return'));
    function pay() {
      openKkiapayWidget({
        amount: {{ (int) round($tx->amount) }},
        key: @json($key),
        sandbox: {{ $sandbox ? 'true' : 'false' }},
        data: @json((string) $tx->id),
        partnerId: @json((string) $tx->id),
        name: @json($meta['name'] ?? ''),
        email: @json($meta['email'] ?? ''),
        phone: @json(preg_replace('/\D+/', '', (string) ($meta['phone'] ?? ''))),
        position: 'center',
        theme: '#1F6FEB'
      });
    }
    addSuccessListener(function (r) {
      location.href = back + '?transaction_id=' + encodeURIComponent(r.transactionId || r.transaction_id || '');
    });
    window.addEventListener('load', pay);
  </script>
@endsection
