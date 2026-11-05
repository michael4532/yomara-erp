<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title')</title>
<style>
  :root { --night:#0B1B3A; --blue:#1F6FEB; --yellow:#FFC531; --bg:#F4F6FB; --line:#E3E8F1; --muted:#5B6B85; --ok:#12A150; --bad:#D93025; }
  * { box-sizing: border-box; }
  body { margin:0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--night); }
  .wrap { max-width: 640px; margin: 0 auto; padding: 24px 16px 48px; }
  .card { background:#fff; border-radius:16px; padding:20px; box-shadow:0 1px 2px rgba(11,27,58,.06); border:1px solid var(--line); margin-bottom:16px; }
  h1 { font-size: 20px; margin: 0 0 4px; } h2 { font-size: 15px; margin: 0 0 12px; }
  .muted { color: var(--muted); font-size: 14px; }
  .amount { font-size: 32px; font-weight: 800; margin: 8px 0; }
  table { width:100%; border-collapse: collapse; font-size: 14px; }
  td { padding: 6px 0; border-bottom: 1px solid var(--line); } td.r { text-align:right; white-space:nowrap; }
  .btn { display:block; width:100%; padding:14px 16px; border-radius:12px; border:0; background: var(--blue); color:#fff; font-size:16px; font-weight:700; cursor:pointer; margin-top:10px; text-align:left; }
  .btn small { display:block; font-weight:400; opacity:.85; font-size:12px; margin-top:2px; }
  .btn.alt { background: var(--night); }
  .pill { display:inline-block; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:700; }
  .ok { background:#E7F6EE; color: var(--ok); } .bad { background:#FCEAEA; color: var(--bad); } .wait { background:#FFF6DB; color:#9A6B00; }
  .err { background:#FCEAEA; color: var(--bad); padding:12px; border-radius:12px; margin-bottom:16px; }
  pre { white-space: pre-wrap; font-family: inherit; margin: 0; }
  .brand { display:flex; align-items:center; gap:10px; margin-bottom:16px; } .logo { width:36px; height:36px; border-radius:10px; background: var(--yellow); display:flex; align-items:center; justify-content:center; font-weight:800; }
  footer { text-align:center; color: var(--muted); font-size: 12px; margin-top: 24px; }
</style>
</head>
<body><div class="wrap">@yield('content')<footer>Paiement sécurisé · {{ config('erp.name') }}</footer></div></body>
</html>
