@extends('rdv.layout')
@section('title', 'Contacter le support')
@section('footer', 'Centre d\'aide')
@section('content')
  @include('helpdesk.style')
  <p><a href="{{ url('/aide/'.$company->slug).($portal ? '?portal='.$portal : '') }}">← Centre d'aide</a></p>
  @if($error)<div class="flash bad">{{ $error }}</div>@endif
  @if($errors->any())<div class="flash bad">{{ $errors->first() }}</div>@endif
  @if($suggest->isNotEmpty())
    <div class="card">
      <h2>Ces articles répondent peut-être à votre question</h2>
      @foreach($suggest as $a)<a class="art" href="{{ url('/aide/'.$company->slug.'/a/'.$a->slug) }}" target="_blank"><b>{{ $a->title }}</b><div class="muted">{{ $a->excerpt(110) }}</div></a>@endforeach
    </div>
  @endif
  <form class="card" method="post" action="{{ url('/aide/'.$company->slug.'/contact') }}">
    @csrf
    @if($portal)<input type="hidden" name="portal" value="{{ $portal }}">@endif
    <h2>Votre demande</h2>
    @if($partner)
      <p class="muted">Demande au nom de <b>{{ $partner->name }}</b> (espace client).</p>
    @else
      <label for="name">Votre nom</label>
      <input id="name" name="name" required value="{{ old('name') }}" autocomplete="name">
      <div class="two">
        <div><label for="email">E-mail</label><input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email"></div>
        <div><label for="phone">Téléphone / WhatsApp</label><input id="phone" name="phone" value="{{ old('phone') }}" autocomplete="tel"></div>
      </div>
    @endif
    @if($categories->isNotEmpty())
      <label for="category_id">Sujet</label>
      <select id="category_id" name="category_id">
        <option value="">—</option>
        @foreach($categories as $c)<option value="{{ $c->id }}" @selected(old('category_id') === $c->id)>{{ $c->name }}</option>@endforeach
      </select>
    @endif
    <label for="subject">Objet</label>
    <input id="subject" name="subject" required maxlength="200" value="{{ old('subject', $q) }}">
    <label for="description">Décrivez votre demande</label>
    <textarea id="description" name="description" rows="6" required>{{ old('description') }}</textarea>
    <label><input type="checkbox" name="priority" value="high" style="width:auto;margin-right:8px" @checked(old('priority'))>C'est urgent (activité bloquée)</label>
    <input class="hp" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
    <button class="btn" style="margin-top:14px">Envoyer la demande</button>
  </form>
@endsection
