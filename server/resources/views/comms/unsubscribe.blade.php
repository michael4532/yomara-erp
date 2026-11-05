<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>Désinscription — {{ $name }}</title>
<style>body{margin:0;background:#F4F6FB;font-family:Segoe UI,Roboto,Arial,sans-serif;color:#0B1530;display:grid;place-items:center;min-height:100vh;padding:16px;box-sizing:border-box}
.card{background:#fff;border-radius:16px;padding:32px;max-width:440px;width:100%;box-shadow:0 10px 30px rgba(11,21,48,.08)}h1{font-size:22px;margin:0 0 10px}p{color:#4A5875;line-height:1.5}
button{background:#0B1530;color:#fff;border:0;border-radius:10px;padding:12px 20px;font:inherit;font-weight:700;cursor:pointer}</style></head>
<body><div class="card">
@if($done)
  <h1>C'est noté.</h1>
  <p>L'adresse <b>{{ $email }}</b> ne recevra plus nos e-mails d'information. Les notifications dans l'application et les messages liés à votre compte continuent.</p>
@else
  <h1>Ne plus recevoir nos e-mails ?</h1>
  <p>L'adresse <b>{{ $email }}</b> ne recevra plus les e-mails d'information de {{ $name }}.</p>
  <form method="post" action="{{ url('/c/u/'.$token) }}"><button type="submit">Me désinscrire</button></form>
@endif
</div></body></html>
