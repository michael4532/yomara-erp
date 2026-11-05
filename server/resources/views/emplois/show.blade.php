@extends('emplois.layout')
@section('title', $job?->title ?? 'Candidature spontanée')
@section('content')
@php $ct = ['CDI' => 'CDI', 'CDD' => 'CDD', 'stage' => 'Stage', 'journalier' => 'Journalier', 'consultant' => 'Prestation']; @endphp
<p><a href="/emplois/{{ $company->slug }}">← Toutes les offres</a></p>
@if($job)
<div class="card">
  <h2 style="margin-bottom:4px">{{ $job->title }}</h2>
  <div class="tags">
    <span class="tag">{{ $ct[$job->contract_type] ?? $job->contract_type }}</span>
    @if($job->location)<span class="tag">{{ $job->location }}</span>@endif
    @if($job->salary)<span class="tag">{{ $job->salary }}</span>@endif
    @if($job->positions > 1)<span class="tag">{{ $job->positions }} postes</span>@endif
  </div>
  @if($job->description)<h3>Le poste</h3><div class="text">{{ $job->description }}</div>@endif
  @if($job->requirements)<h3>Profil recherché</h3><div class="text">{{ $job->requirements }}</div>@endif
</div>
@endif
<form class="card" method="post" enctype="multipart/form-data" action="/emplois/{{ $company->slug }}/{{ $job?->id ?? 'spontanee' }}">
  @csrf
  <h2>{{ $job ? 'Postuler' : 'Candidature spontanée' }}</h2>
  <input type="hidden" name="t" value="{{ time() }}">
  <input class="hp" name="website" tabindex="-1" autocomplete="off">
  <div class="two">
    <div><label>Prénoms *</label><input name="first_name" value="{{ old('first_name') }}" required>@error('first_name')<div class="err">{{ $message }}</div>@enderror</div>
    <div><label>Nom *</label><input name="last_name" value="{{ old('last_name') }}" required>@error('last_name')<div class="err">{{ $message }}</div>@enderror</div>
  </div>
  <div class="two">
    <div><label>Téléphone (WhatsApp)</label><input name="phone" value="{{ old('phone') }}" inputmode="tel">@error('phone')<div class="err">{{ $message }}</div>@enderror</div>
    <div><label>E-mail</label><input name="email" type="email" value="{{ old('email') }}">@error('email')<div class="err">{{ $message }}</div>@enderror</div>
  </div>
  <div class="two">
    <div><label>Ville / commune</label><input name="city" value="{{ old('city') }}"></div>
    <div><label>Disponible à partir du</label><input name="available_from" type="date" value="{{ old('available_from') }}"></div>
  </div>
  <label>Prétentions salariales (facultatif)</label><input name="expected_salary" value="{{ old('expected_salary') }}">
  <label>Votre CV * <span class="muted">(PDF, Word ou photo, 6 Mo maximum)</span></label>
  <input type="file" name="cv" accept=".pdf,.doc,.docx,.odt,.jpg,.jpeg,.png" required>@error('cv')<div class="err">{{ $message }}</div>@enderror
  <label>Quelques mots sur vous (facultatif)</label><textarea name="cover_letter" rows="5">{{ old('cover_letter') }}</textarea>
  <label class="check"><input type="checkbox" name="consent" value="1" {{ old('consent') ? 'checked' : '' }}> <span>J'accepte que {{ $company->name }} conserve mes informations et mon CV pour étudier ma candidature (au plus deux ans, puis effacement).</span></label>
  @error('consent')<div class="err">{{ $message }}</div>@enderror
  <button class="btn">Envoyer ma candidature</button>
</form>
@endsection
