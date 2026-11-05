@php
  $accent = $m->color ?: '#2563EB';
  $me = request('inscrit') ? \App\Modules\Visio\Models\Participant::where('meeting_id', $m->id)->where('token', request('inscrit'))->first() : null;
  $start = $m->starts_at;
  $soon = $start && now()->gte($start->copy()->subMinutes(15)) && $m->status !== 'ended';
  $logo = $c->logo && (str_starts_with($c->logo, 'http') || str_starts_with($c->logo, 'data:image')) ? $c->logo : null;
@endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $m->title }} · Webinaire {{ $c->name }}</title>
<meta name="description" content="{{ \Illuminate\Support\Str::limit(strip_tags((string) $m->description), 160) }}">
<meta property="og:title" content="{{ $m->title }}">
<meta property="og:description" content="Webinaire gratuit — {{ $start?->locale('fr')->isoFormat('dddd D MMMM YYYY [à] HH:mm') }}">
<style>
  :root { --accent: {{ $accent }}; --night:#0B1220; --muted:#5B6B85; --line:#E3E8F1; --bg:#F5F7FB; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:"Inter",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; background:var(--bg); color:var(--night); }
  .hero { background: radial-gradient(1200px 500px at 10% -10%, color-mix(in srgb, var(--accent) 55%, #fff 0%), transparent 60%), linear-gradient(135deg, #0B1220, color-mix(in srgb, var(--accent) 70%, #0B1220)); color:#fff; padding:28px 18px 120px; }
  .in { max-width:1040px; margin:0 auto; }
  .brand { display:flex; gap:12px; align-items:center; font-weight:700; }
  .logo { width:42px; height:42px; border-radius:12px; background:#fff; color:var(--night); display:flex; align-items:center; justify-content:center; font-weight:800; overflow:hidden; }
  .logo img { width:100%; height:100%; object-fit:contain; }
  .tag { display:inline-flex; gap:8px; align-items:center; background:rgba(255,255,255,.14); border:1px solid rgba(255,255,255,.25); padding:6px 12px; border-radius:30px; font-size:13px; margin-top:34px; }
  .tag i { width:8px; height:8px; border-radius:50%; background:#34D399; display:inline-block; box-shadow:0 0 0 4px rgba(52,211,153,.25); }
  h1 { font-size:clamp(28px,4.4vw,46px); line-height:1.1; margin:14px 0 10px; max-width:760px; letter-spacing:-.02em; }
  .when { font-size:18px; opacity:.92; }
  .grid { display:grid; grid-template-columns: 1.35fr 1fr; gap:22px; margin-top:-90px; padding:0 18px 40px; }
  @media (max-width: 860px) { .grid { grid-template-columns:1fr; } }
  .card { background:#fff; border:1px solid var(--line); border-radius:18px; padding:22px; box-shadow:0 10px 30px rgba(15,23,42,.06); }
  .card h2 { margin:0 0 10px; font-size:18px; }
  .desc { color:#334155; line-height:1.65; white-space:pre-line; }
  .count { display:flex; gap:10px; margin:6px 0 18px; }
  .count div { background:#F1F5F9; border-radius:12px; padding:10px 8px; min-width:64px; text-align:center; }
  .count b { display:block; font-size:24px; } .count small { color:var(--muted); font-size:11px; text-transform:uppercase; letter-spacing:.05em; }
  label { display:block; font-weight:600; font-size:13px; margin:12px 0 6px; color:#334155; }
  input { width:100%; padding:12px 13px; border:1px solid var(--line); border-radius:11px; font:inherit; background:#fff; }
  input:focus { outline:2px solid color-mix(in srgb, var(--accent) 35%, transparent); border-color:var(--accent); }
  .btn { display:block; width:100%; margin-top:16px; padding:14px; border-radius:12px; border:0; background:var(--accent); color:#fff; font-size:16px; font-weight:800; cursor:pointer; text-align:center; text-decoration:none; }
  .btn.ghost { background:#fff; color:var(--night); border:1px solid var(--line); font-weight:700; }
  .speakers { display:flex; flex-wrap:wrap; gap:12px; margin-top:6px; }
  .sp { display:flex; gap:10px; align-items:center; background:#F8FAFC; border:1px solid var(--line); border-radius:14px; padding:8px 12px 8px 8px; }
  .av { width:38px; height:38px; border-radius:50%; background:var(--accent); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:800; }
  .muted { color:var(--muted); font-size:13px; }
  .err { background:#FDECEC; color:#B42318; padding:10px 12px; border-radius:10px; font-weight:600; margin-bottom:10px; }
  .ok { background:#E8F7EF; color:#0B6B3A; padding:14px; border-radius:12px; font-weight:600; }
  .hp { position:absolute; left:-9999px; }
  video { width:100%; border-radius:14px; background:#000; }
  footer { text-align:center; color:var(--muted); font-size:12px; padding:10px 0 30px; }
</style>
</head>
<body>
<div class="hero"><div class="in">
  <div class="brand"><div class="logo">@if($logo)<img src="{{ $logo }}" alt="">@else{{ mb_substr($c->name, 0, 1) }}@endif</div>{{ $c->name }}</div>
  <div class="tag"><i></i> {{ $m->status === 'live' ? 'En direct maintenant' : ($m->status === 'ended' ? 'Webinaire terminé' : 'Webinaire en ligne · gratuit') }}</div>
  <h1>{{ $m->title }}</h1>
  <div class="when">{{ $start ? ucfirst($start->locale('fr')->isoFormat('dddd D MMMM YYYY · HH:mm')) : '' }} · {{ $m->duration_minutes }} min</div>
</div></div>

<div class="in grid">
  <div class="card">
    @if(!empty($play))
      <h2>Rediffusion</h2>
      <video controls playsinline preload="metadata" src="{{ url('/webinaire/'.$m->slug.'/video') }}"></video>
      <p class="muted">Enregistré le {{ $m->started_at?->format('d/m/Y') }}.</p>
    @endif
    <h2>À propos</h2>
    <div class="desc">{{ $m->description ?: 'Rejoignez-nous en direct pour ce webinaire. Posez vos questions en direct, participez aux sondages et repartez avec des réponses concrètes.' }}</div>
    @if($speakers->count())
      <h2 style="margin-top:22px">Intervenants</h2>
      <div class="speakers">
        @foreach($speakers as $s)
          <div class="sp"><div class="av">{{ mb_strtoupper(mb_substr($s->name, 0, 1)) }}</div><div><b>{{ $s->name }}</b>@if($s->organization)<div class="muted">{{ $s->organization }}</div>@endif</div></div>
        @endforeach
      </div>
    @endif
  </div>

  <div class="card" id="inscription">
    @if(! $me && request('deja'))
      <div class="ok">Cette adresse est déjà inscrite : votre lien personnel vous est renvoyé par e-mail.</div>
    @endif
    @if($me)
      <div class="ok">✓ Inscription confirmée, {{ $me->name }}. Votre lien personnel vous a aussi été envoyé par e-mail.</div>
      @if($soon || $m->status === 'live')
        <a class="btn" href="{{ $m->roomUrl($me->token) }}">Rejoindre le webinaire</a>
      @else
        <p class="muted" style="margin-top:14px">Le bouton d'accès s'active 15 minutes avant le début.</p>
        <a class="btn" href="{{ $m->roomUrl($me->token) }}">Mon lien d'accès</a>
      @endif
      <a class="btn ghost" href="{{ url('/webinaire/'.$m->slug.'/calendrier.ics?t='.$me->token) }}">Ajouter à mon calendrier</a>
    @elseif($m->status === 'ended')
      <h2>Webinaire terminé</h2>
      @if($replay && empty($play))
        <p class="muted">La rediffusion est disponible.</p>
        <a class="btn" href="{{ url('/webinaire/'.$m->slug.'/replay') }}">Voir la rediffusion</a>
      @else
        <p class="muted">Merci à tous les participants.</p>
      @endif
    @else
      <h2>Réservez votre place</h2>
      @if($start && $start->isFuture())
        <div class="count" id="cd" data-t="{{ $start->getTimestamp() }}"><div><b id="cd-d">0</b><small>jours</small></div><div><b id="cd-h">0</b><small>heures</small></div><div><b id="cd-m">0</b><small>min</small></div><div><b id="cd-s">0</b><small>s</small></div></div>
      @endif
      @if($errors->any())<div class="err">{{ $errors->first() }}</div>@endif
      @if($full)
        <div class="err">Ce webinaire est complet.</div>
      @elseif(!$m->registration_open)
        <div class="err">Les inscriptions sont closes.</div>
      @else
        <form method="post" action="{{ url('/webinaire/'.$m->slug) }}">
          @csrf
          <input type="hidden" name="source" value="{{ $source ?? '' }}">
          <input class="hp" name="website" tabindex="-1" autocomplete="off">
          <label>Nom complet</label><input name="name" required maxlength="120" value="{{ old('name') }}" autocomplete="name">
          <label>E-mail</label><input name="email" type="email" required maxlength="190" value="{{ old('email') }}" autocomplete="email">
          <label>Téléphone (WhatsApp)</label><input name="phone" maxlength="40" value="{{ old('phone') }}" autocomplete="tel">
          <label>Entreprise / organisation</label><input name="organization" maxlength="120" value="{{ old('organization') }}" autocomplete="organization">
          <button class="btn" type="submit">Je m'inscris gratuitement</button>
        </form>
        <p class="muted" style="margin-top:12px">{{ $registered }} inscrit{{ $registered > 1 ? 's' : '' }}@if($m->capacity) · {{ max(0, $m->capacity - $registered) }} places restantes @endif. Aucune installation : le webinaire s'ouvre dans votre navigateur.</p>
      @endif
    @endif
  </div>
</div>
<footer>{{ $c->name }} · Webinaire propulsé par Yomara ERP</footer>
<script>
(function(){ var el=document.getElementById('cd'); if(!el) return; var t=+el.dataset.t*1000;
  function z(){ var s=Math.max(0,Math.floor((t-Date.now())/1000)); document.getElementById('cd-d').textContent=Math.floor(s/86400); document.getElementById('cd-h').textContent=Math.floor(s%86400/3600);
    document.getElementById('cd-m').textContent=Math.floor(s%3600/60); document.getElementById('cd-s').textContent=s%60; }
  z(); setInterval(z,1000); })();
</script>
</body>
</html>
