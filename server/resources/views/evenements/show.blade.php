@extends('rdv.layout')
@section('title', $event->name)
@php
  $fmt = fn ($v) => number_format((float) $v, 0, ',', ' ').' '.$cur;
  $colors = collect($event->categories ?? [])->mapWithKeys(fn ($c) => [$c['name'] => $c['color'] ?? '#1F6FEB']);
  $free = $spaces->filter(fn ($s) => ! $s->blocked && ! isset($taken[$s->id]));
  $cell = 36;
@endphp
@section('content')
<style>
  .hero .in, main { max-width:1000px; }
  .plan { width:100%; overflow-x:auto; background:#fff; border:1px solid var(--line); border-radius:16px; padding:10px; }
  .plan svg { display:block; min-width:640px; width:100%; height:auto; }
  .sp rect { stroke:#fff; stroke-width:2; rx:4; }
  .sp.free { cursor:pointer; } .sp.free:hover rect { opacity:.85; }
  .sp.sel rect { stroke:#0B1B3A; stroke-width:4; }
  .sp text { font-size:11px; font-weight:700; fill:#fff; pointer-events:none; }
  .legend { display:flex; gap:14px; flex-wrap:wrap; margin:10px 0 0; font-size:13px; color:var(--muted); }
  .legend i { display:inline-block; width:12px; height:12px; border-radius:3px; margin-right:5px; vertical-align:-1px; }
  .grid2 { display:grid; grid-template-columns:repeat(auto-fill,minmax(210px,1fr)); gap:10px; }
  .opt { display:flex; gap:10px; align-items:flex-start; padding:12px; border:1px solid var(--line); border-radius:12px; background:#fff; cursor:pointer; }
  .opt input { width:auto; margin-top:3px; } .opt b { display:block; } .opt small { color:var(--muted); }
  .pk { display:flex; gap:12px; align-items:center; padding:12px; border:1px solid var(--line); border-radius:12px; margin-bottom:10px; }
  .pk .sp2 { flex:1; } .pk input { width:70px; }
  .badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:12px; font-weight:700; background:#FFF6DB; color:#9A6B00; margin-left:6px; }
  ul.ben { margin:4px 0 0 18px; padding:0; color:var(--muted); font-size:13px; }
</style>
  @if($ok)<div class="flash ok">{{ $ok }}</div>@endif
  @if($error)<div class="flash bad">{{ $error }}</div>@endif
  @if($errors->any())<div class="flash bad">{{ $errors->first() }}</div>@endif
  <div class="card">
    <h2 style="font-size:22px;margin-bottom:4px">{{ $event->name }}</h2>
    <div class="muted">{{ ucfirst($event->start_date->locale('fr')->isoFormat('dddd D MMMM YYYY')) }}@if(! $event->end_date->eq($event->start_date)) — {{ $event->end_date->locale('fr')->isoFormat('dddd D MMMM YYYY') }}@endif{{ $event->venue ? ' · '.$event->venue : '' }}{{ $event->city ? ', '.$event->city : '' }}</div>
    @if($event->description)<p style="white-space:pre-line">{{ $event->description }}</p>@endif
    <div class="muted"><b style="color:var(--night)">{{ $free->count() }}</b> emplacement(s) disponible(s) sur {{ $spaces->where('blocked', false)->count() }}</div>
  </div>

  @if($event->status !== 'open')
    <div class="flash bad">Les réservations sont closes pour cet événement.</div>
  @endif

  <form method="post" action="{{ url('/salon/'.$event->public_token) }}">
    @csrf
    <input class="hp" type="text" name="website" tabindex="-1" autocomplete="off">
    <h2>1. Choisissez vos emplacements</h2>
    <div class="plan">
      <svg viewBox="0 0 {{ $event->plan_cols * $cell }} {{ $event->plan_rows * $cell }}" role="img" aria-label="Plan des stands">
        <rect x="0" y="0" width="{{ $event->plan_cols * $cell }}" height="{{ $event->plan_rows * $cell }}" fill="#F4F6FB" rx="8"/>
        @foreach($spaces as $s)
          @php($isFree = ! $s->blocked && ! isset($taken[$s->id]))
          <g class="sp {{ $isFree ? 'free' : '' }}" data-id="{{ $s->id }}">
            <title>{{ $s->code }}{{ $s->category ? ' — '.$s->category : '' }}{{ $s->area ? ' — '.rtrim(rtrim(number_format($s->area, 2, ',', ''), '0'), ',').' m²' : '' }}{{ $isFree ? ' — '.$fmt($s->price).' HT' : ' — indisponible' }}</title>
            <rect x="{{ $s->x * $cell + 1 }}" y="{{ $s->y * $cell + 1 }}" width="{{ $s->w * $cell - 2 }}" height="{{ $s->h * $cell - 2 }}" fill="{{ $isFree ? ($colors[$s->category] ?? '#1F6FEB') : '#C3CAD6' }}"/>
            <text x="{{ ($s->x + $s->w / 2) * $cell }}" y="{{ ($s->y + $s->h / 2) * $cell + 4 }}" text-anchor="middle">{{ $s->code }}</text>
          </g>
        @endforeach
      </svg>
      <div class="legend">
        @foreach($colors as $name => $col)<span><i style="background:{{ $col }}"></i>{{ $name }}</span>@endforeach
        <span><i style="background:#C3CAD6"></i>Réservé / indisponible</span>
      </div>
    </div>
    <p class="muted">Touchez un stand libre sur le plan ou cochez-le ci-dessous. Prix hors taxes.</p>
    <div class="grid2" style="margin-bottom:18px">
      @forelse($free as $s)
        <label class="opt" for="s{{ $s->id }}">
          <input type="checkbox" id="s{{ $s->id }}" name="spaces[]" value="{{ $s->id }}" @checked(in_array($s->id, old('spaces', [])))>
          <span><b>{{ $s->code }} · {{ $fmt($s->price) }}</b><small>{{ trim(($s->category ?? '').($s->area ? ' · '.rtrim(rtrim(number_format($s->area, 2, ',', ''), '0'), ',').' m²' : '').($s->zone ? ' · '.$s->zone : '')) }}{{ $s->features ? ' · '.$s->features : '' }}</small></span>
        </label>
      @empty
        <div class="muted">Tous les emplacements sont réservés : vous pouvez tout de même choisir une offre ci-dessous.</div>
      @endforelse
    </div>

    @if($packages->isNotEmpty())
      <h2>2. Offres et options</h2>
      <div class="card">
        @foreach($packages as $row)
          @php($p = $row['p'])
          <div class="pk">
            <div class="sp2">
              <b>{{ $p->name }}</b>@if($p->kind === 'sponsor')<span class="badge">Sponsor</span>@endif
              @if($row['left'] !== null)<small class="muted"> · {{ $row['left'] > 0 ? 'reste '.$row['left'] : 'complet' }}</small>@endif
              @if($p->description)<div class="muted">{{ $p->description }}</div>@endif
              @if($p->benefits)<ul class="ben">@foreach($p->benefits as $bn)<li>{{ $bn }}</li>@endforeach</ul>@endif
            </div>
            <div style="text-align:right"><b>{{ $fmt($p->price) }}</b><br>
              <input type="number" min="0" max="{{ $row['left'] ?? 50 }}" name="packages[{{ $p->id }}]" value="{{ old('packages.'.$p->id, 0) }}" @disabled($row['left'] === 0)></div>
          </div>
        @endforeach
      </div>
    @endif

    <h2>{{ $packages->isNotEmpty() ? '3' : '2' }}. Vos coordonnées</h2>
    <div class="card">
      <label for="company">Entreprise / enseigne</label>
      <input id="company" name="company" required value="{{ old('company') }}">
      <label for="name">Nom du contact</label>
      <input id="name" name="name" required value="{{ old('name') }}" autocomplete="name">
      <label for="phone">Téléphone (WhatsApp de préférence)</label>
      <input id="phone" name="phone" required value="{{ old('phone') }}" inputmode="tel" autocomplete="tel">
      <label for="email">E-mail</label>
      <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email">
      <label for="message">Message (facultatif)</label>
      <textarea id="message" name="message" rows="2">{{ old('message') }}</textarea>
      <p class="muted" style="margin:14px 0">Les emplacements choisis vous sont réservés {{ $event->option_days }} jours, le temps de confirmer.@if($event->deposit_percent > 0 && $event->deposit_percent < 100) Un acompte de {{ rtrim(rtrim(number_format($event->deposit_percent, 2, ',', ''), '0'), ',') }} % est facturé à la confirmation, le solde {{ $event->balance_days }} jours avant l'ouverture.@endif</p>
      @if($event->terms)<p class="muted" style="white-space:pre-line">{{ $event->terms }}</p>@endif
      <button class="btn" type="submit" @disabled($event->status !== 'open')>Envoyer ma demande de réservation</button>
    </div>
  </form>
<script>
  document.querySelectorAll('.sp.free').forEach(function (g) {
    var cb = document.getElementById('s' + g.dataset.id);
    var sync = function () { g.classList.toggle('sel', cb && cb.checked); };
    g.addEventListener('click', function () { if (cb) { cb.checked = !cb.checked; sync(); } });
    if (cb) { cb.addEventListener('change', sync); sync(); }
  });
</script>
@endsection
