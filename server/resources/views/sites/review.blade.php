@extends('sites.layout')
@php
  extract(\App\Modules\Sites\SiteView::vars(get_defined_vars()));
  $cur = $done ? (int) $done->rating : ($note >= 1 && $note <= 5 ? $note : 0);
  $prodNotes = (array) ($done->products ?? []);
@endphp
@section('title', 'Votre avis — '.$name)
@push('head')
<style>
.stars{display:inline-flex;flex-direction:row-reverse;gap:4px}.stars input{display:none}
.stars label{font-size:2.4rem;line-height:1;color:color-mix(in srgb,var(--d) 18%,transparent);cursor:pointer;transition:color .15s,transform .15s;margin:0}
.stars label:hover,.stars label:hover~label,.stars input:checked~label{color:#F5B301}.stars label:hover{transform:scale(1.12)}
.stars.sm label{font-size:1.5rem}
.pr{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--line);flex-wrap:wrap}
</style>
@endpush
@section('main')
<section class="page-head"><div class="wrap"><div class="crumb"><a href="{{ $home }}">Accueil</a> › Avis</div><h1>Votre avis</h1><p>Commande {{ $o->number }} du {{ $o->created_at->locale('fr')->isoFormat('D MMMM YYYY') }}</p></div></section>
<section style="padding-top:30px"><div class="wrap" style="max-width:720px">
  @if($saved)<div class="flash ok">Merci {{ $o->name }} ! Votre avis {{ ($done?->status ?? 'published') === 'published' ? 'est publié' : 'sera publié après vérification' }}.</div>@endif
  @if($error)<div class="flash bad">{{ $error }}</div>@endif
  @if($o->status !== 'delivered')<div class="card muted">Vous pourrez donner votre avis une fois la commande livrée.</div>
  @else
  <form class="card f" method="post" action="{{ $base }}/avis/{{ $o->token }}">
    <input class="hp" name="website" tabindex="-1" autocomplete="off">
    <div><label>Votre note globale</label><div class="stars">@for($k = 5; $k >= 1; $k--)<input type="radio" id="r{{ $k }}" name="rating" value="{{ $k }}" @checked($cur === $k)><label for="r{{ $k }}" title="{{ $k }}/5">★</label>@endfor</div></div>
    @if(count($o->items) > 0)<div><label>Et vos articles ?</label>
      @foreach($o->items as $it)<div class="pr"><span>{{ $it['name'] }}</span><span class="stars sm">@for($k = 5; $k >= 1; $k--)<input type="radio" id="p{{ $loop->index }}-{{ $k }}" name="products[{{ $it['product_id'] }}]" value="{{ $k }}" @checked((int) ($prodNotes[$it['product_id']] ?? 0) === $k)><label for="p{{ $loop->index }}-{{ $k }}">★</label>@endfor</span></div>@endforeach
    </div>@endif
    <div><label for="rv-c">Votre commentaire (facultatif)</label><textarea id="rv-c" name="comment" rows="4" maxlength="2000" placeholder="Qualité des produits, livraison, accueil…">{{ $done->comment ?? '' }}</textarea></div>
    <div><label for="rv-n">Nom affiché</label><input id="rv-n" name="name" maxlength="160" value="{{ $done->name ?? $o->name }}"></div>
    <button class="btn" type="submit">{{ $done ? 'Modifier mon avis' : 'Publier mon avis' }}</button>
    <div class="muted" style="font-size:.85rem">Avis lié à votre commande : il apparaît avec la mention « Achat vérifié ».</div>
  </form>
  @if($done && $done->reply)<div class="card" style="margin-top:14px"><b>Réponse de {{ $name }}</b><p style="margin:6px 0 0">{{ $done->reply }}</p></div>@endif
  @endif
</div></section>
@endsection
