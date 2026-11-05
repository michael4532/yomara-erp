<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title') · {{ $company->name }}</title>
<style>
  :root { --night:#0B1B3A; --blue:#1F6FEB; --yellow:#FFC531; --bg:#F4F6FB; --line:#E3E8F1; --muted:#5B6B85; --ok:#12A150; --bad:#D93025; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; background:var(--bg); color:var(--night); font-size:15px; line-height:1.5; }
  a { color:var(--blue); text-decoration:none; }
  .hero { background:var(--night); color:#fff; padding:26px 16px 30px; }
  .hero .in, main { max-width:760px; margin:0 auto; }
  .brand { display:flex; gap:12px; align-items:center; }
  .logo { width:48px; height:48px; border-radius:13px; background:var(--yellow); color:var(--night); display:flex; align-items:center; justify-content:center; font-weight:800; font-size:20px; overflow:hidden; flex:none; }
  .logo img { width:100%; height:100%; object-fit:contain; background:#fff; }
  .hero h1 { margin:0; font-size:21px; } .hero p { margin:2px 0 0; color:#C9D4EA; font-size:14px; }
  main { padding:20px 16px 48px; }
  h2 { font-size:18px; margin:0 0 12px; }
  .card { background:#fff; border:1px solid var(--line); border-radius:16px; padding:18px; margin-bottom:14px; }
  .job { display:block; color:inherit; } .job:hover { border-color:var(--blue); }
  .job h3 { margin:0 0 4px; font-size:17px; color:var(--night); }
  .tags { display:flex; gap:6px; flex-wrap:wrap; margin-top:8px; } .tag { background:#EEF3FF; color:var(--blue); border-radius:20px; padding:3px 10px; font-size:12.5px; font-weight:600; }
  .muted { color:var(--muted); font-size:14px; }
  .text { white-space:pre-line; }
  label { display:block; font-weight:600; font-size:14px; margin:12px 0 6px; }
  input, textarea { width:100%; padding:12px; border:1px solid var(--line); border-radius:10px; font:inherit; background:#fff; }
  .two { display:grid; grid-template-columns:1fr 1fr; gap:12px; } @media (max-width:560px) { .two { grid-template-columns:1fr; } }
  .check { display:flex; gap:10px; align-items:flex-start; font-weight:400; } .check input { width:auto; margin-top:4px; }
  .btn { display:inline-block; width:100%; padding:14px; border-radius:12px; border:0; background:var(--blue); color:#fff; font-size:16px; font-weight:800; cursor:pointer; text-align:center; margin-top:16px; }
  .btn.ghost { background:#fff; color:var(--night); border:1px solid var(--line); font-weight:700; }
  .flash { padding:12px 14px; border-radius:12px; margin-bottom:14px; font-weight:600; } .flash.ok { background:#E7F6EE; color:#0B5E31; } .flash.bad { background:#FCEAEA; color:var(--bad); }
  .err { color:var(--bad); font-size:13px; margin-top:4px; }
  .hp { position:absolute; left:-9999px; }
  footer { text-align:center; color:var(--muted); font-size:12px; margin:20px 0; }
</style>
</head>
<body>
<div class="hero"><div class="in brand">
  <div class="logo">@if($company->logo && (str_starts_with($company->logo, 'http') || str_starts_with($company->logo, 'data:image')))<img src="{{ $company->logo }}" alt="">@else{{ mb_substr($company->name, 0, 1) }}@endif</div>
  <div><h1>{{ $company->name }} recrute</h1><p>{{ trim($company->address.' '.$company->city) }}</p></div>
</div></div>
<main>
  @if(session('ok'))<div class="flash ok">{{ session('ok') }}</div>@endif
  @if(session('error'))<div class="flash bad">{{ session('error') }}</div>@endif
  @yield('content')
</main>
<footer>Offres d'emploi · {{ config('erp.name') }}</footer>
</body>
</html>
