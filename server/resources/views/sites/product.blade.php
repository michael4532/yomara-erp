@extends('sites.layout')
@php extract(\App\Modules\Sites\SiteView::vars(get_defined_vars())); $p = $product; $first = collect($p['variants'])->firstWhere('id', $variant) ?? collect($p['variants'])->first(fn ($v) => $v['stock'] === null || $v['stock'] > 0) ?? ($p['variants'][0] ?? null); @endphp
@section('title', $p['name'].' — '.$name)
@push('head')
<link rel="canonical" href="{{ $canonical }}">
<meta property="og:title" content="{{ $p['name'] }}">@if($photos)<meta property="og:image" content="{{ $photos[0]['url'] }}">@endif
<script type="application/ld+json">{!! json_encode(array_filter(['@context' => 'https://schema.org', '@type' => 'Product', 'name' => $p['name'], 'image' => array_column($photos, 'url') ?: null, 'description' => $p['description'] ?: null, 'category' => $p['category'],
  'aggregateRating' => $rating ? ['@type' => 'AggregateRating', 'ratingValue' => $rating['average'], 'reviewCount' => $rating['count']] : null,
  'offers' => ['@type' => 'AggregateOffer', 'priceCurrency' => $company->currency ?: 'XOF', 'lowPrice' => $p['price'], 'highPrice' => $p['variants'] ? max(array_column($p['variants'], 'price')) : $p['price'], 'offerCount' => max(1, count($p['variants'])),
    'availability' => $p['stock'] === null || $p['stock'] > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock']]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
<style>
.pp{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1fr);gap:48px;align-items:start;padding:34px 0 10px}
@media(max-width:860px){.pp{grid-template-columns:1fr;gap:26px}}
.pgal .main{position:relative;aspect-ratio:1/1;border-radius:calc(var(--r) + 4px);overflow:hidden;background:linear-gradient(135deg,color-mix(in srgb,var(--p) 18%,#fff),color-mix(in srgb,var(--a) 22%,#fff));display:grid;place-items:center;color:var(--p)}
.pgal .main img{width:100%;height:100%;object-fit:cover;transition:opacity .25s}
.pgal .nav{position:absolute;top:50%;transform:translateY(-50%);width:42px;height:42px;border-radius:50%;border:0;background:rgba(255,255,255,.92);cursor:pointer;font-size:1.4rem;color:var(--d);box-shadow:0 6px 18px -6px rgba(0,0,0,.35)}
.pgal .prev{left:12px}.pgal .next{right:12px}
.thumbs{display:flex;gap:10px;margin-top:12px;overflow-x:auto;padding-bottom:4px}
.thumbs button{flex:none;width:76px;height:76px;border-radius:calc(var(--r) * .5 + 4px);overflow:hidden;border:2px solid transparent;padding:0;cursor:pointer;background:#fff;opacity:.7}
.thumbs button.on{border-color:var(--p);opacity:1}.thumbs img{width:100%;height:100%;object-fit:cover}
.badge{position:absolute;top:16px;left:16px;background:var(--a);color:#fff;font-weight:800;font-size:.85rem;padding:6px 12px;border-radius:999px}
.info h1{font-size:clamp(1.7rem,3vw,2.4rem);margin:6px 0 10px}
.info .cat{font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;color:var(--mut);font-weight:700}
.info .rate{display:inline-flex;gap:8px;align-items:center;font-weight:600;text-decoration:none;color:var(--txt)}.info .rate b{color:#E8A317;letter-spacing:1px}
.pbig{font-size:2rem;font-weight:800;color:var(--d);margin:16px 0 4px;display:flex;gap:12px;align-items:baseline;flex-wrap:wrap}.pbig s{font-size:1.1rem;font-weight:500;color:var(--mut)}
.pbig .off{font-size:.85rem;background:#E7F6EC;color:#0B5E31;padding:4px 10px;border-radius:999px}
.attr{margin-top:20px}.attr .lbl{font-weight:700;margin-bottom:8px}.attr .lbl span{font-weight:500;color:var(--mut)}
.vals{display:flex;gap:8px;flex-wrap:wrap}
.vals button{min-width:48px;padding:10px 16px;border-radius:calc(var(--r) * .5 + 4px);border:1.5px solid color-mix(in srgb,var(--d) 18%,transparent);background:#fff;font:inherit;font-weight:600;cursor:pointer;color:var(--txt);position:relative}
.vals button.on{border-color:var(--p);background:color-mix(in srgb,var(--p) 8%,#fff);color:var(--d);box-shadow:0 0 0 1px var(--p)}
.vals button.out{color:var(--mut);text-decoration:line-through;background:#F6F6F8}
.vals button.no{display:none}
.st{display:inline-flex;align-items:center;gap:8px;font-weight:600;font-size:.92rem;margin-top:18px}.st i{width:9px;height:9px;border-radius:50%;background:#1F9D55}.st.low i{background:#E8A317}.st.out i{background:#D92D20}
.buy{display:flex;gap:12px;margin-top:20px;align-items:center;flex-wrap:wrap}
.buy .qty{display:inline-flex;align-items:center;border:1px solid color-mix(in srgb,var(--d) 16%,transparent);border-radius:999px;overflow:hidden;background:#fff}
.buy .qty button{width:44px;height:48px;border:0;background:#fff;font-size:1.2rem;cursor:pointer;color:var(--d)}.buy .qty span{min-width:30px;text-align:center;font-weight:700}
.buy .btn{flex:1;justify-content:center;min-width:200px;padding:15px 20px}.buy .btn[disabled]{opacity:.45;cursor:not-allowed}
.perks{margin-top:24px;display:grid;gap:10px;padding:18px;border-radius:calc(var(--r) + 2px);background:color-mix(in srgb,var(--p) 5%,#fff);border:1px solid var(--line)}
.perks div{display:flex;gap:10px;align-items:flex-start;font-size:.93rem}.perks svg{flex:none;color:var(--p);margin-top:1px}
.sku{font-size:.82rem;color:var(--mut);margin-top:12px}
.desc{max-width:780px;line-height:1.75}.desc h2,.desc h3{margin-top:1.4em}
.rv{border-top:1px solid var(--line);padding:18px 0}.rv .hd{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}.rv .hd b{color:#E8A317}
.rv .rep{margin-top:10px;padding:10px 14px;background:color-mix(in srgb,var(--p) 6%,#fff);border-radius:10px;font-size:.92rem}
.related .prod .im{position:relative}.related .badge{top:10px;left:10px;font-size:.75rem;padding:4px 9px}
.mbar{display:none}
@media(max-width:860px){.mbar{display:flex;position:fixed;left:0;right:0;bottom:0;z-index:55;background:#fff;border-top:1px solid var(--line);padding:10px 16px;gap:12px;align-items:center;box-shadow:0 -10px 30px -18px rgba(0,0,0,.4)}.mbar .btn{flex:1;justify-content:center}.fab{bottom:84px}}
</style>
@endpush
@section('main')
<section style="padding:0"><div class="wrap">
  <div class="crumb" style="padding-top:26px"><a href="{{ $home }}">Accueil</a> › <a href="{{ $shopUrl }}">Boutique</a>@if($p['category']) › {{ $p['category'] }}@endif › {{ $p['name'] }}</div>
  @if($error)<div class="flash bad" style="margin-top:16px">{{ $error }}</div>@endif
  <div class="pp">
    <div class="pgal">
      <div class="main" id="main">@if($photos)<img id="mainImg" src="{{ $photos[0]['url'] }}" alt="{{ $p['name'] }}">@else{!! $ic('bag', 72) !!}@endif
        @if($p['badge'])<span class="badge">{{ $p['badge'] }}</span>@endif
        @if(count($photos) > 1)<button class="nav prev" type="button" onclick="go(-1)" aria-label="Photo précédente">‹</button><button class="nav next" type="button" onclick="go(1)" aria-label="Photo suivante">›</button>@endif</div>
      @if(count($photos) > 1)<div class="thumbs" id="thumbs">@foreach($photos as $i => $ph)<button type="button" class="{{ $i ? '' : 'on' }}" onclick="show({{ $i }})" aria-label="Photo {{ $i + 1 }}"><img src="{{ $ph['url'] }}" alt="" loading="lazy"></button>@endforeach</div>@endif
    </div>
    <div class="info">
      @if($p['category'])<div class="cat">{{ $p['category'] }}</div>@endif
      <h1>{{ $p['name'] }}</h1>
      @if($rating)<a class="rate" href="#avis"><b>{{ str_repeat('★', (int) round($rating['average'])) }}{{ str_repeat('☆', 5 - (int) round($rating['average'])) }}</b> {{ number_format($rating['average'], 1, ',', '') }}/5 · {{ $rating['count'] }} avis</a>@endif
      <div class="pbig"><span id="price">{{ number_format($first['price'] ?? $p['price'], 0, ',', ' ') }} {{ $currency }}</span>@if($p['compare_at'])<s>{{ number_format($p['compare_at'], 0, ',', ' ') }} {{ $currency }}</s><span class="off" id="off"></span>@endif</div>
      @if($p['description'] && ! $description)<p class="muted">{{ $p['description'] }}</p>@endif
      @foreach($p['attributes'] as $ai => $a)
        <div class="attr"><div class="lbl">{{ $a['name'] }} : <span id="sel{{ $ai }}"></span></div>
          <div class="vals" data-a="{{ $a['name'] }}">@foreach($a['values'] as $val)<button type="button" data-v="{{ $val }}" onclick="pick({{ $ai }}, this.dataset.v)">{{ $val }}</button>@endforeach</div></div>
      @endforeach
      <div class="st" id="st"><i></i><span></span></div>
      <div class="buy"><span class="qty"><button type="button" onclick="qn(-1)" aria-label="Moins">−</button><span id="qn">1</span><button type="button" onclick="qn(1)" aria-label="Plus">+</button></span>
        <button class="btn" type="button" id="addBtn" onclick="addSel()">{!! $ic('bag', 18) !!} Ajouter au panier</button></div>
      <div class="sku" id="sku"></div>
      <div class="perks">
        @if($shop['pickup'])<div>{!! $ic('map', 18) !!}<span><b>Retrait gratuit</b> — {{ $shop['pickup_address'] ?: $address }}</span></div>@endif
        @if($shop['zones'])<div>{!! $ic('truck', 18) !!}<span><b>Livraison</b> dès {{ number_format(collect($shop['zones'])->min('fee'), 0, ',', ' ') }} {{ $currency }}@if($shop['free_from']) · offerte dès {{ number_format($shop['free_from'], 0, ',', ' ') }} {{ $currency }}@endif</span></div>@endif
        <div>{!! $ic('shield', 18) !!}<span>{{ collect([$online ? 'Paiement sécurisé Mobile Money ou carte' : null, $shop['payment_cod'] ? 'paiement à la livraison' : null, $shop['payment_momo'] ? 'transfert Mobile Money' : null])->filter()->implode(' · ') ?: 'Paiement à la commande' }}</span></div>
      </div>
    </div>
  </div>
</div></section>
@if($description)
<section><div class="wrap"><h2>Description</h2><div class="desc">{!! $description !!}</div></div></section>
@endif
@if($reviews)
<section id="avis" class="alt-bg"><div class="wrap" style="max-width:860px"><h2>Avis clients</h2><p class="lead"><span style="color:#E8A317">{{ str_repeat('★', (int) round($rating['average'])) }}</span> {{ number_format($rating['average'], 1, ',', '') }}/5 sur {{ $rating['count'] }} avis vérifiés</p>
  @foreach($reviews as $rv)<div class="rv"><div class="hd"><span><b>{{ str_repeat('★', $rv['rating']) }}</b> <strong>{{ $rv['name'] }}</strong></span><span class="muted" style="font-size:.85rem">{{ $rv['date'] ? \Carbon\Carbon::parse($rv['date'])->translatedFormat('j F Y') : '' }}</span></div>
    @if($rv['comment'])<p style="margin:8px 0 0">{{ $rv['comment'] }}</p>@endif @if($rv['reply'])<div class="rep"><b>Réponse de {{ $name }} :</b> {{ $rv['reply'] }}</div>@endif</div>@endforeach
</div></section>
@endif
@if($related)
<section class="related"><div class="wrap"><h2>Vous aimerez aussi</h2>
  <div class="grid g4" style="margin-top:24px">@foreach($related as $o)
    <a class="card prod" href="{{ $o['url'] }}" style="text-decoration:none;color:inherit"><div class="im">@if($o['image'])<img src="{{ $o['image'] }}" alt="" loading="lazy">@else{!! $ic('bag', 40) !!}@endif @if($o['badge'])<span class="badge">{{ $o['badge'] }}</span>@endif</div>
      <div class="bd" style="gap:4px">@if($o['category'])<span class="muted" style="font-size:.78rem;text-transform:uppercase;letter-spacing:.06em">{{ $o['category'] }}</span>@endif<h3 style="margin:0;font-size:1.05rem">{{ $o['name'] }}</h3>
        <span class="price">@if($o['price_from'])<small class="muted" style="font-size:.78rem;font-weight:600">À partir de</small> @endif{{ number_format($o['price'], 0, ',', ' ') }} {{ $currency }}@if($o['compare_at']) <s class="muted" style="font-weight:500;font-size:.85rem;white-space:nowrap">{{ number_format($o['compare_at'], 0, ',', ' ') }}</s>@endif</span></div></a>
  @endforeach</div></div></section>
@endif
<div class="mbar"><div><div class="muted" style="font-size:.78rem" id="mLbl">{{ $p['name'] }}</div><b id="mPrice">{{ number_format($first['price'] ?? $p['price'], 0, ',', ' ') }} {{ $currency }}</b></div><button class="btn" type="button" onclick="addSel()" id="mAdd">{!! $ic('bag', 18) !!} Ajouter</button></div>
@include('sites._cart')
@endsection
@push('scripts')
<script>
var PR={!! json_encode(['id' => $p['id'], 'tpl' => $p['is_template'], 'price' => $p['price'], 'stock' => $p['stock'], 'cmp' => $p['compare_at'], 'sku' => null], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!},
  V={!! json_encode($p['variants'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!},A={!! json_encode($p['attributes'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!},PH={!! json_encode($photos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!},
  FIRST={!! json_encode($first['values'] ?? (object) [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!},sel={},cur=null,qty=1,pi=0;
function show(i){if(!PH.length)return;pi=(i+PH.length)%PH.length;var m=document.getElementById('mainImg');if(m)m.src=PH[pi].url;document.querySelectorAll('#thumbs button').forEach(function(b,j){b.classList.toggle('on',j===pi)})}
function go(d){show(pi+d)}
function avail(v){return v.stock===null||v.stock>0}
function match(s){return V.find(function(v){return A.every(function(a){return v.values[a.name]===s[a.name]})})}
function pick(ai,val){sel[A[ai].name]=val;if(!match(sel)){var alt=V.filter(function(v){return v.values[A[ai].name]===val});alt.sort(function(x,y){return (avail(y)?1:0)-(avail(x)?1:0)});if(alt[0])sel=Object.assign({},alt[0].values)}
  var tagged=PH.findIndex(function(p){return p.tag&&p.tag===val});if(tagged>=0)show(tagged);else{var v=match(sel);if(v&&v.image){var k=PH.findIndex(function(p){return p.url===v.image});if(k>=0)show(k)}}render()}
function fmt(n){return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g,' ')+CUR}
function render(){
  if(PR.tpl){cur=match(sel)||null;A.forEach(function(a,ai){document.getElementById('sel'+ai).textContent=sel[a.name]||'';
    document.querySelectorAll('.vals[data-a="'+a.name.replace(/"/g,'\\"')+'"] button').forEach(function(b){var t=Object.assign({},sel);t[a.name]=b.dataset.v;
      var any=V.filter(function(v){return v.values[a.name]===b.dataset.v}),exact=match(t);b.classList.toggle('no',!any.length);b.classList.toggle('on',sel[a.name]===b.dataset.v);
      b.classList.toggle('out',!!any.length&&!(exact?avail(exact):any.some(avail)))})})}
  var price=cur?cur.price:PR.price,stock=cur?cur.stock:PR.stock,ok=PR.tpl?!!cur&&avail(cur):(stock===null||stock>0);
  document.getElementById('price').textContent=fmt(price);document.getElementById('mPrice').textContent=fmt(price);
  var off=document.getElementById('off');if(off){off.textContent=PR.cmp>price?'−'+Math.round(100-price*100/PR.cmp)+' %':'';off.style.display=PR.cmp>price?'':'none'}
  var st=document.getElementById('st');st.className='st'+(PR.tpl&&!cur?' out':stock!==null&&stock<=0?' out':stock!==null&&stock<=5?' low':'');
  st.querySelector('span').textContent=PR.tpl&&!cur?'Combinaison indisponible':stock===null?'Disponible':stock<=0?'Rupture de stock':stock<=5?'Plus que '+Math.floor(stock)+' en stock':'En stock';
  document.getElementById('sku').textContent=cur&&cur.sku?'Réf. '+cur.sku:'';document.getElementById('mLbl').textContent=cur?cur.label:document.getElementById('mLbl').textContent;
  ['addBtn','mAdd'].forEach(function(id){document.getElementById(id).disabled=!ok});if(stock!==null&&qty>stock&&stock>0){qty=Math.floor(stock)}document.getElementById('qn').textContent=qty}
function qn(d){var s=cur?cur.stock:PR.stock;qty=Math.max(1,Math.min(99,qty+d));if(s!==null&&qty>s)qty=Math.max(1,Math.floor(s));document.getElementById('qn').textContent=qty}
function addSel(){var id=PR.tpl?(cur&&cur.id):PR.id;if(!id)return;add(id,qty,true);qty=1;document.getElementById('qn').textContent=1}
sel=Object.assign({},FIRST);render();
</script>
@endpush
