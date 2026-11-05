@extends('sites.layout')
@php extract(\App\Modules\Sites\SiteView::vars(get_defined_vars())); @endphp
@section('title', 'Boutique — '.$name)
@push('head')
<style>
.tools{display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin:26px 0 18px}
.tools input{flex:1;min-width:220px;padding:13px 16px;border:1px solid color-mix(in srgb,var(--d) 16%,transparent);border-radius:calc(var(--r) * .6 + 6px);font:inherit;background:#fff}
.chipz{display:flex;gap:8px;flex-wrap:wrap}.chipz button{border:1px solid color-mix(in srgb,var(--d) 16%,transparent);background:#fff;border-radius:999px;padding:8px 14px;font:inherit;font-weight:600;cursor:pointer;color:var(--txt)}
.chipz button.on{background:var(--d);color:#fff;border-color:var(--d)}
.pc .stock{font-size:.8rem;font-weight:700;padding:3px 9px;border-radius:999px;align-self:flex-start}.pc .low{background:#FFF4D6;color:#8A5A00}.pc .out{background:#FCEAEA;color:#B42318}
.addrow{display:flex;gap:8px;align-items:center;margin-top:auto;padding-top:10px}
.badge{position:absolute;top:12px;left:12px;background:var(--a);color:#fff;font-weight:800;font-size:.78rem;padding:5px 10px;border-radius:999px;letter-spacing:.02em}
.vchips{display:flex;gap:6px;flex-wrap:wrap}.vchips span{font-size:.78rem;font-weight:600;padding:3px 9px;border-radius:999px;background:color-mix(in srgb,var(--p) 9%,#fff);color:var(--d)}
</style>
@endpush
@section('main')
<section class="page-head"><div class="wrap"><div class="crumb"><a href="{{ $home }}">Accueil</a> › Boutique</div><h1>Notre boutique</h1>@if($ratings['count'])<div style="margin-top:8px;font-weight:700"><span style="color:var(--a)">{{ str_repeat('★', (int) round($ratings['average'])) }}</span> {{ number_format($ratings['average'], 1, ',', '') }}/5 · {{ $ratings['count'] }} avis vérifiés</div>@endif<p>{{ $shop['note'] ?: 'Commandez en ligne : '.collect([$shop['pickup'] ? 'retrait en boutique' : null, $shop['zones'] ? 'livraison' : null])->filter()->implode(' ou ').'.' }}</p></div></section>
<section style="padding-top:10px"><div class="wrap">
  @if($error)<div class="flash bad" style="margin-top:20px">{{ $error }}</div>@endif
  <div class="tools"><input id="q" type="search" placeholder="Rechercher un produit…" oninput="filt()">
    @if(count($categories) > 1)<div class="chipz" id="cats"><button class="on" data-c="" onclick="cat(this)">Tout</button>@foreach($categories as $ct)<button data-c="{{ $ct }}" onclick="cat(this)">{{ $ct }}</button>@endforeach</div>@endif</div>
  <div class="grid g4" id="grid">
    @forelse($catalog as $p)
      <div class="card prod pc" data-n="{{ mb_strtolower($p['name'].' '.$p['category'].' '.collect($p['attributes'])->pluck('values')->flatten()->implode(' ')) }}" data-c="{{ $p['category'] }}">
        <a class="im" href="{{ $p['url'] }}" style="position:relative">@if($p['image'])<img src="{{ $p['image'] }}" alt="{{ $p['name'] }}" loading="lazy">@else{!! $ic('bag', 40) !!}@endif @if($p['badge'])<span class="badge">{{ $p['badge'] }}</span>@endif</a>
        <div class="bd">@if($p['category'])<span class="muted" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.06em">{{ $p['category'] }}</span>@endif
          <h3 style="margin:0"><a href="{{ $p['url'] }}" style="color:inherit;text-decoration:none;margin:0;display:inline">{{ $p['name'] }}</a></h3>@if($p['description'])<span class="muted" style="font-size:.9rem">{{ $p['description'] }}</span>@endif
          @if(isset($ratings['products'][$p['id']]))<span style="font-size:.85rem"><span style="color:#E8A317">{{ str_repeat('★', (int) round($ratings['products'][$p['id']]['average'])) }}</span> <span class="muted">{{ number_format($ratings['products'][$p['id']]['average'], 1, ',', '') }} ({{ $ratings['products'][$p['id']]['count'] }})</span></span>@endif
          @if($p['is_template'])<span class="vchips">@foreach($p['attributes'] as $a)<span>{{ count($a['values']) }} {{ mb_strtolower($a['name']) }}{{ count($a['values']) > 1 && ! str_ends_with(mb_strtolower($a['name']), 's') ? 's' : '' }}</span>@endforeach</span>@endif
          <span class="price">@if($p['price_from'])<small style="font-weight:600;font-size:.8rem" class="muted">À partir de</small> @endif{{ number_format($p['price'], 0, ',', ' ') }} {{ $currency }}@if($p['compare_at']) <s class="muted" style="font-weight:500;font-size:.9rem;white-space:nowrap">{{ number_format($p['compare_at'], 0, ',', ' ') }} {{ $currency }}</s>@endif</span>
          @if($p['stock'] !== null && $p['stock'] <= 0)<span class="stock out">Rupture de stock</span>@elseif($p['stock'] !== null && $p['stock'] <= 5)<span class="stock low">Plus que {{ (int) $p['stock'] }}</span>@endif
          <div class="addrow">@if($p['is_template'])<a class="btn add" href="{{ $p['url'] }}" style="color:#fff;margin-top:0">Choisir {!! $ic('arrow', 18) !!}</a>@elseif($p['stock'] === null || $p['stock'] > 0)<button class="btn add" type="button" onclick="add('{{ $p['id'] }}')">{!! $ic('bag', 18) !!} Ajouter</button>@endif</div>
        </div></div>
    @empty
      <div class="empty" style="grid-column:1/-1">La boutique se remplit bientôt.</div>
    @endforelse
  </div>
  <div class="empty" id="none" style="display:none">Aucun produit ne correspond à votre recherche.</div>
</div></section>
@include('sites._cart')
@endsection
@push('scripts')
<script>
var curCat='';function cat(b){document.querySelectorAll('#cats button').forEach(function(x){x.classList.remove('on')});b.classList.add('on');curCat=b.dataset.c;filt()}
function filt(){var q=document.getElementById('q').value.toLowerCase().trim(),n=0;document.querySelectorAll('#grid .pc').forEach(function(e){var ok=(!q||e.dataset.n.indexOf(q)>=0)&&(!curCat||e.dataset.c===curCat);e.style.display=ok?'':'none';if(ok)n++});document.getElementById('none').style.display=n?'none':'block'}
</script>
@endpush
