@php
  $col = $page->color ?: '#1F6FEB';
  $fields = (array) (json_decode((string) $page->fields, true) ?: ['name', 'phone']);
  $bullets = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $page->bullets))));
  $labels = ['name' => 'Votre nom', 'phone' => 'Téléphone (WhatsApp)', 'email' => 'E-mail', 'company' => 'Entreprise', 'city' => 'Ville', 'message' => 'Votre besoin'];
  $types = ['phone' => 'tel', 'email' => 'email'];
@endphp<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $page->headline }} — {{ $company->name }}</title>
<meta name="description" content="{{ \Illuminate\Support\Str::limit($page->subheadline ?: $page->body, 160) }}">
<meta property="og:title" content="{{ $page->headline }}">@if($page->image_url)<meta property="og:image" content="{{ $page->image_url }}">@endif
@if($page->status !== 'published')<meta name="robots" content="noindex">@endif
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--p:{{ $col }};--ink:#0f172a;--mut:#5b6474;--line:#e5e9f0;--bg:#f6f8fb}
*{box-sizing:border-box}body{margin:0;font-family:Inter,system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;color:var(--ink);background:var(--bg);line-height:1.55;-webkit-font-smoothing:antialiased}
.top{display:flex;align-items:center;gap:10px;max-width:1100px;margin:0 auto;padding:18px 20px;font-weight:700}
.top img{width:36px;height:36px;border-radius:10px;object-fit:cover}
.hero{max-width:1100px;margin:0 auto;padding:12px 20px 56px;display:grid;grid-template-columns:1.1fr .9fr;gap:40px;align-items:start}
@media(max-width:860px){.hero{grid-template-columns:1fr;gap:26px}}
.badge{display:inline-block;background:color-mix(in srgb,var(--p) 12%,#fff);color:var(--p);font-weight:700;font-size:.9rem;padding:7px 14px;border-radius:999px;margin-bottom:16px}
h1{font-size:clamp(2rem,4.6vw,3.1rem);line-height:1.1;letter-spacing:-.025em;margin:0 0 14px}
.sub{font-size:1.15rem;color:var(--mut);margin:0 0 22px;max-width:36em}
ul.b{list-style:none;padding:0;margin:0 0 22px}ul.b li{display:flex;gap:12px;align-items:flex-start;margin:10px 0;font-size:1.04rem}
ul.b li:before{content:"";flex:0 0 22px;height:22px;border-radius:50%;background:var(--p) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='3'%3E%3Cpath d='M5 12l5 5L20 7'/%3E%3C/svg%3E") center/14px no-repeat;margin-top:2px}
.body{color:#334155;white-space:pre-line;max-width:38em}
.img{width:100%;border-radius:18px;margin-top:22px;box-shadow:0 20px 50px -25px rgba(15,23,42,.45)}
.card{background:#fff;border:1px solid var(--line);border-radius:20px;padding:26px;box-shadow:0 24px 60px -30px rgba(15,23,42,.35);position:sticky;top:20px}
.card h2{margin:0 0 4px;font-size:1.3rem}.card p.m{margin:0 0 18px;color:var(--mut);font-size:.95rem}
label{display:block;font-size:.85rem;font-weight:600;margin:12px 0 6px}
input,textarea{width:100%;padding:13px 14px;border:1.5px solid var(--line);border-radius:12px;font:inherit;font-size:1rem;background:#fff}
input:focus,textarea:focus{outline:none;border-color:var(--p);box-shadow:0 0 0 4px color-mix(in srgb,var(--p) 15%,transparent)}
button{margin-top:18px;width:100%;padding:15px;border:0;border-radius:12px;background:var(--p);color:#fff;font:inherit;font-weight:700;font-size:1.05rem;cursor:pointer}
button:hover{filter:brightness(1.06)}button:focus-visible,a:focus-visible{outline:3px solid color-mix(in srgb,var(--p) 45%,transparent);outline-offset:2px}
.cd{display:flex;gap:8px;margin:0 0 18px}.cd div{flex:1;background:var(--ink);color:#fff;border-radius:12px;padding:10px 4px;text-align:center}.cd b{display:block;font-size:1.4rem;font-variant-numeric:tabular-nums}.cd span{font-size:.72rem;opacity:.75}
.err{background:#fef2f2;color:#991b1b;border-radius:10px;padding:10px 12px;font-size:.92rem;margin-bottom:8px}
.ok{text-align:center;padding:10px 0}.ok .ic{width:64px;height:64px;border-radius:50%;background:color-mix(in srgb,var(--p) 14%,#fff);display:grid;place-items:center;margin:0 auto 12px}
.wa{display:flex;align-items:center;justify-content:center;gap:10px;margin-top:12px;padding:13px;border-radius:12px;border:1.5px solid #25D366;color:#128C4A;font-weight:700;text-decoration:none}
.legal{font-size:.78rem;color:var(--mut);margin-top:12px}
.hp{position:absolute;left:-9999px}
footer{text-align:center;color:var(--mut);font-size:.82rem;padding:26px 20px 40px}
</style>
@include('tracking.head', ['tracking' => $tracking, 'events' => $events ?? []])
</head>
<body>
<header class="top">@if($logo)<img src="{{ $logo }}" alt="">@endif<span>{{ $company->name }}</span></header>
<main class="hero">
  <section>
    @if($page->offer)<span class="badge">{{ $page->offer }}</span>@endif
    <h1>{{ $page->headline }}</h1>
    @if($page->subheadline)<p class="sub">{{ $page->subheadline }}</p>@endif
    @if($bullets)<ul class="b">@foreach($bullets as $b)<li>{{ $b }}</li>@endforeach</ul>@endif
    @if($page->body)<div class="body">{{ $page->body }}</div>@endif
    @if($page->image_url)<img class="img" src="{{ $page->image_url }}" alt="">@endif
  </section>
  <aside class="card" id="form">
    @if($sent)
      <div class="ok"><div class="ic"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="{{ $col }}" stroke-width="2.6"><path d="M5 12l5 5L20 7"/></svg></div>
        <h2>Merci !</h2><p class="m" style="white-space:pre-line">{{ $page->thank_you ?: 'Votre demande est bien reçue. Nous vous contactons très vite.' }}</p></div>
      @if($page->whatsapp)<a class="wa" href="https://wa.me/{{ preg_replace('/\D/', '', $page->whatsapp) }}" target="_blank" rel="noopener">Nous écrire sur WhatsApp</a>@endif
    @elseif($page->status === 'closed')
      <h2>Offre terminée</h2><p class="m">Cette offre n'est plus disponible. Contactez {{ $company->name }} pour en savoir plus.</p>
      @if($page->whatsapp)<a class="wa" href="https://wa.me/{{ preg_replace('/\D/', '', $page->whatsapp) }}" target="_blank" rel="noopener">Nous écrire sur WhatsApp</a>@endif
    @else
      <h2>{{ $page->cta_label ?: 'Je suis intéressé' }}</h2><p class="m">Laissez vos coordonnées, on vous recontacte rapidement.</p>
      @if($page->deadline && \Illuminate\Support\Carbon::parse($page->deadline)->isFuture())
        <div class="cd" data-end="{{ \Illuminate\Support\Carbon::parse($page->deadline)->timestamp * 1000 }}"><div><b data-k="d">0</b><span>jours</span></div><div><b data-k="h">0</b><span>heures</span></div><div><b data-k="m">0</b><span>min</span></div><div><b data-k="s">0</b><span>s</span></div></div>
      @endif
      @if($error)<div class="err">{{ $error }}</div>@endif
      <form method="post" action="{{ url('/lp/'.$page->slug) }}">
        <input class="hp" type="text" name="website_hp" tabindex="-1" autocomplete="off" aria-hidden="true">
        @foreach($fields as $f)
          <label for="f_{{ $f }}">{{ $labels[$f] ?? $f }}@if(in_array($f, ['name', 'phone'], true)) *@endif</label>
          @if($f === 'message')<textarea id="f_{{ $f }}" name="{{ $f }}" rows="3">{{ old($f) }}</textarea>
          @else<input id="f_{{ $f }}" name="{{ $f }}" type="{{ $types[$f] ?? 'text' }}" value="{{ old($f) }}" @if(in_array($f, ['name', 'phone'], true)) required @endif autocomplete="{{ ['name' => 'name', 'phone' => 'tel', 'email' => 'email', 'company' => 'organization', 'city' => 'address-level2'][$f] ?? 'off' }}">@endif
        @endforeach
        <button type="submit">{{ $page->cta_label ?: 'Envoyer ma demande' }}</button>
        <p class="legal">Vos coordonnées servent uniquement à vous recontacter au sujet de cette offre.</p>
      </form>
      @if($page->whatsapp)<a class="wa" href="https://wa.me/{{ preg_replace('/\D/', '', $page->whatsapp) }}" target="_blank" rel="noopener">Ou écrivez-nous sur WhatsApp</a>@endif
    @endif
  </aside>
</main>
<footer>© {{ now()->year }} {{ $company->name }}@if($page->status !== 'published') · Aperçu : page non publiée @endif</footer>
<script>(function(){var el=document.querySelector('.cd');if(!el)return;var end=+el.dataset.end;function t(){var d=Math.max(0,end-Date.now())/1000;var v={d:Math.floor(d/86400),h:Math.floor(d%86400/3600),m:Math.floor(d%3600/60),s:Math.floor(d%60)};for(var k in v){el.querySelector('[data-k="'+k+'"]').textContent=v[k];}}t();setInterval(t,1000);})();</script>
</body>
</html>
