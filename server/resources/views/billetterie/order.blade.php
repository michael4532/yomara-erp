@extends('rdv.layout')
@section('title', 'Commande '.$order->number)
@section('footer', 'Billetterie en ligne')
@section('content')
<style>.tix{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px}.ti{background:#fff;border:1px solid var(--line);border-radius:16px;padding:16px;text-align:center}.ti svg{width:180px;height:180px}.ti .code{font-family:monospace;font-size:18px;font-weight:800;letter-spacing:2px}</style>
  <div class="card">
    <h2 style="margin-bottom:4px">{{ $event->name }}</h2>
    <div class="muted">Commande {{ $order->number }} · {{ $order->buyer_name }} · {{ $event->start_date->format('d/m/Y') }}{{ $event->venue ? ' · '.$event->venue : '' }}</div>
    <div class="row" style="margin-top:10px"><span>Total</span><b>{{ $order->total > 0 ? number_format($order->total, 0, ',', ' ').' '.$cur : 'Gratuit' }}</b></div>
    <div class="row"><span>État</span><b>{{ ['pending' => 'En attente de paiement', 'paid' => 'Payée', 'cancelled' => 'Annulée'][$order->status] ?? $order->status }}</b></div>
  </div>
  @if($order->status === 'pending')
    <div class="card"><p>Vos billets apparaîtront ici dès le paiement confirmé.</p>@if($payUrl)<a class="btn" href="{{ $payUrl }}">Payer maintenant</a>@endif</div>
  @elseif($order->status === 'cancelled')
    <div class="flash bad">Cette commande est annulée : les billets ne sont plus valables.</div>
  @else
    <h2>Vos billets ({{ $tickets->count() }})</h2>
    <p class="muted">Présentez chaque QR code à l'entrée, sur votre téléphone ou imprimé.</p>
    <div class="tix">
      @foreach($tickets as $t)
        <a class="ti" href="{{ $t->publicUrl() }}" style="color:inherit">
          {!! \App\Modules\Billetterie\TicketService::qrSvg($t->token, 5) !!}
          <div class="code">{{ $t->code }}</div>
          <div><b>{{ $types[$t->type_id] ?? 'Billet' }}</b></div>
          <div class="muted">{{ $t->holder_name }}</div>
          @if($t->status !== 'valid')<div class="flash bad" style="margin:8px 0 0">Annulé</div>@endif
        </a>
      @endforeach
    </div>
  @endif
@endsection
