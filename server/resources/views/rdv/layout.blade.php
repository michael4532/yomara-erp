<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="@yield('robots', 'noindex')">
<title>@yield('title') · {{ $company->name }}</title>
<style>
  :root { --night:#0B1B3A; --blue:#1F6FEB; --yellow:#FFC531; --bg:#F4F6FB; --line:#E3E8F1; --muted:#5B6B85; --ok:#12A150; --bad:#D93025; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; background:var(--bg); color:var(--night); font-size:15px; }
  a { color:var(--blue); text-decoration:none; }
  .hero { background:var(--night); color:#fff; padding:22px 16px 26px; }
  .hero .in, main { max-width:720px; margin:0 auto; }
  .brand { display:flex; gap:12px; align-items:center; }
  .logo { width:46px; height:46px; border-radius:13px; background:var(--yellow); color:var(--night); display:flex; align-items:center; justify-content:center; font-weight:800; font-size:20px; overflow:hidden; flex:none; }
  .logo img { width:100%; height:100%; object-fit:contain; background:#fff; }
  .hero h1 { margin:0; font-size:20px; } .hero p { margin:2px 0 0; color:#C9D4EA; font-size:14px; }
  main { padding:18px 16px 48px; }
  h2 { font-size:17px; margin:0 0 12px; }
  .card { background:#fff; border:1px solid var(--line); border-radius:16px; padding:16px; margin-bottom:14px; }
  .muted { color:var(--muted); font-size:14px; }
  .svc { display:flex; gap:12px; align-items:center; padding:14px; border:1px solid var(--line); border-radius:14px; margin-bottom:10px; color:inherit; background:#fff; }
  .svc:hover { border-color:var(--blue); } .svc .sp { flex:1; } .svc .price { font-weight:800; white-space:nowrap; }
  .dot { width:10px; height:40px; border-radius:6px; background:var(--blue); flex:none; }
  .steps { display:flex; gap:6px; margin-bottom:14px; font-size:13px; color:var(--muted); flex-wrap:wrap; } .steps b { color:var(--night); }
  .days { display:flex; gap:8px; overflow-x:auto; padding-bottom:6px; scrollbar-width:thin; }
  .day { min-width:72px; text-align:center; padding:10px 6px; border:1px solid var(--line); border-radius:12px; color:inherit; background:#fff; flex:none; }
  .day small { display:block; color:var(--muted); font-size:11px; } .day b { font-size:18px; display:block; }
  .day.on { background:var(--blue); color:#fff; border-color:var(--blue); } .day.on small { color:#DCE7FF; }
  .day.off { opacity:.45; pointer-events:none; }
  .slots { display:grid; grid-template-columns:repeat(auto-fill,minmax(84px,1fr)); gap:8px; }
  .slot { text-align:center; padding:11px 4px; border:1px solid #C9D8F5; border-radius:10px; font-weight:700; background:#F2F6FF; color:var(--blue); }
  .slot:hover { background:var(--blue); color:#fff; }
  .chips { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
  .chip { padding:7px 12px; border-radius:20px; border:1px solid var(--line); background:#fff; color:inherit; font-size:14px; } .chip.on { background:var(--night); color:#fff; border-color:var(--night); }
  label { display:block; font-weight:600; font-size:14px; margin:12px 0 6px; }
  select { width:100%; padding:12px; border:1px solid var(--line); border-radius:10px; font:inherit; background:#fff; color:inherit; }
  input, textarea { width:100%; padding:12px; border:1px solid var(--line); border-radius:10px; font:inherit; background:#fff; }
  .btn { display:inline-block; width:100%; padding:14px; border-radius:12px; border:0; background:var(--blue); color:#fff; font-size:16px; font-weight:800; cursor:pointer; text-align:center; }
  .btn.ghost { background:#fff; color:var(--night); border:1px solid var(--line); font-weight:700; } .btn.red { background:#fff; color:var(--bad); border:1px solid #F3C4C0; }
  .flash { padding:12px 14px; border-radius:12px; margin-bottom:14px; font-weight:600; } .flash.ok { background:#E7F6EE; color:#0B5E31; } .flash.bad { background:#FCEAEA; color:var(--bad); }
  .row { display:flex; justify-content:space-between; gap:12px; padding:7px 0; border-bottom:1px solid #EEF1F6; } .row:last-child { border:0; } .row span { color:var(--muted); }
  .pill { display:inline-block; padding:4px 12px; border-radius:20px; font-weight:700; font-size:13px; }
  .p-confirmed, .p-done { background:#E7F6EE; color:var(--ok); } .p-pending { background:#FFF6DB; color:#9A6B00; } .p-cancelled, .p-no_show { background:#FCEAEA; color:var(--bad); }
  .hp { position:absolute; left:-9999px; }
  footer { text-align:center; color:var(--muted); font-size:12px; margin:20px 0; }
</style>
</head>
<body>
<div class="hero"><div class="in brand">
  <div class="logo">@if($company->logo && (str_starts_with($company->logo, 'http') || str_starts_with($company->logo, 'data:image')))<img src="{{ $company->logo }}" alt="">@else{{ mb_substr($company->name, 0, 1) }}@endif</div>
  <div><h1>{{ $company->name }}</h1><p>{{ trim($company->address.' '.$company->city) }}{{ $company->phone ? ' · '.$company->phone : '' }}</p></div>
</div></div>
<main>@yield('content')</main>
<footer>@yield('footer', 'Réservation en ligne') · {{ config('erp.name') }}</footer>
</body>
</html>
