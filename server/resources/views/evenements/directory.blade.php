@extends('rdv.layout')
@section('title', 'Exposants · '.$event->name)
@section('robots', 'index')
@section('footer', 'Annuaire des exposants')
@section('content')
  <style>
    .ex { display:flex; gap:14px; align-items:flex-start; } .ex .logo { width:60px; height:60px; border:1px solid var(--line); }
    .tier { display:inline-block; background:var(--yellow); color:var(--night); font-weight:800; font-size:12px; padding:3px 10px; border-radius:20px; margin-left:6px; }
    .links { display:flex; gap:10px; flex-wrap:wrap; margin-top:8px; font-size:14px; } .offer { background:#FFF6DB; border-radius:10px; padding:8px 10px; margin-top:8px; font-size:14px; }
  </style>
  <div class="card">
    <h2 style="margin-bottom:4px">{{ $event->name }}</h2>
    <div class="muted">{{ $event->start_date->format('d/m/Y') }}{{ $event->venue ? ' · '.$event->venue : '' }} · {{ count($dir['sponsors']) + count($dir['exhibitors']) }} participant(s)</div>
    @if($ticketing)<a class="btn" style="margin-top:12px" href="{{ $ticketing }}">S'inscrire à l'événement</a>@endif
  </div>
  @foreach(['sponsors' => 'Partenaires et sponsors', 'exhibitors' => 'Exposants'] as $k => $title)
    @if(count($dir[$k]))
      <h2>{{ $title }}</h2>
      @foreach($dir[$k] as $r)
        @php $p = $r['profile']; @endphp
        <div class="card ex">
          <div class="logo">@if($r['logo'])<img src="{{ $r['logo'] }}" alt="">@else{{ mb_substr($r['booking']->exhibitor_name ?: '?', 0, 1) }}@endif</div>
          <div style="flex:1">
            <b style="font-size:16px">{{ $r['booking']->exhibitor_name }}</b>@if($r['tier'])<span class="tier">{{ $r['tier'] }}</span>@endif
            <div class="muted">{{ collect([$p['sector'] ?? null, $r['stands'] ? 'Stand '.$r['stands'] : null])->filter()->implode(' · ') }}</div>
            @if(! empty($p['tagline']))<div style="margin-top:6px;font-weight:600">{{ $p['tagline'] }}</div>@endif
            @if(! empty($p['description']))<div style="margin-top:6px;white-space:pre-line">{{ $p['description'] }}</div>@endif
            @if(! empty($p['products']))<div class="muted" style="margin-top:6px;white-space:pre-line">{{ $p['products'] }}</div>@endif
            @if(! empty($p['offer']))<div class="offer">🎁 {{ $p['offer'] }}</div>@endif
            <div class="links">
              @foreach(['website' => 'Site web', 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'tiktok' => 'TikTok', 'youtube' => 'YouTube'] as $lk => $ll)
                @if(! empty($p[$lk]))<a href="{{ $p[$lk] }}" target="_blank" rel="noopener nofollow">{{ $ll }}</a>@endif
              @endforeach
              @if(! empty($p['email']))<a href="mailto:{{ $p['email'] }}">{{ $p['email'] }}</a>@endif
              @if(! empty($p['phone']))<a href="tel:{{ preg_replace('/[^\d+]/', '', $p['phone']) }}">{{ $p['phone'] }}</a>@endif
              @if(! empty($p['whatsapp']))<a href="https://wa.me/{{ preg_replace('/\D/', '', $p['whatsapp']) }}" target="_blank" rel="noopener">WhatsApp</a>@endif
            </div>
          </div>
        </div>
      @endforeach
    @endif
  @endforeach
  @if(! count($dir['sponsors']) && ! count($dir['exhibitors']))<div class="card muted">La liste des exposants sera bientôt disponible.</div>@endif
@endsection
