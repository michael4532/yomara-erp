<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Installation — Yomara ERP</title>
<style>
  :root { --night:#0b1b3a; --blue:#1f6feb; --yellow:#ffc531; --ink:#14213d; --muted:#5b6b85; --line:#e3e8f1; --ok:#12a150; --ko:#d93025; --bg:#f4f6fb; }
  * { box-sizing:border-box; }
  body { margin:0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background:var(--bg); color:var(--ink); }
  header { background:var(--night); color:#fff; padding:28px 16px 64px; text-align:center; }
  header h1 { margin:0; font-size:24px; letter-spacing:.3px; }
  header h1 span { color:var(--yellow); }
  header p { margin:6px 0 0; color:#b9c6e4; font-size:14px; }
  .steps { display:flex; justify-content:center; gap:8px; margin-top:20px; flex-wrap:wrap; }
  .steps div { font-size:12px; padding:6px 12px; border-radius:20px; background:rgba(255,255,255,.08); color:#b9c6e4; }
  .steps div.on { background:var(--yellow); color:var(--night); font-weight:600; }
  main { max-width:640px; margin:-40px auto 40px; padding:0 16px; }
  .card { background:#fff; border-radius:14px; box-shadow:0 8px 30px rgba(11,27,58,.08); padding:28px; }
  h2 { margin:0 0 6px; font-size:20px; }
  .sub { color:var(--muted); margin:0 0 22px; font-size:14px; line-height:1.5; }
  label { display:block; font-size:13px; font-weight:600; margin:14px 0 6px; }
  input, select { width:100%; padding:11px 12px; border:1px solid var(--line); border-radius:8px; font-size:15px; background:#fff; }
  input:focus, select:focus { outline:2px solid var(--blue); border-color:transparent; }
  .row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
  @media (max-width:520px){ .row { grid-template-columns:1fr; } }
  .btn { display:inline-block; margin-top:24px; background:var(--yellow); color:var(--night); border:0; padding:13px 22px; border-radius:8px; font-weight:700; font-size:15px; cursor:pointer; text-decoration:none; }
  .btn[disabled] { opacity:.4; cursor:not-allowed; }
  ul.checks { list-style:none; padding:0; margin:0; }
  ul.checks li { display:flex; justify-content:space-between; gap:12px; padding:9px 0; border-bottom:1px solid var(--line); font-size:14px; }
  .ok { color:var(--ok); font-weight:600; } .ko { color:var(--ko); font-weight:600; } .opt { color:var(--muted); }
  .alert { background:#fdecea; color:#8a1c14; border-radius:8px; padding:12px 14px; font-size:14px; margin-bottom:12px; }
  .hint { font-size:12px; color:var(--muted); margin-top:4px; }
  .choice { display:flex; gap:10px; }
  .choice label { flex:1; margin:0; border:1px solid var(--line); border-radius:10px; padding:12px; font-weight:500; cursor:pointer; }
  .choice input { width:auto; margin-right:6px; }
  code, pre { background:#f1f4fa; border-radius:6px; font-size:13px; }
  pre { padding:12px; overflow-x:auto; white-space:pre-wrap; word-break:break-all; }
  footer { text-align:center; color:var(--muted); font-size:12px; margin:24px 0; }
</style>
</head>
<body>
<header>
  <h1>Yomara <span>ERP</span></h1>
  <p>Assistant d'installation</p>
  <div class="steps">
    @php $s = ['requirements' => '1. Prérequis', 'database' => '2. Base de données', 'setup' => '3. Société', 'done' => '4. Terminé']; @endphp
    @foreach ($s as $k => $label)
      <div class="{{ $step === $k ? 'on' : '' }}">{{ $label }}</div>
    @endforeach
  </div>
</header>
<main><div class="card">@yield('content')</div></main>
<footer>© {{ date('Y') }} Yomara Technologies</footer>
@stack('scripts')
</body>
</html>
