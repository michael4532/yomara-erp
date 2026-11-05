@extends('landing.layout')
@section('title', 'Bienvenue — '.$appName)
@push('styles')
<style>
.ok{max-width:860px;margin:56px auto;padding:0 20px}
.box{background:#fff;border:1px solid var(--line);border-radius:24px;padding:40px;box-shadow:var(--shadow);text-align:center}
.tick{width:72px;height:72px;border-radius:50%;margin:0 auto 18px;display:grid;place-items:center;font-size:34px;color:#fff;background:linear-gradient(135deg,#16A34A,#0EA5A4);box-shadow:0 16px 30px -12px rgba(22,163,74,.6)}
.box h1{margin:0 0 8px;font-size:32px;letter-spacing:-.03em}
.box p{color:var(--ink2);margin:0 auto;max-width:560px}
.recap{margin:26px auto 0;max-width:520px;text-align:left;background:var(--bg);border:1px solid var(--line);border-radius:16px;padding:6px 18px}
.recap div{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid var(--line);font-size:15px}
.recap div:last-child{border:0}.recap span{color:var(--mute)}
.mail{margin:18px auto 0;max-width:560px;border-radius:12px;padding:12px 14px;font-size:14.5px}
.mail.y{background:#ECFDF5;border:1px solid #A7F3D0;color:#065F46}.mail.n{background:#FFFBEB;border:1px solid #FDE68A;color:#92400E}
.choices{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:28px;text-align:left}
.choice{border:1px solid var(--line);border-radius:18px;padding:22px}
.choice h3{margin:0 0 4px;font-size:17px}.choice p{font-size:14px;margin:0 0 14px}
.oslist{display:flex;flex-wrap:wrap;gap:6px}
.oslist a,.oslist span{font-size:13px;font-weight:700;border:1px solid var(--line);border-radius:10px;padding:7px 11px}
.oslist a:hover{border-color:var(--blue);color:var(--blue)}.oslist span{color:var(--mute)}
@media (max-width:700px){.choices{grid-template-columns:1fr}.box{padding:28px 20px}}
</style>
@endpush
@section('content')
<div class="ok">
  <div class="box">
    <div class="tick">✓</div>
    <h1>Bienvenue, {{ $s['name'] }} !</h1>
    <p>L'espace de <b>{{ $s['company'] }}</b> est prêt. Connectez-vous avec votre e-mail et le mot de passe que vous venez de choisir.</p>
    <div class="recap">
      <div><span>Entreprise</span><b>{{ $s['company'] }}</b></div>
      <div><span>Identifiant</span><b>{{ $s['email'] }}</b></div>
      @if($s['plan'])<div><span>Formule</span><b>{{ $s['plan'] }}{{ $trialDays > 0 ? ' · essai '.$trialDays.' jours' : '' }}</b></div>@endif
    </div>
    @if($s['mailed'])
      <div class="mail y">✉️ Vos accès ont été envoyés à <b>{{ $s['email'] }}</b>. Pensez à vérifier vos courriers indésirables.</div>
    @else
      <div class="mail n">L'e-mail de bienvenue n'a pas pu partir pour le moment, mais votre compte est bien créé : vous pouvez vous connecter dès maintenant.</div>
    @endif
    <div class="choices">
      <div class="choice">
        <h3>🌐 Utiliser en ligne</h3>
        <p>Rien à installer : ouvrez {{ $appName }} dans votre navigateur.</p>
        <a class="btn btn-primary" href="/app/" style="width:100%">Ouvrir {{ $appName }}</a>
      </div>
      <div class="choice">
        <h3>💻 Installer le logiciel</h3>
        <p>Pour travailler même sans internet, sur ordinateur ou mobile.</p>
        <div class="oslist">
          @foreach($platforms as $k => $label)
            @if($downloads[$k] ?? null)<a href="/telecharger/{{ $k }}" rel="nofollow">⬇ {{ $label }}</a>@else<span>{{ $label }} · bientôt</span>@endif
          @endforeach
        </div>
      </div>
    </div>
  </div>
</div>
@endsection
