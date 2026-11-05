@extends('rdv.layout')
@section('title', 'Mon rendez-vous')
@php($local = $a->starts_at->copy()->timezone($cfg['tz'])->locale('fr'))
@section('content')
  @if($ok)<div class="flash ok">{{ $ok }}</div>@endif
  @if($error)<div class="flash bad">{{ $error }}</div>@endif
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px">
      <h2 style="margin:0">Rendez-vous {{ $a->number }}</h2>
      <span class="pill p-{{ $a->status }}">{{ \App\Modules\RendezVous\Models\Appointment::STATUSES[$a->status] ?? $a->status }}</span>
    </div>
    <div style="margin-top:12px">
      <div class="row"><span>Prestation</span><b>{{ $service?->name ?? '—' }}</b></div>
      <div class="row"><span>Date</span><b>{{ ucfirst($local->isoFormat('dddd D MMMM YYYY')) }}</b></div>
      <div class="row"><span>Heure</span><b>{{ $local->format('H:i') }} – {{ $a->ends_at->copy()->timezone($cfg['tz'])->format('H:i') }}</b></div>
      @if($staff)<div class="row"><span>Avec</span><b>{{ $staff->name }}</b></div>@endif
      <div class="row"><span>Au nom de</span><b>{{ $a->customer_name }}</b></div>
      @if($company->address || $company->city)<div class="row"><span>Adresse</span><b>{{ trim($company->address.' '.$company->city) }}</b></div>@endif
    </div>
  </div>
  @if(in_array($a->status, ['pending', 'confirmed']))
    <div class="card" style="display:grid;gap:10px">
      <a class="btn ghost" href="{{ url('/rdv/a/'.$a->token.'/calendrier.ics') }}">Ajouter à mon agenda</a>
      @if($company->address || $company->city)<a class="btn ghost" href="https://www.google.com/maps/search/?api=1&query={{ urlencode(trim($company->name.' '.$company->address.' '.$company->city)) }}" target="_blank">Itinéraire</a>@endif
      @if($canCancel)
        <a class="btn ghost" href="{{ url('/rdv/'.$company->slug).'?'.http_build_query(['service' => $a->service_id, 'reschedule' => $a->token]) }}">Changer de créneau</a>
        <form method="post" action="{{ url('/rdv/a/'.$a->token.'/annuler') }}" onsubmit="return confirm('Annuler ce rendez-vous ?')">
          @csrf
          <label for="reason" style="margin-top:0">Annuler le rendez-vous</label>
          <input id="reason" name="reason" placeholder="Motif (facultatif)">
          <button class="btn red" type="submit" style="margin-top:10px">Annuler le rendez-vous</button>
        </form>
      @else
        <p class="muted">Pour modifier ou annuler, appelez-nous{{ $company->phone ? ' au '.$company->phone : '' }}.</p>
      @endif
    </div>
  @elseif($a->status === 'cancelled')
    <a class="btn" href="{{ url('/rdv/'.$company->slug) }}">Reprendre un rendez-vous</a>
  @endif
@endsection
