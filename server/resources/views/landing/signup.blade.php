@extends('landing.layout')
@section('title', 'Créer mon entreprise — '.$appName)
@php $cur = $currency === 'XOF' ? 'FCFA' : $currency; $sel = old('plan', $selected ?: optional($plans->first())->code); @endphp
@push('styles')
<style>
.auth{min-height:calc(100vh - 68px);display:grid;grid-template-columns:1fr 1.05fr}
.auth-side{background:var(--night);color:#fff;padding:64px 56px;position:relative;overflow:hidden}
.auth-side:after{content:"";position:absolute;width:520px;height:520px;right:-180px;bottom:-200px;background:radial-gradient(circle,rgba(255,197,49,.28),transparent 65%)}
.auth-side h1{font-size:38px;line-height:1.1;letter-spacing:-.03em;margin:0 0 16px;font-weight:800}
.auth-side p{color:#C7D2E8;font-size:16.5px}
.auth-side ul{list-style:none;padding:0;margin:28px 0 0;display:grid;gap:14px;position:relative;z-index:1}
.auth-side li{display:flex;gap:12px;align-items:flex-start;font-size:15px}
.auth-side li b{display:block}
.auth-side li span.i{flex:none;width:34px;height:34px;border-radius:10px;background:rgba(255,255,255,.1);display:grid;place-items:center}
.auth-form{padding:56px;display:flex;align-items:flex-start;justify-content:center}
.form{width:100%;max-width:520px}
.form h2{margin:0 0 4px;font-size:28px;letter-spacing:-.02em}
.form .muted{color:var(--mute);margin:0 0 24px}
label{display:block;font-weight:700;font-size:14px;margin:14px 0 6px}
input[type=text],input[type=email],input[type=password],input[type=tel]{width:100%;padding:13px 14px;border:1px solid var(--line);border-radius:12px;font:inherit;font-size:15px;background:#fff;transition:.15s}
input:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 4px rgba(46,107,230,.12)}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.plans-pick{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px}
.plans-pick label{margin:0;border:1px solid var(--line);border-radius:12px;padding:10px 12px;cursor:pointer;font-weight:600;background:#fff;transition:.15s}
.plans-pick input{display:none}
.plans-pick label:has(input:checked){border-color:var(--blue);background:rgba(46,107,230,.06);box-shadow:0 0 0 3px rgba(46,107,230,.12)}
.plans-pick b{display:block;font-size:14.5px}.plans-pick small{color:var(--mute);font-size:12.5px}.plans-pick s{opacity:.7}.plans-pick .off{display:block;font-style:normal;color:#067647;font-weight:700}
.err{background:#FEF2F2;border:1px solid #FECACA;color:#B91C1C;border-radius:12px;padding:12px 14px;font-size:14px;margin-bottom:10px}
.err ul{margin:0;padding-left:18px}
.check{display:flex;gap:10px;align-items:flex-start;font-weight:500;font-size:14px;color:var(--ink2);margin-top:16px}
.check input{margin-top:4px;width:16px;height:16px}
.pw{position:relative}.pw button{position:absolute;right:8px;top:50%;transform:translateY(-50%);border:0;background:none;color:var(--mute);cursor:pointer;font:inherit;font-size:13px;font-weight:700}
.meter{height:4px;border-radius:4px;background:var(--line);margin-top:6px;overflow:hidden}.meter i{display:block;height:100%;width:0;transition:.2s}
.submit{width:100%;margin-top:22px;padding:15px}
.foot{text-align:center;color:var(--mute);font-size:14px;margin-top:16px}.foot a{color:var(--blue);font-weight:700}
.help{position:relative;z-index:1;margin-top:34px;border-top:1px solid rgba(255,255,255,.14);padding-top:22px}
.help b{display:block;margin-bottom:12px}
.help-links{display:flex;flex-wrap:wrap;gap:8px}
.help-links a{border:1px solid rgba(255,255,255,.25);border-radius:999px;padding:8px 14px;font-weight:600;font-size:14px}
.help-links a.wa{background:#25D366;border-color:#25D366;color:#fff}
.help small{display:block;color:#AEB9D3;margin-top:10px}
@media (max-width:960px){.auth{grid-template-columns:1fr}.auth-side{padding:40px 24px}.auth-side ul{display:none}.auth-form{padding:32px 20px}}
@media (max-width:520px){.row2{grid-template-columns:1fr}}
</style>
@endpush
@section('content')
<div class="auth">
  <aside class="auth-side">
    <h1>Votre entreprise prête en 2 minutes.</h1>
    <p>{{ $trialDays > 0 ? $trialDays.' jours d\'essai gratuit, sans carte bancaire. ' : '' }}Aucune installation : vous vous connectez juste après l'inscription.</p>
    <ul>
      <li><span class="i">✉️</span><span><b>Vos accès par e-mail</b>Identifiant, mot de passe et liens de connexion vous sont envoyés aussitôt.</span></li>
      <li><span class="i">🌐</span><span><b>En ligne ou installé</b>Navigateur, Windows, Mac, Linux, Android ou iPhone : le même compte partout.</span></li>
      <li><span class="i">⚡</span><span><b>Même sans internet</b>Le logiciel installé continue de fonctionner et se synchronise tout seul.</span></li>
      <li><span class="i">🔒</span><span><b>Vos données protégées</b>Hébergées sur nos serveurs sécurisés et sauvegardées.</span></li>
    </ul>
    <div class="help">
      <b>Besoin d'aide pour vous inscrire ?</b>
      @if(! $contact['whatsapp'] && ! $contact['phone'] && ! $contact['email'])<small style="margin-top:0">{!! ($chatEnabled ?? true) ? 'Écrivez-nous avec la bulle de discussion en bas à droite : un conseiller vous répond.' : 'Écrivez-nous depuis le <a href="/#contact" style="color:#fff;text-decoration:underline">formulaire de contact</a>.' !!}</small>@endif
      <div class="help-links">
        @if($contact['whatsapp'])<a class="wa" href="https://wa.me/{{ preg_replace('/\D+/', '', $contact['whatsapp']) }}?text={{ rawurlencode('Bonjour, j\'ai besoin d\'aide pour créer mon entreprise sur '.$appName.'.') }}" target="_blank" rel="noopener">WhatsApp</a>@endif
        @if($contact['phone'])<a href="tel:{{ preg_replace('/\s+/', '', $contact['phone']) }}">{{ $contact['phone'] }}</a>@endif
        @if($contact['email'])<a href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a>@endif
      </div>
      @if($contact['hours'])<small>{{ $contact['hours'] }}</small>@endif
    </div>
  </aside>
  <div class="auth-form">
    <form class="form" method="post" action="/inscription" id="f" autocomplete="on">
      @csrf
      <h2>Créer mon entreprise</h2>
      <p class="muted">Vous serez l'administrateur de votre espace.</p>
      @if($parrain)
        <div style="background:#ECFDF3;border:1px solid #A6F4C5;color:#05603A;border-radius:12px;padding:12px 14px;font-size:14.5px;margin-bottom:12px">🎁 Invité par <b>{{ $referrerName }}</b>{{ $welcomeDiscount > 0 ? ' : −'.rtrim(rtrim(number_format($welcomeDiscount, 1, ',', ''), '0'), ',').' % sur votre premier achat' : '' }}.</div>
        <input type="hidden" name="parrain" value="{{ $parrain }}">
      @endif
      @if($errors->any())
        <div class="err"><ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
      @endif
      <label for="company_name">Nom de l'entreprise</label>
      <input type="text" id="company_name" name="company_name" value="{{ old('company_name') }}" required maxlength="255" autocomplete="organization" placeholder="Ex. Boutique Akwaba SARL">
      <div class="row2">
        <div><label for="name">Votre nom complet</label><input type="text" id="name" name="name" value="{{ old('name') }}" required maxlength="255" autocomplete="name"></div>
        <div><label for="phone">Téléphone</label><input type="tel" id="phone" name="phone" value="{{ old('phone') }}" maxlength="50" autocomplete="tel" placeholder="+225 07 00 00 00 00"></div>
      </div>
      <label for="email">E-mail (votre identifiant de connexion)</label>
      <input type="email" id="email" name="email" value="{{ old('email') }}" required maxlength="255" autocomplete="email">
      <div class="row2">
        <div><label for="password">Mot de passe</label><div class="pw"><input type="password" id="password" name="password" required minlength="8" autocomplete="new-password"><button type="button" data-show="password">Afficher</button></div><div class="meter"><i id="m"></i></div></div>
        <div><label for="password_confirmation">Confirmation</label><input type="password" id="password_confirmation" name="password_confirmation" required minlength="8" autocomplete="new-password"></div>
      </div>
      @if($plans->count() > 1)
        <label>Formule</label>
        <div class="plans-pick">
          @foreach($plans as $p)
            <label><input type="radio" name="plan" value="{{ $p->code }}" @checked($sel === $p->code)><b>{{ $p->name }}</b><small>
              @if($p->price_monthly <= 0)
                Gratuit, sans limite de durée
              @elseif($p->intro_discount > 0 && $p->intro_months > 0)
                <s>{{ number_format($p->price_monthly, 0, ',', ' ') }}</s> {{ number_format($p->introPrice(), 0, ',', ' ') }} {{ $cur }} / mois <em class="off">−{{ rtrim(rtrim(number_format($p->intro_discount, 1, ',', ''), '0'), ',') }} % {{ $p->intro_months }} mois</em>
              @else
                {{ number_format($p->price_monthly, 0, ',', ' ') }} {{ $cur }} / mois
              @endif
            </small></label>
          @endforeach
        </div>
      @elseif($plans->count() === 1)
        <input type="hidden" name="plan" value="{{ $plans->first()->code }}">
      @endif
      <label class="check"><input type="checkbox" name="terms" value="1" @checked(old('terms')) required> <span>J'accepte les conditions d'utilisation et la <a href="/confidentialite" target="_blank">politique de confidentialité</a> de {{ $contact['name'] ?: $appName }}.</span></label>
      <button class="btn btn-primary submit" type="submit" id="go">Créer mon entreprise{{ $trialDays > 0 ? ' gratuitement' : '' }}</button>
      <p class="foot">Déjà un compte ? <a href="/app/">Se connecter</a></p>
    </form>
  </div>
</div>
@endsection
@push('scripts')
<script>
document.querySelectorAll('[data-show]').forEach(b => b.addEventListener('click', () => { const i = document.getElementById(b.dataset.show); const t = i.type === 'password'; i.type = t ? 'text' : 'password'; document.getElementById('password_confirmation').type = i.type; b.textContent = t ? 'Masquer' : 'Afficher'; }));
document.getElementById('password').addEventListener('input', e => { const v = e.target.value; let s = 0; if (v.length >= 8) s++; if (/[A-Z]/.test(v) && /[a-z]/.test(v)) s++; if (/\d/.test(v)) s++; if (/[^A-Za-z0-9]/.test(v) || v.length >= 12) s++; const m = document.getElementById('m'); m.style.width = (s * 25) + '%'; m.style.background = ['#EF4444', '#EF4444', '#F59E0B', '#22C55E', '#16A34A'][s]; });
document.getElementById('f').addEventListener('submit', e => { const p = document.getElementById('password'), c = document.getElementById('password_confirmation'); if (p.value !== c.value) { e.preventDefault(); c.setCustomValidity('Les deux mots de passe ne correspondent pas.'); c.reportValidity(); c.addEventListener('input', () => c.setCustomValidity(''), { once: true }); return; } const b = document.getElementById('go'); b.disabled = true; b.textContent = 'Création de votre espace…'; });
</script>
@endpush
