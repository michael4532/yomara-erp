@php extract(\App\Modules\Sites\SiteView::vars(get_defined_vars())); @endphp<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', $seo['title'] ?? ($name.' — '.($c['hero_title'] ?? '')))</title>
<meta name="description" content="@yield('description', $seo['description'] ?? \Illuminate\Support\Str::limit($c['hero_subtitle'] ?? '', 160))">
@if($preview)<meta name="robots" content="noindex">@endif
<meta property="og:title" content="@yield('title', $name)"><meta property="og:description" content="{{ \Illuminate\Support\Str::limit($c['hero_subtitle'] ?? '', 160) }}">
@hasSection('og_image')<meta property="og:image" content="@yield('og_image')">@elseif($hero_image)<meta property="og:image" content="{{ $hero_image }}">@endif
@if($logo)<link rel="icon" href="{{ $logo }}">@endif
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?{{ $fonts }}&display=swap" rel="stylesheet">
<style>
:root{--p:{{ $t['primary'] }};--d:{{ $t['dark'] }};--a:{{ $t['accent'] }};--bg:{{ $t['background'] }};--r:{{ (int) ($t['radius'] ?? 12) }}px;
  --hf:'{{ $t['heading_font'] ?? 'Poppins' }}',system-ui,sans-serif;--bf:'{{ $t['body_font'] ?? 'Inter' }}',system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;
  --txt:#1d2330;--mut:#5d6677;--line:rgba(17,24,39,.09);--card:#fff}
*{box-sizing:border-box}html{scroll-behavior:smooth}
body{margin:0;font-family:var(--bf);color:var(--txt);background:var(--bg);font-size:16px;line-height:1.6;-webkit-font-smoothing:antialiased}
a{color:inherit}img{max-width:100%;display:block}
h1,h2,h3{font-family:var(--hf);line-height:1.15;margin:0 0 .5em;color:var(--d)}
h1{font-size:clamp(2.1rem,5.2vw,3.7rem);letter-spacing:-.02em}h2{font-size:clamp(1.6rem,3.4vw,2.4rem);letter-spacing:-.01em}h3{font-size:1.15rem}
.wrap{max-width:1160px;margin:0 auto;padding:0 20px}
section{padding:84px 0;scroll-margin-top:70px}
.kicker{display:inline-flex;align-items:center;gap:8px;font-weight:700;font-size:.8rem;letter-spacing:.14em;text-transform:uppercase;color:var(--p);margin-bottom:14px}
.kicker:before{content:"";width:26px;height:2px;background:var(--a)}
.lead{font-size:1.12rem;color:var(--mut);max-width:640px}
.btn{display:inline-flex;align-items:center;gap:10px;padding:14px 24px;border-radius:calc(var(--r) + 4px);background:var(--p);color:#fff;font-weight:700;text-decoration:none;border:0;cursor:pointer;font:inherit;font-weight:700;transition:transform .2s,box-shadow .2s,filter .2s}
.btn:hover{transform:translateY(-2px);box-shadow:0 12px 28px -10px color-mix(in srgb,var(--p) 70%,transparent);filter:brightness(1.05)}
.btn.alt{background:transparent;color:currentColor;border:1.5px solid currentColor}
.btn.light{background:#fff;color:var(--d)}
.center{text-align:center}.center .lead{margin-left:auto;margin-right:auto}.center .kicker:before{display:none}
.grid{display:grid;gap:22px}.g2{grid-template-columns:repeat(2,1fr)}.g3{grid-template-columns:repeat(3,1fr)}.g4{grid-template-columns:repeat(4,1fr)}
@media(max-width:900px){.g3,.g4{grid-template-columns:repeat(2,1fr)}.g2{grid-template-columns:1fr}section{padding:64px 0}}
@media(max-width:560px){.g3,.g4{grid-template-columns:1fr}}
.card{background:var(--card);border-radius:var(--r);padding:26px;transition:transform .25s,box-shadow .25s}
.cards-shadow .card{box-shadow:0 1px 2px rgba(16,24,40,.05),0 14px 34px -18px rgba(16,24,40,.28)}
.cards-outline .card{border:1px solid color-mix(in srgb,var(--d) 16%,transparent);background:transparent}
.cards-flat .card{background:color-mix(in srgb,var(--p) 6%,#fff)}
.card:hover{transform:translateY(-4px)}
.ico{width:50px;height:50px;border-radius:calc(var(--r) * .7 + 4px);display:grid;place-items:center;background:color-mix(in srgb,var(--p) 13%,transparent);color:var(--p);margin-bottom:16px}
.muted{color:var(--mut)}
/* barre d'annonce et navigation */
.ann{background:var(--a);color:var(--d);text-align:center;font-weight:600;font-size:.9rem;padding:8px 14px}
header.nav{position:sticky;top:0;z-index:50;transition:background .3s,box-shadow .3s}
.nav-in{display:flex;align-items:center;gap:22px;height:72px}
.brand{display:flex;align-items:center;gap:12px;text-decoration:none;font-family:var(--hf);font-weight:800;font-size:1.15rem;margin-right:auto}
.brand .mk{width:42px;height:42px;border-radius:calc(var(--r) * .6 + 4px);display:grid;place-items:center;background:var(--p);color:#fff;overflow:hidden;flex:none}
.brand .mk img{width:100%;height:100%;object-fit:contain;background:#fff}
.links{display:flex;gap:22px}.links a{text-decoration:none;font-weight:600;font-size:.95rem;opacity:.82;white-space:nowrap}
.brand span:last-child{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:260px}.nav .btn{white-space:nowrap}.links a:hover{opacity:1;color:var(--p)}
.nav-light{background:rgba(255,255,255,.92);backdrop-filter:blur(10px);box-shadow:0 1px 0 var(--line)}
.nav-dark{background:var(--d);color:#fff}.nav-dark .links a:hover{color:var(--a)}
.nav-transparent{background:color-mix(in srgb,var(--bg) 88%,transparent);backdrop-filter:blur(10px);border-bottom:1px solid var(--line)}
.nav .btn{padding:10px 18px}
details.mm{display:none}
@media(max-width:1020px){.links,.nav-in>.btn{display:none}details.mm{display:block}
  details.mm summary{list-style:none;cursor:pointer;padding:8px}details.mm summary::-webkit-details-marker{display:none}
  details.mm[open] .mm-p{position:absolute;left:0;right:0;top:72px;background:#fff;color:var(--txt);padding:12px 20px 20px;box-shadow:0 20px 30px -20px rgba(0,0,0,.3);display:grid;gap:4px}
  .mm-p a{padding:10px 0;text-decoration:none;font-weight:600;border-bottom:1px solid var(--line)}}
/* bannières */
.hero{position:relative;overflow:hidden}
.hero .art{position:absolute;inset:0;pointer-events:none}
.h-gradient{background:linear-gradient(135deg,var(--p),var(--d));color:#fff;padding:96px 0 110px}
.h-gradient h1,.h-split .panel h1{color:#fff}.h-gradient .lead{color:rgba(255,255,255,.86)}
.h-gradient .kicker,.h-image .kicker{color:var(--a)}
.hero-grid{display:grid;grid-template-columns:1.15fr .85fr;gap:48px;align-items:center;position:relative}
@media(max-width:900px){.hero-grid{grid-template-columns:1fr}.hero-grid .deco{display:none}.h-gradient{padding:64px 0 76px}}
.hero-card{border-radius:calc(var(--r) * 1.4);overflow:hidden;box-shadow:0 30px 60px -25px rgba(0,0,0,.55);aspect-ratio:4/3.4;background:rgba(255,255,255,.1)}
.hero-card img{width:100%;height:100%;object-fit:cover}
.badges{display:flex;flex-wrap:wrap;gap:10px;margin-top:30px}
.badge{display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:999px;background:rgba(255,255,255,.14);font-size:.9rem;font-weight:600}
.h-centered{padding:110px 0 90px;text-align:center}
.h-centered .lead{margin:0 auto}.h-centered .acts{justify-content:center}
.h-centered .orn{width:64px;height:2px;background:var(--a);margin:26px auto}
.h-split{padding:0}.h-split .cols{display:grid;grid-template-columns:1fr 1fr;min-height:560px}
.h-split .txt{padding:90px 48px 90px max(20px,calc((100vw - 1160px)/2 + 20px));display:flex;flex-direction:column;justify-content:center}
.h-split .panel{background:var(--d);position:relative;overflow:hidden;color:#fff}
.h-split .panel img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:.9}
@media(max-width:900px){.h-split .cols{grid-template-columns:1fr}.h-split .txt{padding:64px 20px}.h-split .panel{min-height:280px}}
.h-image{color:#fff;padding:150px 0 130px;background:var(--d)}
.h-image .bgimg{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.h-image .shade{position:absolute;inset:0;background:linear-gradient(90deg,color-mix(in srgb,var(--d) 92%,transparent),color-mix(in srgb,var(--d) 40%,transparent))}
.h-image h1{color:#fff}.h-image .lead{color:rgba(255,255,255,.88)}
.acts{display:flex;flex-wrap:wrap;gap:12px;margin-top:30px}
/* sections */
.alt-bg{background:#fff}
.about{display:grid;grid-template-columns:1fr 1fr;gap:56px;align-items:center}
@media(max-width:900px){.about{grid-template-columns:1fr}}
.about .pic{border-radius:calc(var(--r) * 1.3);overflow:hidden;aspect-ratio:5/4;background:linear-gradient(140deg,var(--p),var(--d));display:grid;place-items:center;color:#fff;position:relative}
.about .pic img{width:100%;height:100%;object-fit:cover}
.mono{font-family:var(--hf);font-size:8rem;font-weight:800;opacity:.9;line-height:1}
.ticks{list-style:none;padding:0;margin:22px 0 0;display:grid;gap:10px}.ticks li{display:flex;gap:10px;align-items:flex-start}.ticks svg{color:var(--p);flex:none;margin-top:3px}
.stats{background:var(--d);color:#fff;padding:60px 0}.stats .n{font-family:var(--hf);font-size:2.6rem;font-weight:800;color:var(--a);line-height:1}.stats p{margin:6px 0 0;opacity:.8}
.prod{padding:0;overflow:hidden;display:flex;flex-direction:column}
.prod .im{aspect-ratio:4/3;background:linear-gradient(135deg,color-mix(in srgb,var(--p) 20%,#fff),color-mix(in srgb,var(--a) 25%,#fff));display:grid;place-items:center;color:var(--p)}
.prod .im img{width:100%;height:100%;object-fit:cover}
.prod .bd{padding:18px 20px 20px;display:flex;flex-direction:column;gap:6px;flex:1}
.price{font-weight:800;color:var(--p);font-size:1.1rem}
.prod .bd a{margin-top:auto;font-weight:700;color:var(--p);text-decoration:none;display:inline-flex;gap:6px;align-items:center}
.ev{display:flex;gap:20px;align-items:center}
.date{flex:none;width:74px;text-align:center;border-radius:var(--r);background:var(--p);color:#fff;padding:10px 0}
.date b{display:block;font-size:1.7rem;font-family:var(--hf);line-height:1}.date span{font-size:.78rem;text-transform:uppercase;letter-spacing:.08em}
.gal{columns:3 260px;column-gap:16px}.gal img{width:100%;border-radius:var(--r);margin-bottom:16px;break-inside:avoid}
.quote{font-size:1.02rem}.quote:before{content:"“";font-family:Georgia,serif;font-size:3.6rem;line-height:.6;color:var(--a);display:block;margin-bottom:6px}
.who{margin-top:16px;font-weight:700}.who span{display:block;font-weight:400;color:var(--mut);font-size:.9rem}
details.q{border-bottom:1px solid var(--line);padding:18px 0}details.q summary{cursor:pointer;font-weight:700;font-size:1.05rem;list-style:none;display:flex;justify-content:space-between;gap:20px}
details.q summary:after{content:"+";color:var(--p);font-size:1.4rem;line-height:1}details.q[open] summary:after{content:"–"}details.q p{margin:12px 0 0;color:var(--mut)}
.band{border-radius:calc(var(--r) * 1.4);padding:46px;background:linear-gradient(120deg,var(--p),var(--d));color:#fff;display:flex;align-items:center;justify-content:space-between;gap:24px;flex-wrap:wrap}
.band h2{color:#fff;margin:0}.band p{margin:8px 0 0;opacity:.85}
.contact{display:grid;grid-template-columns:.9fr 1.1fr;gap:40px}
@media(max-width:900px){.contact{grid-template-columns:1fr}}
.info{display:grid;gap:18px}.info .row{display:flex;gap:14px;align-items:flex-start}.info .ico{margin:0;width:44px;height:44px;flex:none}
.info a{text-decoration:none}
form.f{display:grid;gap:14px}
.f label{font-weight:600;font-size:.9rem;display:block;margin-bottom:6px}
.f input,.f textarea{width:100%;padding:13px 14px;border:1px solid color-mix(in srgb,var(--d) 18%,transparent);border-radius:calc(var(--r) * .6 + 4px);font:inherit;background:#fff;color:var(--txt)}
.f input:focus,.f textarea:focus{outline:2px solid color-mix(in srgb,var(--p) 40%,transparent);border-color:var(--p)}
.f .two{display:grid;grid-template-columns:1fr 1fr;gap:14px}@media(max-width:560px){.f .two{grid-template-columns:1fr}}
.chk{display:flex;gap:10px;font-size:.9rem;color:var(--mut);align-items:flex-start}.chk input{width:auto;margin-top:4px}
.hp{position:absolute;left:-9999px}
.flash{padding:14px 16px;border-radius:var(--r);font-weight:600}.flash.ok{background:#E7F6EE;color:#0B5E31}.flash.bad{background:#FCEAEA;color:#B42318}
.socials{display:flex;gap:10px;flex-wrap:wrap}.socials a{padding:8px 14px;border-radius:999px;border:1px solid currentColor;text-decoration:none;font-size:.88rem;font-weight:600;opacity:.85}
footer.ft{background:var(--d);color:rgba(255,255,255,.78);padding:46px 0 28px}
footer.ft .top{display:flex;justify-content:space-between;gap:30px;flex-wrap:wrap;align-items:center}
footer.ft h3{color:#fff}footer.ft .cr{margin-top:30px;padding-top:20px;border-top:1px solid rgba(255,255,255,.12);font-size:.85rem;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}
.wa{position:fixed;right:20px;bottom:20px;width:58px;height:58px;border-radius:50%;background:#25D366;color:#fff;display:grid;place-items:center;box-shadow:0 12px 28px -8px rgba(0,0,0,.4);z-index:60;transition:transform .2s}.wa:hover{transform:scale(1.07)}
.pv{position:fixed;left:50%;transform:translateX(-50%);bottom:18px;background:#0B1B3A;color:#fff;padding:10px 18px;border-radius:999px;font-size:.88rem;font-weight:600;z-index:70;box-shadow:0 10px 30px -10px rgba(0,0,0,.5)}
.reveal{opacity:0;transform:translateY(22px);transition:opacity .7s ease,transform .7s ease}.reveal.on{opacity:1;transform:none}
@media(prefers-reduced-motion:reduce){.reveal{opacity:1;transform:none;transition:none}html{scroll-behavior:auto}}
.nav .cartb{position:relative;display:inline-grid;place-items:center;width:44px;height:44px;border-radius:50%;border:1.5px solid currentColor;color:inherit;text-decoration:none;cursor:pointer;background:none;font:inherit}
.nav .cartb b{position:absolute;top:-6px;right:-6px;min-width:20px;height:20px;border-radius:10px;background:var(--a);color:var(--d);font-size:.75rem;display:grid;place-items:center;padding:0 5px}
.page-head{background:linear-gradient(135deg,var(--p),var(--d));color:#fff;padding:56px 0 48px}.page-head h1{color:#fff;font-size:clamp(1.8rem,4vw,2.8rem);margin:0}.page-head p{margin:8px 0 0;opacity:.85}
.crumb{font-size:.88rem;opacity:.8;margin-bottom:10px}.crumb a{text-decoration:none}
</style>
@include('tracking.head', ['tracking' => $tracking ?? [], 'events' => $trackEvents ?? []])
@stack('head')
</head>
<body class="cards-{{ $t['cards'] ?? 'shadow' }}">
@if(! empty($c['announcement']))<div class="ann">{{ $c['announcement'] }}</div>@endif
<header class="nav nav-{{ $navMode }}">
  <div class="wrap nav-in">
    <a class="brand" href="{{ $page === 'home' ? '#top' : $home }}"><span class="mk">@if($logo)<img src="{{ $logo }}" alt="">@else{{ $initial }}@endif</span><span>{{ $name }}</span></a>
    <nav class="links">@foreach($nav as $k)<a href="{{ $href($anchors[$k]) }}">{{ $navLabels[$k] }}</a>@endforeach @if($shopOn)<a href="{{ $shopUrl }}" @if($page === 'shop') style="color:var(--p);opacity:1" @endif>Boutique</a>@endif @if($blogOn)<a href="{{ $blogUrl }}" @if(in_array($page, ['blog', 'post'], true)) style="color:var(--p);opacity:1" @endif>Blog</a>@endif</nav>
    @if($page === 'shop')<button class="cartb" type="button" onclick="openCart()" aria-label="Panier">{!! $ic('bag', 20) !!}<b id="cartCount">0</b></button>@else<a class="btn" href="{{ $page === 'home' ? $cta : (str_starts_with($cta, '#') ? $href(substr($cta, 1)) : $cta) }}">{{ $c['cta_label'] ?? 'Nous contacter' }}</a>@endif
    <details class="mm"><summary aria-label="Menu">{!! $ic('menu', 26) !!}</summary><div class="mm-p">@foreach($nav as $k)<a href="{{ $href($anchors[$k]) }}">{{ $navLabels[$k] }}</a>@endforeach @if($shopOn)<a href="{{ $shopUrl }}">Boutique</a>@endif @if($blogOn)<a href="{{ $blogUrl }}">Blog</a>@endif<a href="{{ $href('contact') }}" style="color:var(--p)">{{ $c['cta_label'] ?? 'Nous contacter' }}</a></div></details>
  </div>
</header>
<main id="top">
@yield('main')
</main>
<footer class="ft"><div class="wrap">
  <div class="top"><div><h3 style="margin-bottom:6px">{{ $name }}</h3><div>{{ \Illuminate\Support\Str::limit($c['hero_subtitle'] ?? '', 110) }}</div></div>
    <div class="socials">@foreach($socials as $k => $u)<a href="{{ $u }}" target="_blank" rel="noopener">{{ ucfirst($k) }}</a>@endforeach @if($shopOn)<a href="{{ $shopUrl }}">Boutique</a>@endif @if($blogOn)<a href="{{ $blogUrl }}">Blog</a>@endif</div></div>
  <div class="cr"><span>© {{ now()->year }} {{ $company->name }}{{ $company->trade_register ? ' · RCCM '.$company->trade_register : '' }}</span><span>Site réalisé avec {{ config('erp.name', 'Yomara ERP') }}</span></div>
</div></footer>
@if($whatsapp && $page !== 'shop')<a class="wa" href="https://wa.me/{{ $whatsapp }}" aria-label="WhatsApp" target="_blank" rel="noopener">{!! $ic('chat', 26) !!}</a>@endif
@if($preview)<div class="pv">{{ ! empty($template_preview) ? 'Aperçu du modèle « '.$template['name'].' » avec vos informations' : 'Aperçu — site non publié' }}</div>@endif
<script>
(function(){var els=document.querySelectorAll('.reveal');if(!('IntersectionObserver' in window)){els.forEach(function(e){e.classList.add('on')});return;}
var io=new IntersectionObserver(function(en){en.forEach(function(x){if(x.isIntersecting){x.target.classList.add('on');io.unobserve(x.target);}})},{threshold:.12});els.forEach(function(e){io.observe(e)});
document.querySelectorAll('details.mm a').forEach(function(a){a.addEventListener('click',function(){a.closest('details').removeAttribute('open')})});})();
</script>
@stack('scripts')
</body>
</html>
