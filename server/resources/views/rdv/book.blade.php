@extends('rdv.layout')
@section('title', 'Prendre rendez-vous')
@php($fmt = fn ($v) => number_format((float) $v, in_array($company->currency, ['XOF','XAF','GNF']) ? 0 : 2, ',', ' ').' '.(in_array($company->currency, ['XOF','XAF']) ? 'F CFA' : $company->currency))
@php($q = fn (array $extra) => '?'.http_build_query(array_filter(['service' => $service?->id, 'staff' => $staffId ?? null, 'reschedule' => $reschedule?->token, 'portal' => $portal] + $extra + [], fn ($v) => $v !== null && $v !== '')))
@section('content')
  @if(session('error'))<div class="flash bad">{{ session('error') }}</div>@endif
  @if($reschedule)<div class="flash ok">Choisissez le nouveau créneau de votre rendez-vous {{ $reschedule->number }}.</div>@endif
  @if($partner && ! $reschedule)<p class="muted">Bonjour {{ $partner->name }}, choisissez votre prestation.</p>@endif
  <div class="steps"><b>1. Prestation</b> › <span @class(['muted' => ! $service])>@if($service)<b>2. Date et heure</b>@else 2. Date et heure @endif</span> › <span>3. Vos coordonnées</span></div>

  @if(! $service)
    @if(!empty($company->settings['rdv_intro']))<div class="card" style="white-space:pre-line">{{ $company->settings['rdv_intro'] }}</div>@endif
    @if($services->isEmpty())<div class="card muted">La réservation en ligne n'est pas encore ouverte. Appelez-nous au {{ $company->phone }}.</div>@endif
    @foreach($services->groupBy(fn ($s) => $s->category ?: '') as $cat => $group)
      @if($cat)<h2 style="margin-top:8px">{{ $cat }}</h2>@endif
      @foreach($group as $s)
        <a class="svc" href="{{ url('/rdv/'.$company->slug).'?'.http_build_query(array_filter(['service' => $s->id, 'portal' => $portal, 'reschedule' => $reschedule?->token])) }}">
          <span class="dot" style="background:{{ $s->color ?: '#1F6FEB' }}"></span>
          <span class="sp"><strong>{{ $s->name }}</strong><br><span class="muted">{{ $s->duration_minutes >= 60 ? intdiv($s->duration_minutes, 60).' h'.($s->duration_minutes % 60 ? ' '.($s->duration_minutes % 60) : '') : $s->duration_minutes.' min' }} · {{ \App\Modules\RendezVous\Models\Service::LOCATIONS[$s->location] ?? '' }}@if($s->description)<br>{{ \Illuminate\Support\Str::limit($s->description, 120) }}@endif</span></span>
          @if($s->price > 0)<span class="price">{{ $fmt($s->price) }}</span>@endif
        </a>
      @endforeach
    @endforeach
  @else
    <div class="card" style="display:flex;justify-content:space-between;gap:10px;align-items:center">
      <div><strong>{{ $service->name }}</strong><div class="muted">{{ $service->duration_minutes }} min{{ $service->price > 0 ? ' · '.$fmt($service->price) : '' }}</div></div>
      <a href="{{ url('/rdv/'.$company->slug).'?'.http_build_query(array_filter(['portal' => $portal, 'reschedule' => $reschedule?->token])) }}" class="muted">Changer</a>
    </div>
    @if($staffList->count() > 1)
      <div class="chips">
        <a class="chip {{ ! $staffId ? 'on' : '' }}" href="{{ url('/rdv/'.$company->slug).$q(['staff' => null, 'date' => $date]) }}">Peu importe</a>
        @foreach($staffList as $st)<a class="chip {{ $staffId === $st->id ? 'on' : '' }}" href="{{ url('/rdv/'.$company->slug).'?'.http_build_query(array_filter(['service' => $service->id, 'staff' => $st->id, 'reschedule' => $reschedule?->token, 'portal' => $portal, 'date' => $date])) }}">{{ $st->name }}</a>@endforeach
      </div>
    @endif
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <h2 style="margin:0">{{ ucfirst(\Carbon\Carbon::parse($date)->locale('fr')->isoFormat('MMMM YYYY')) }}</h2>
        <span>
          @if($from->gt(now($cfg['tz'])->endOfDay()))<a href="{{ url('/rdv/'.$company->slug).$q(['from' => $from->copy()->subDays(7)->toDateString()]) }}">‹ Avant</a> · @endif
          <a href="{{ url('/rdv/'.$company->slug).$q(['from' => $from->copy()->addDays(7)->toDateString()]) }}">Après ›</a>
        </span>
      </div>
      <div class="days">
        @foreach($days as $d)
          @php($c = \Carbon\Carbon::parse($d['date'])->locale('fr'))
          <a class="day {{ $d['date'] === $date ? 'on' : '' }} {{ $d['count'] === 0 ? 'off' : '' }}" href="{{ url('/rdv/'.$company->slug).$q(['from' => $from->toDateString(), 'date' => $d['date']]) }}">
            <small>{{ $c->isoFormat('ddd') }}</small><b>{{ $c->format('d') }}</b><small>{{ $d['count'] ? $d['count'].' libre'.($d['count'] > 1 ? 's' : '') : 'complet' }}</small>
          </a>
        @endforeach
      </div>
    </div>
    <div class="card">
      <h2>{{ ucfirst(\Carbon\Carbon::parse($date)->locale('fr')->isoFormat('dddd D MMMM')) }}</h2>
      @if(empty($slots))
        <p class="muted">Aucun créneau libre ce jour-là. Choisissez une autre date.</p>
      @else
        <div class="slots">
          @foreach($slots as $s)
            <a class="slot" href="{{ url('/rdv/'.$company->slug.'/confirmer').'?'.http_build_query(array_filter(['service' => $service->id, 'staff' => $staffId, 'start' => $s['starts_at'], 'reschedule' => $reschedule?->token, 'portal' => $portal])) }}">{{ $s['time'] }}</a>
          @endforeach
        </div>
      @endif
    </div>
  @endif
@endsection
