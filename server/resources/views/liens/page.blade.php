@php
  $title = $l->title ?: ($company->name ?? 'Liens');
  $color = $p['color'] ?? '#0A1633';
  $cover = $p['cover_url'] ?? null;
  $q = $src === 'qr' ? '?q=1' : '';
  $initial = fn ($label) => mb_strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N}]/u', '', $label), 0, 1));
@endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $title }}</title>
<meta name="description" content="{{ $p['subtitle'] ?? $title }}">
<meta property="og:title" content="{{ $title }}">
<meta property="og:description" content="{{ $p['subtitle'] ?? ($p['description'] ?? '') }}">
@if($cover)<meta property="og:image" content="{{ $cover }}">@endif
<meta name="twitter:card" content="summary_large_image">
<meta name="theme-color" content="{{ $color }}">
<style>
:root{--c:{{ $color }}}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#111A2E;background:#0B1020;display:flex;justify-content:center}
.bg{position:fixed;inset:-40px;background:var(--c) center/cover no-repeat;filter:blur(38px) saturate(1.3);opacity:.85;transform:scale(1.1)}
.bg:after{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(0,0,0,.15),rgba(0,0,0,.55))}
main{position:relative;width:100%;max-width:440px;padding:42px 18px 30px;display:flex;flex-direction:column;align-items:center}
.cover{width:min(260px,70vw);aspect-ratio:1;border-radius:22px;background:var(--c) center/cover no-repeat;box-shadow:0 30px 70px -20px rgba(0,0,0,.65);animation:float 6s ease-in-out infinite,in .7s cubic-bezier(.2,.8,.2,1) both}
.cover.empty{display:grid;place-items:center;color:#fff;font:800 72px/1 system-ui}
@keyframes float{50%{transform:translateY(-8px)}}
@keyframes in{from{opacity:0;transform:translateY(20px) scale(.96)}}
h1{color:#fff;font-size:26px;line-height:1.15;text-align:center;margin:22px 0 6px;letter-spacing:-.01em;animation:in .7s .1s both}
.sub{color:rgba(255,255,255,.82);text-align:center;margin:0 0 6px;animation:in .7s .15s both}
.desc{color:rgba(255,255,255,.7);text-align:center;font-size:14.5px;line-height:1.5;margin:6px 0 0;animation:in .7s .2s both}
.list{width:100%;margin-top:24px;background:#fff;border-radius:20px;overflow:hidden;box-shadow:0 30px 70px -30px rgba(0,0,0,.6)}
.row{display:flex;align-items:center;gap:14px;padding:14px 16px;border-top:1px solid #EEF1F6;text-decoration:none;color:inherit;transition:background .15s;animation:in .5s cubic-bezier(.2,.8,.2,1) both;animation-delay:calc(.25s + var(--i) * .06s)}
.row:first-child{border-top:0}
.row:hover{background:#F6F8FC}
.ico{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;color:#fff;font-weight:800;font-size:18px;flex:none}
.name{flex:1;font-weight:700;font-size:16px}
.go{border:1.5px solid #D9DFEA;border-radius:999px;padding:7px 16px;font-weight:700;font-size:14px;color:#111A2E;transition:all .15s}
.row:hover .go{background:var(--c);border-color:var(--c);color:#fff}
footer{margin-top:22px;color:rgba(255,255,255,.65);font-size:12.5px}
footer a{color:#fff;text-decoration:none;font-weight:700}
@media (prefers-reduced-motion:reduce){*{animation:none!important}}
</style>
</head>
<body>
<div class="bg" @if($cover) style="background-image:url('{{ $cover }}')" @endif></div>
<main>
  @if($cover)<div class="cover" style="background-image:url('{{ $cover }}')" role="img" aria-label="{{ $title }}"></div>
  @else<div class="cover empty">{{ $initial($title) }}</div>@endif
  <h1>{{ $title }}</h1>
  @if(!empty($p['subtitle']))<p class="sub">{{ $p['subtitle'] }}</p>@endif
  @if(!empty($p['description']))<p class="desc">{{ $p['description'] }}</p>@endif
  <nav class="list">
    @foreach($p['buttons'] ?? [] as $i => $b)
      @php [$pname, $pcolor] = $platforms[$b['platform']] ?? ['Lien', '#64748B']; @endphp
      <a class="row" style="--i:{{ $i }}" href="/l/{{ $l->slug }}/b/{{ $i }}{{ $q }}" rel="nofollow noopener">
        <span class="ico" style="background:{{ $pcolor }};{{ $pcolor === '#FFFC00' ? 'color:#111' : '' }}">{{ $initial($pname) }}</span>
        <span class="name">{{ $b['label'] }}</span>
        <span class="go">{{ $b['action'] ?: (in_array($b['platform'], ['spotify', 'apple_music', 'deezer', 'audiomack', 'boomplay', 'soundcloud', 'tidal', 'amazon_music', 'youtube_music'], true) ? 'Écouter' : (in_array($b['platform'], ['youtube', 'tiktok'], true) ? 'Regarder' : 'Ouvrir')) }}</span>
      </a>
    @endforeach
  </nav>
  <footer>{{ $company->name ?? '' }} · <span>Créé avec</span> <a href="{{ url('/') }}">{{ config('erp.name', 'Yomara ERP') }}</a></footer>
</main>
</body>
</html>
