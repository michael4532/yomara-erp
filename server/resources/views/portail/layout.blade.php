<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>@yield('title') · {{ $company->name ?? 'Espace client' }}</title>
<style>
  :root { --night:#0B1B3A; --blue:#1F6FEB; --yellow:#FFC531; --bg:#F4F6FB; --line:#E3E8F1; --muted:#5B6B85; --ok:#12A150; --bad:#D93025; --warn:#9A6B00; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; background:var(--bg); color:var(--night); font-size:15px; }
  a { color:var(--blue); text-decoration:none; } a:hover { text-decoration:underline; }
  header.top { background:var(--night); color:#fff; }
  .bar { max-width:1040px; margin:0 auto; padding:16px; display:flex; align-items:center; gap:12px; }
  .logo { width:40px; height:40px; border-radius:11px; background:var(--yellow); color:var(--night); display:flex; align-items:center; justify-content:center; font-weight:800; font-size:18px; overflow:hidden; flex:none; }
  .logo img { width:100%; height:100%; object-fit:contain; background:#fff; }
  .bar .who { margin-left:auto; text-align:right; font-size:13px; color:#C9D4EA; } .bar .who strong { color:#fff; display:block; font-size:14px; }
  nav.tabs { max-width:1040px; margin:0 auto; padding:0 8px; display:flex; gap:2px; overflow-x:auto; scrollbar-width:none; }
  nav.tabs a { color:#C9D4EA; padding:12px 12px; font-weight:600; font-size:14px; white-space:nowrap; border-bottom:3px solid transparent; }
  nav.tabs a:hover { text-decoration:none; color:#fff; } nav.tabs a.on { color:#fff; border-color:var(--yellow); }
  @media (min-width:760px) { nav.tabs { flex-wrap:wrap; overflow:visible; } nav.tabs a { padding:12px 10px; } }
  @media (max-width:759px) { nav.tabs { -webkit-mask-image:linear-gradient(90deg,#000 88%,transparent); mask-image:linear-gradient(90deg,#000 88%,transparent); } }
  .badge { background:var(--yellow); color:var(--night); border-radius:10px; padding:1px 7px; font-size:11px; margin-left:4px; }
  main { max-width:1040px; margin:0 auto; padding:20px 16px 48px; }
  h1 { font-size:22px; margin:0 0 4px; } h2 { font-size:16px; margin:0 0 12px; }
  .muted { color:var(--muted); font-size:14px; }
  .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px; margin:16px 0; }
  .card { background:#fff; border:1px solid var(--line); border-radius:16px; padding:18px; margin-bottom:16px; box-shadow:0 1px 2px rgba(11,27,58,.05); }
  .kpi .v { font-size:24px; font-weight:800; margin-top:4px; } .kpi.alert .v { color:var(--bad); }
  table { width:100%; border-collapse:collapse; font-size:14px; }
  .card { overflow-x:auto; }
  .tscroll { overflow-x:auto; -webkit-overflow-scrolling:touch; margin:0 -4px; }
  .tscroll td, .tscroll th { white-space:nowrap; }
  @media (max-width:600px) { table { font-size:13px; } th, td { padding-left:6px !important; padding-right:6px !important; } }
  th { text-align:left; color:var(--muted); font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:.03em; padding:8px 6px; border-bottom:1px solid var(--line); }
  td { padding:10px 6px; border-bottom:1px solid var(--line); vertical-align:top; } td.r, th.r { text-align:right; white-space:nowrap; }
  tr:last-child td { border-bottom:0; }
  .pill { display:inline-block; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:700; white-space:nowrap; }
  .ok { background:#E7F6EE; color:var(--ok); } .bad { background:#FCEAEA; color:var(--bad); } .wait { background:#FFF6DB; color:var(--warn); } .info { background:#E8F0FE; color:var(--blue); } .grey { background:#EEF1F6; color:var(--muted); }
  .btn { display:inline-block; padding:10px 16px; border-radius:10px; border:0; background:var(--blue); color:#fff; font-size:14px; font-weight:700; cursor:pointer; text-decoration:none !important; }
  .btn.sm { padding:6px 12px; font-size:13px; } .btn.green { background:var(--ok); } .btn.ghost { background:#fff; color:var(--night); border:1px solid var(--line); } .btn.red { background:#fff; color:var(--bad); border:1px solid #F3C4C0; }
  .flash { padding:12px 14px; border-radius:12px; margin-bottom:16px; font-weight:600; } .flash.ok { background:#E7F6EE; } .flash.bad { background:#FCEAEA; }
  input[type=text], input[type=email], textarea, select { width:100%; padding:11px 12px; border:1px solid var(--line); border-radius:10px; font:inherit; background:#fff; }
  label { font-weight:600; font-size:14px; display:block; margin:12px 0 6px; }
  .row { display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
  .sp { flex:1; }
  .list a.item { display:flex; gap:12px; align-items:center; padding:12px 0; border-bottom:1px solid var(--line); color:inherit; } .list a.item:last-child { border-bottom:0; } .list a.item:hover { text-decoration:none; background:#FAFBFD; }
  footer { text-align:center; color:var(--muted); font-size:12px; margin:24px 0; }
  @media (max-width:640px) { .hide-sm { display:none; } .bar .who { display:none; } }
  @media print { header.top, nav, .noprint, footer { display:none !important; } body { background:#fff; } .card { border:0; box-shadow:none; padding:0; } main { padding:0; } }
</style>
</head>
<body>
@isset($token)
<header class="top">
  <div class="bar">
    <div class="logo">@if($company->logo && (str_starts_with($company->logo, 'http') || str_starts_with($company->logo, 'data:image')))<img src="{{ $company->logo }}" alt="">@else{{ mb_substr($company->name, 0, 1) }}@endif</div>
    <div><strong>{{ $company->name }}</strong><div style="font-size:12px;color:#C9D4EA">Espace client</div></div>
    <div class="who"><strong>{{ $partner->name }}</strong>{{ $partner->email ?: $partner->phone }}</div>
  </div>
  @php($t = request()->segment(3) ?: '')
  <nav class="tabs">
    <a href="{{ url('/portail/'.$token) }}" class="{{ $t === '' ? 'on' : '' }}">Accueil</a>
    <a href="{{ url('/portail/'.$token.'/factures') }}" class="{{ $t === 'factures' ? 'on' : '' }}">Factures</a>
    <a href="{{ url('/portail/'.$token.'/devis') }}" class="{{ $t === 'devis' ? 'on' : '' }}">Devis</a>
    <a href="{{ url('/portail/'.$token.'/commandes') }}" class="{{ $t === 'commandes' ? 'on' : '' }}">Commandes</a>
    @if($logistique)<a href="{{ url('/portail/'.$token.'/livraisons') }}" class="{{ $t === 'livraisons' ? 'on' : '' }}">Livraisons</a>@endif
 @if($fid)<a href="{{ url('/portail/'.$token.'/fidelite') }}" class="{{ $t === 'fidelite' ? 'on' : '' }}">Fidélité</a>@endif
 @if($rdv)<a href="{{ url('/portail/'.$token.'/rendez-vous') }}" class="{{ $t === 'rendez-vous' ? 'on' : '' }}">Rendez-vous</a>@endif
 @if($prest ?? false)<a href="{{ url('/portail/'.$token.'/prestations') }}" class="{{ $t === 'prestations' ? 'on' : '' }}">Forfaits & livrables</a>@endif
 @if($support ?? false)<a href="{{ url('/portail/'.$token.'/support') }}" class="{{ $t === 'support' ? 'on' : '' }}">Support</a>@endif
 @if($sav)<a href="{{ url('/portail/'.$token.'/interventions') }}" class="{{ $t === 'interventions' ? 'on' : '' }}">Interventions</a>@endif
    <a href="{{ url('/portail/'.$token.'/releve') }}" class="{{ $t === 'releve' ? 'on' : '' }}">Relevé de compte</a>
    <a href="{{ url('/portail/'.$token.'/messages') }}" class="{{ $t === 'messages' ? 'on' : '' }}">Messages @if($unreadReplies)<span class="badge">{{ $unreadReplies }}</span>@endif</a>
  </nav>
</header>
@endisset
<main>@yield('content')</main>
<footer>@isset($company){{ $company->name }} · {{ $company->phone }} · {{ $company->email }}<br>@endisset Espace client sécurisé · {{ config('erp.name') }}</footer>
</body>
</html>
