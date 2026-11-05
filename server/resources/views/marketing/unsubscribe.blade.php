@extends('pay.layout')
@section('title', 'Désinscription')
@section('content')
  <div class="card" style="text-align:center">
    <h1>{{ $company?->name }}</h1>
    @if (! empty($confirmed))
      <p class="muted">Merci, votre inscription est confirmée.</p>
    @elseif ($done)
      <p class="muted">C'est fait : vous ne recevrez plus nos {{ $channel === 'email' ? 'e-mails' : ($channel === 'sms' ? 'SMS' : 'messages WhatsApp') }} d'information.</p>
    @else
      <p class="muted">Vous ne souhaitez plus recevoir nos {{ $channel === 'email' ? 'e-mails' : ($channel === 'sms' ? 'SMS' : 'messages WhatsApp') }} d'information ?</p>
      <form method="post">@csrf<button class="btn alt" style="text-align:center">Me désinscrire</button></form>
    @endif
  </div>
@endsection
