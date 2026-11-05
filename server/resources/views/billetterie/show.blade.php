@extends('rdv.layout')
@section('title', 'Billetterie — '.$event->name)
@section('footer', 'Billetterie en ligne')
@section('content')
<style>.tk{display:flex;gap:12px;align-items:center;padding:14px;border:1px solid var(--line);border-radius:14px;margin-bottom:10px;background:#fff}.tk .sp{flex:1}.tk input{width:76px;text-align:center}.tk .price{font-weight:800;font-size:17px;white-space:nowrap}.bar{width:8px;align-self:stretch;border-radius:6px;background:var(--blue)}</style>
  @if($error)<div class="flash bad">{{ $error }}</div>@endif
  @if($errors->any())<div class="flash bad">{{ $errors->first() }}</div>@endif
  <div class="card">
    <h2 style="font-size:22px;margin-bottom:4px">{{ $event->name }}</h2>
    <div class="muted">{{ ucfirst($event->start_date->locale('fr')->isoFormat('dddd D MMMM YYYY')) }}@if(! $event->end_date->eq($event->start_date)) — {{ $event->end_date->locale('fr')->isoFormat('dddd D MMMM YYYY') }}@endif{{ $event->venue ? ' · '.$event->venue : '' }}{{ $event->city ? ', '.$event->city : '' }}</div>
    @if($event->description)<p style="white-space:pre-line">{{ $event->description }}</p>@endif
  </div>
  <form method="post" action="{{ url('/billetterie/'.$event->public_token) }}">
    @csrf
    <input class="hp" type="text" name="website" tabindex="-1" autocomplete="off">
    <h2>1. Vos billets</h2>
    @forelse($types as $row)
      @php($t = $row['t'])
      <div class="tk">
        <div class="bar" style="background:{{ $t->color ?: '#1F6FEB' }}"></div>
        <div class="sp"><b>{{ $t->name }}</b>
          <div class="muted">{{ $t->valid_from && $t->valid_to ? ($t->valid_from->eq($t->valid_to) ? 'Le '.$t->valid_from->format('d/m/Y') : 'Du '.$t->valid_from->format('d/m/Y').' au '.$t->valid_to->format('d/m/Y')) : '' }}{{ $t->description ? ' · '.$t->description : '' }}</div>
          @if(! $row['on'])<div class="muted" style="color:var(--bad)">Vente fermée</div>@elseif($row['left'] !== null && $row['left'] < 30)<div class="muted" style="color:#9A6B00">{{ $row['left'] > 0 ? 'Plus que '.$row['left'].' place(s)' : 'Complet' }}</div>@endif
        </div>
        <div class="price">{{ $t->price > 0 ? number_format($t->price, 0, ',', ' ').' '.$cur : 'Gratuit' }}</div>
        <input type="number" name="qty[{{ $t->id }}]" min="0" max="{{ min($t->max_per_order, $row['left'] ?? 50) }}" value="{{ old('qty.'.$t->id, 0) }}" @disabled(! $row['on'] || $row['left'] === 0)>
      </div>
    @empty
      <div class="card muted">Aucun billet en vente pour le moment.</div>
    @endforelse
    <h2>2. Vos coordonnées</h2>
    <div class="card">
      <label for="name">Nom et prénom</label><input id="name" name="name" required value="{{ old('name') }}" autocomplete="name">
      <label for="phone">Téléphone (WhatsApp de préférence){{ ($form['ask_email'] ?? 'required') === 'required' ? ' (facultatif)' : '' }}</label><input id="phone" name="phone" @if(($form['ask_email'] ?? 'required') !== 'required') required @endif value="{{ old('phone') }}" inputmode="tel" autocomplete="tel">
      <label for="email">E-mail (pour recevoir vos billets){{ $form['ask_email'] === 'required' ? '' : ' — facultatif' }}</label><input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" @required($form['ask_email'] === 'required')>
      @if($form['ask_company'])<label for="company">Entreprise / organisation (facultatif)</label><input id="company" name="company" value="{{ old('company') }}" autocomplete="organization">@endif
      @if($form['ask_job'])<label for="job">Fonction (facultatif)</label><input id="job" name="job" value="{{ old('job') }}" autocomplete="organization-title">@endif
      @foreach($form['questions'] as $q)
        @php($n = 'q['.$q['id'].']')
        @if($q['type'] === 'checkbox')
          <label style="display:flex;gap:8px;align-items:flex-start;font-weight:500;margin-top:12px"><input type="checkbox" name="{{ $n }}" value="1" style="width:auto;margin-top:3px" @checked(old('q.'.$q['id'])) @required($q['required'])> {{ $q['label'] }}</label>
        @else
          <label for="q_{{ $q['id'] }}">{{ $q['label'] }}{{ $q['required'] ? '' : ' (facultatif)' }}</label>
          @if($q['type'] === 'select')
            <select id="q_{{ $q['id'] }}" name="{{ $n }}" @required($q['required'])><option value="">—</option>@foreach($q['options'] as $o)<option @selected(old('q.'.$q['id']) === $o)>{{ $o }}</option>@endforeach</select>
          @elseif($q['type'] === 'textarea')
            <textarea id="q_{{ $q['id'] }}" name="{{ $n }}" rows="3" @required($q['required'])>{{ old('q.'.$q['id']) }}</textarea>
          @else
            <input id="q_{{ $q['id'] }}" name="{{ $n }}" value="{{ old('q.'.$q['id']) }}" type="{{ $q['type'] === 'number' ? 'number' : ($q['type'] === 'phone' ? 'tel' : 'text') }}" @required($q['required'])>
          @endif
        @endif
      @endforeach
      <label style="display:flex;gap:8px;align-items:flex-start;font-weight:500;margin-top:14px"><input type="checkbox" name="consent" value="1" style="width:auto;margin-top:3px" @checked(old('consent'))> {{ strtr($form['consent_text'], ['{societe}' => $company->name]) }}</label>
      <input type="hidden" name="source" value="{{ old('source', $source) }}">
      <p class="muted" style="margin:14px 0">Entrée gratuite : votre billet avec QR code s'affiche aussitôt et vous est envoyé par e-mail. Entrée payante : après le paiement (Mobile Money ou carte). Prix TTC.</p>
      <button class="btn" type="submit" @disabled($types->isEmpty() || $event->status !== 'open')>{{ $types->every(fn ($r) => $r['t']->price <= 0) ? 'Je m\'inscris' : 'Commander mes billets' }}</button>
    </div>
  </form>
@endsection
