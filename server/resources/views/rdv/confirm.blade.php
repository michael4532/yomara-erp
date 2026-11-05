@extends('rdv.layout')
@section('title', 'Confirmer le rendez-vous')
@php($local = $start->copy()->timezone($cfg['tz'])->locale('fr'))
@section('content')
  <div class="steps"><span>1. Prestation</span> › <span>2. Date et heure</span> › <b>3. Vos coordonnées</b></div>
  @if($error)<div class="flash bad">{{ $error }}</div>@endif
  @if($errors->any())<div class="flash bad">{{ $errors->first() }}</div>@endif
  <div class="card">
    <div class="row"><span>Prestation</span><b>{{ $service->name }}</b></div>
    <div class="row"><span>Date</span><b>{{ ucfirst($local->isoFormat('dddd D MMMM YYYY')) }}</b></div>
    <div class="row"><span>Heure</span><b>{{ $local->format('H:i') }} – {{ $local->copy()->addMinutes($service->duration_minutes)->format('H:i') }}</b></div>
    @if($staff)<div class="row"><span>Avec</span><b>{{ $staff->name }}</b></div>@endif
    @if($service->price > 0)<div class="row"><span>Prix</span><b>{{ number_format($service->price, 0, ',', ' ') }} {{ $company->currency === 'XOF' ? 'F CFA' : $company->currency }}</b></div>@endif
  </div>
  <form class="card" method="post" action="{{ url('/rdv/'.$company->slug) }}">
    @csrf
    <input type="hidden" name="service_id" value="{{ $service->id }}">
    <input type="hidden" name="staff_id" value="{{ $staff?->id }}">
    <input type="hidden" name="starts_at" value="{{ $start->toIso8601String() }}">
    <input type="hidden" name="reschedule" value="{{ $reschedule?->token }}">
    <input type="hidden" name="portal" value="{{ $portal }}">
    <input class="hp" type="text" name="website" tabindex="-1" autocomplete="off">
    @if(! $reschedule)
      <label for="name">Nom et prénom</label>
      <input id="name" name="name" required value="{{ old('name', $partner?->name) }}" autocomplete="name">
      <label for="phone">Téléphone (WhatsApp de préférence)</label>
      <input id="phone" name="phone" required value="{{ old('phone', $partner?->phone) }}" inputmode="tel" autocomplete="tel">
      <label for="email">E-mail (facultatif)</label>
      <input id="email" name="email" type="email" value="{{ old('email', $partner?->email) }}" autocomplete="email">
      <label for="notes">Une précision ? (facultatif)</label>
      <textarea id="notes" name="notes" rows="2">{{ old('notes') }}</textarea>
    @endif
    <p class="muted" style="margin:14px 0">Vous recevrez une confirmation et un rappel avant le rendez-vous. Annulation en ligne possible jusqu'à {{ rtrim(rtrim(number_format($cfg['cancel_notice'], 1, ',', ''), '0'), ',') }} h avant.</p>
    <button class="btn" type="submit">{{ $reschedule ? 'Déplacer mon rendez-vous' : ($service->requires_approval || ! $cfg['auto_confirm'] ? 'Envoyer ma demande' : 'Confirmer le rendez-vous') }}</button>
  </form>
  <a class="muted" href="javascript:history.back()">‹ Choisir un autre créneau</a>
@endsection
