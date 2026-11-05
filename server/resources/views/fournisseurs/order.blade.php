@extends('rdv.layout')
@section('title', $o->number)
@section('content')
  @include('fournisseurs.nav')
  @php($a = $acks->first())
  <div class="card">
    <div class="row" style="border:0;align-items:center"><h2 style="margin:0;font-size:21px">Bon de commande {{ $o->number }}</h2><span class="pill {{ ! $a ? 'p-wait' : ($a->status === 'accepted' ? 'p-ok' : 'p-bad') }}">{{ ! $a ? 'À confirmer' : ($a->status === 'accepted' ? 'Confirmée' : 'Refusée') }}</span></div>
    <div class="muted">du {{ $o->date->format('d/m/Y') }}{{ $o->reference ? ' · réf. '.$o->reference : '' }}</div>
    <table style="margin-top:10px">
      <tr><th>Article</th><th class="r">Qté</th><th class="r">P.U. HT</th><th class="r">Total HT</th></tr>
      @foreach($o->lines as $l)
        <tr><td>{{ $l->description }}</td><td class="r">{{ rtrim(rtrim(number_format($l->quantity, 2, ',', ' '), '0'), ',') }} {{ $l->unit }}</td><td class="r">{{ number_format($l->unit_price, 0, ',', ' ') }}</td><td class="r">{{ number_format($l->subtotal, 0, ',', ' ') }}</td></tr>
      @endforeach
      <tr><td colspan="3" class="r">Total TTC</td><td class="r"><b>{{ number_format($o->total, 0, ',', ' ') }} {{ $o->currency }}</b></td></tr>
    </table>
    @if($o->notes)<p class="muted" style="white-space:pre-line">{{ $o->notes }}</p>@endif
  </div>
  @foreach($acks as $x)
    <div class="card"><b>{{ $x->status === 'accepted' ? 'Confirmée' : 'Refusée' }}</b> le {{ $x->created_at->format('d/m/Y H:i') }}{{ $x->by_name ? ' par '.$x->by_name : '' }}{{ $x->delivery_date ? ' · livraison prévue le '.$x->delivery_date->format('d/m/Y') : '' }}@if($x->comment)<p style="margin:6px 0 0;white-space:pre-line">{{ $x->comment }}</p>@endif</div>
  @endforeach
  @if($o->receipt_status !== 'received')
    <form class="card" method="post" action="{{ url('/fournisseur/'.$token.'/commandes/'.$o->id) }}">
      @csrf
      <h2>{{ $a ? 'Mettre à jour votre réponse' : 'Votre réponse' }}</h2>
      <div class="two">
        <div><label for="delivery_date">Date de livraison prévue</label><input id="delivery_date" type="date" name="delivery_date" value="{{ old('delivery_date', $a?->delivery_date?->format('Y-m-d')) }}"></div>
        <div><label for="name">Votre nom</label><input id="name" name="name" value="{{ old('name') }}"></div>
      </div>
      <label for="comment">Commentaire (obligatoire en cas de refus)</label>
      <textarea id="comment" name="comment" rows="3">{{ old('comment') }}</textarea>
      <div style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap">
        <button class="btn" style="flex:1;background:var(--ok)" name="decision" value="accept">Je confirme la commande</button>
        <button class="btn ghost" style="flex:1" name="decision" value="decline">Je ne peux pas livrer</button>
      </div>
    </form>
  @endif
@endsection
