@php $cur = $currency === 'XOF' ? 'FCFA' : $currency; @endphp
<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;background:#F1F4FA;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0B1530">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F1F4FA;padding:28px 12px"><tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:18px;overflow:hidden">
  <tr><td style="background:#2E6BE6;background-image:linear-gradient(135deg,#2E6BE6,#7C3AED);padding:30px 32px;color:#ffffff">
    <div style="font-size:14px;opacity:.85;font-weight:600">{{ $appName }}</div>
    <div style="font-size:24px;font-weight:800;margin-top:6px">Bienvenue, {{ $name }} !</div>
    <div style="font-size:15px;opacity:.9;margin-top:4px">L'espace de {{ $company }} est prêt.</div>
  </td></tr>
  <tr><td style="padding:28px 32px">
    <p style="margin:0 0 18px;font-size:15px;line-height:1.6">Merci pour votre inscription. Voici le récapitulatif de votre compte. Connectez-vous avec votre e-mail et le mot de passe que vous avez choisi.</p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F7F9FD;border:1px solid #E5E9F2;border-radius:12px;font-size:15px">
      <tr><td style="padding:12px 16px;color:#64748B;border-bottom:1px solid #E5E9F2">Entreprise</td><td style="padding:12px 16px;font-weight:700;border-bottom:1px solid #E5E9F2">{{ $company }}</td></tr>
      <tr><td style="padding:12px 16px;color:#64748B;border-bottom:1px solid #E5E9F2">Identifiant (e-mail)</td><td style="padding:12px 16px;font-weight:700;border-bottom:1px solid #E5E9F2">{{ $email }}</td></tr>
      <tr><td style="padding:12px 16px;color:#64748B;border-bottom:1px solid #E5E9F2">Mot de passe</td><td style="padding:12px 16px;color:#334155;border-bottom:1px solid #E5E9F2">Celui que vous avez choisi à l'inscription (jamais envoyé par e-mail)</td></tr>
      @if($plan)<tr><td style="padding:12px 16px;color:#64748B">Formule</td><td style="padding:12px 16px;font-weight:700">{{ $plan->name }}{{ $trialDays > 0 ? ' · essai gratuit de '.$trialDays.' jours' : '' }}</td></tr>@endif
    </table>
    @if(!empty($modules))
      <p style="margin:22px 0 8px;font-size:15px;font-weight:700">{{ !empty($trialEnds) ? 'Offerts pendant votre essai, jusqu\'au '.$trialEnds->format('d/m/Y').' :' : 'Les modules de votre formule :' }}</p>
      <p style="margin:0 0 6px;line-height:2">@foreach($modules as $m)<span style="display:inline-block;background:#EEF3FF;color:#1E40AF;border-radius:999px;padding:3px 11px;margin:2px;font-size:13px;font-weight:600">{{ $m }}</span> @endforeach</p>
      @if(!empty($trialEnds))<p style="margin:0 0 4px;font-size:13.5px;color:#64748B;line-height:1.6">Nous vous préviendrons 7, 3 et 1 jour(s) avant la fin de l'essai. Sans formule choisie, votre espace passera sur la formule gratuite : vos données restent conservées.</p>@endif
    @endif
    <p style="margin:22px 0 10px;font-size:15px;font-weight:700">Deux façons d'utiliser {{ $appName }} :</p>
    <p style="margin:0 0 14px;font-size:14.5px;line-height:1.6"><b>🌐 En ligne</b>, sans rien installer :</p>
    <p style="margin:0 0 20px"><a href="{{ $url }}/app/" style="display:inline-block;background:#2E6BE6;color:#ffffff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:10px">Ouvrir {{ $appName }}</a></p>
    <p style="margin:0 0 8px;font-size:14.5px;line-height:1.6"><b>💻 Sur vos appareils</b>, pour travailler même sans internet :</p>
    <p style="margin:0 0 20px;font-size:14px;line-height:2">
      @foreach($platforms as $k => $label)
        @if($downloads[$k] ?? null)<a href="{{ $url }}/telecharger/{{ $k }}" style="color:#2E6BE6;font-weight:700;text-decoration:none;margin-right:14px">⬇ {{ $label }}</a>@endif
      @endforeach
      <a href="{{ $url }}/#telecharger" style="color:#64748B;text-decoration:none">Toutes les versions</a>
    </p>
    <p style="margin:0;font-size:13px;color:#64748B;line-height:1.6">Mot de passe oublié ? Utilisez « Mot de passe oublié » sur la page de connexion. Notre équipe ne vous demandera jamais votre mot de passe. Si vous n'êtes pas à l'origine de cette inscription, répondez simplement à cet e-mail.</p>
  </td></tr>
  <tr><td style="background:#F7F9FD;padding:18px 32px;font-size:12.5px;color:#64748B;line-height:1.6">
    {{ $contact['name'] ?: $appName }}@if($contact['phone']) · {{ $contact['phone'] }}@endif @if($contact['email']) · {{ $contact['email'] }}@endif<br>
    <a href="{{ $url }}" style="color:#64748B">{{ preg_replace('#^https?://#', '', $url) }}</a>
  </td></tr>
</table>
</td></tr></table>
</body></html>
