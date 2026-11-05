<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>Nouveau mot de passe — {{ config('erp.name', 'Yomara ERP') }}</title>
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0A1633;font-family:system-ui,Segoe UI,Roboto,sans-serif;color:#111A2E;padding:16px}
.card{background:#fff;border-radius:20px;padding:30px;max-width:420px;width:100%;box-shadow:0 30px 80px -30px rgba(0,0,0,.6)}
h1{font-size:24px;margin:0 0 8px}p{color:#5B6B85;line-height:1.5;margin:0 0 18px}
label{display:block;font-weight:600;font-size:14px;margin:14px 0 6px}
input{width:100%;box-sizing:border-box;padding:12px 14px;border:1.5px solid #E2E6EF;border-radius:12px;font:inherit}
input:focus{outline:none;border-color:#2E6BE6}
button,.btn{display:block;width:100%;margin-top:20px;padding:14px;border:0;border-radius:12px;background:#FFC531;color:#0A1633;font:700 16px system-ui;cursor:pointer;text-align:center;text-decoration:none}
.err{background:#FEF3F2;color:#B42318;border-radius:10px;padding:10px 12px;font-size:14px;margin-bottom:10px}
.ok{background:#ECFDF3;color:#05603A;border-radius:10px;padding:12px;font-weight:600}
</style></head><body>
<div class="card">
  <h1>{{ $ok ? 'Mot de passe changé' : 'Nouveau mot de passe' }}</h1>
  @if($ok)
    <div class="ok">Votre mot de passe a été changé. Vous avez été déconnecté de tous vos appareils par sécurité.</div>
    <a class="btn" href="/app/">Se connecter</a>
  @elseif($expired)
    <p>Ce lien a expiré ou a déjà servi. Demandez-en un nouveau depuis « Mot de passe oublié » sur la page de connexion.</p>
    <a class="btn" href="/app/">Retour à la connexion</a>
  @else
    <p>Choisissez un nouveau mot de passe pour <b>{{ $email }}</b>.</p>
    @if($errors->any())<div class="err">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
    <form method="post">
      @csrf
      <input type="hidden" name="email" value="{{ $email }}">
      <label for="p1">Nouveau mot de passe (8 caractères minimum)</label>
      <input id="p1" type="password" name="password" required minlength="8" autocomplete="new-password" autofocus>
      <label for="p2">Confirmez le mot de passe</label>
      <input id="p2" type="password" name="password_confirmation" required minlength="8" autocomplete="new-password">
      <button type="submit">Enregistrer le mot de passe</button>
    </form>
  @endif
</div>
</body></html>
