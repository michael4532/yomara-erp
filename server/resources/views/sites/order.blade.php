@extends('sites.layout')
@php
  extract(\App\Modules\Sites\SiteView::vars(get_defined_vars()));
  $steps = ['new' => 'Reçue', 'confirmed' => 'Confirmée', 'preparing' => 'En préparation', 'shipped' => $o->delivery_mode === 'Retrait en boutique' ? 'Prête' : 'En livraison', 'delivered' => $o->delivery_mode === 'Retrait en boutique' ? 'Retirée' : 'Livrée'];
  $keys = array_keys($steps);
  $cur = array_search($o->status, $keys, true);
@endphp
@section('title', 'Commande '.$o->number.' — '.$name)
@push('head')
<style>
.track{display:flex;gap:0;margin:26px 0 8px}.track div{flex:1;text-align:center;position:relative;font-size:.86rem;font-weight:600;color:var(--mut)}
.track div:before{content:"";display:block;width:28px;height:28px;border-radius:50%;margin:0 auto 8px;background:#fff;border:3px solid color-mix(in srgb,var(--d) 15%,transparent);position:relative;z-index:1}
.track div:after{content:"";position:absolute;top:13px;left:-50%;width:100%;height:3px;background:color-mix(in srgb,var(--d) 12%,transparent)}.track div:first-child:after{display:none}
.track .done{color:var(--d)}.track .done:before{background:var(--p);border-color:var(--p)}.track .done:after{background:var(--p)}
.track .now:before{box-shadow:0 0 0 6px color-mix(in srgb,var(--p) 20%,transparent)}
.lines .row{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid var(--line)}
</style>
@endpush
@section('main')
<section class="page-head"><div class="wrap"><div class="crumb"><a href="{{ $home }}">Accueil</a> › Commande</div><h1>Commande {{ $o->number }}</h1><p>Passée le {{ $o->created_at->locale('fr')->isoFormat('D MMMM YYYY [à] HH:mm') }}</p></div></section>
<section style="padding-top:30px"><div class="wrap" style="max-width:820px">
  @if($isNew)<div class="flash ok">Merci {{ $o->name }} ! Votre commande est enregistrée{{ $o->email ? ' — un récapitulatif vous est envoyé par e-mail' : '' }}. Gardez cette page pour suivre son avancement.</div>@endif
  @if($o->status === 'cancelled')<div class="flash bad" style="margin-top:14px">Cette commande a été annulée.</div>
  @else<div class="card"><div class="track">@foreach($steps as $k => $l)<div class="{{ $cur !== false && array_search($k, $keys, true) <= $cur ? 'done' : '' }} {{ $k === $o->status ? 'now' : '' }}">{{ $l }}</div>@endforeach</div></div>@endif
  @if($payUrl)<div class="card" style="margin-top:16px;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap"><div><b>Paiement en attente</b><div class="muted">Réglez {{ number_format($o->total, 0, ',', ' ') }} {{ $currency }} par Mobile Money ou carte.</div></div><a class="btn" href="{{ $payUrl }}">Payer maintenant</a></div>@endif
  @if($o->payment_mode === 'momo' && $o->payment_status !== 'paid' && $shop['momo_instructions'])<div class="card" style="margin-top:16px"><b>Transfert Mobile Money</b><div class="muted">{{ $shop['momo_instructions'] }} — indiquez la référence {{ $o->number }}.</div></div>@endif
  <div class="card lines" style="margin-top:16px">
    @foreach($o->items as $i)<div class="row"><span>{{ $i['quantity'] }} × {{ $i['name'] }}</span><b>{{ number_format($i['total'], 0, ',', ' ') }} {{ $currency }}</b></div>@endforeach
    @if($o->discount > 0)<div class="row" style="color:#0B5E31"><span>Code promo {{ $o->coupon_code }}</span><b>−{{ number_format($o->discount, 0, ',', ' ') }} {{ $currency }}</b></div>@elseif($o->coupon_code)<div class="row" style="color:#0B5E31"><span>Code promo {{ $o->coupon_code }}</span><b>livraison offerte</b></div>@endif
    <div class="row"><span>{{ $o->delivery_mode }}</span><b>{{ $o->delivery_fee > 0 ? number_format($o->delivery_fee, 0, ',', ' ').' '.$currency : 'gratuit' }}</b></div>
    <div class="row" style="border:0;font-size:1.15rem"><span>Total</span><b>{{ number_format($o->total, 0, ',', ' ') }} {{ $currency }}</b></div>
    <div class="muted" style="margin-top:6px">{{ $modes[$o->payment_mode] ?? '' }} · {{ $o->payment_status === 'paid' ? 'payée' : 'à régler' }}@if($o->address) · {{ $o->address }}@endif</div>
  </div>
  @if($o->status === 'delivered' && ($shop['reviews_enabled'] ?? false))<div class="card" style="margin-top:16px;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap"><div><b>Votre avis compte</b><div class="muted">Notez votre commande et vos articles en 30 secondes.</div></div><a class="btn" href="{{ $base }}/avis/{{ $o->token }}">Donner mon avis</a></div>@endif
  <div style="margin-top:20px;display:flex;gap:10px;flex-wrap:wrap">@if($whatsapp)<a class="btn" href="https://wa.me/{{ $whatsapp }}?text={{ rawurlencode('Bonjour, au sujet de ma commande '.$o->number) }}">Une question ? WhatsApp</a>@endif @if($shopOn)<a class="btn alt" style="color:var(--p)" href="{{ $shopUrl }}">Retour à la boutique</a>@endif</div>
</div></section>
@endsection
@push('scripts')
@if($isNew)<script>try{localStorage.removeItem('cart-{{ $site->slug }}')}catch(e){}</script>@endif
@endpush
