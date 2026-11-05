@extends('rdv.layout')
@section('title', 'Centre d\'aide')
@section('footer', 'Centre d\'aide')
@section('robots', 'index, follow')
@section('content')
  @include('helpdesk.style')
  @if($ok)<div class="flash ok">{{ $ok }}</div>@endif
  <div class="card">
    <h2 style="font-size:22px">Comment pouvons-nous vous aider ?</h2>
    @if($settings['kb_public'])
      <form class="search" method="get" action="{{ url('/aide/'.$company->slug) }}">
        <input name="q" value="{{ $q }}" placeholder="Décrivez votre question (ex. facture, mot de passe…)" aria-label="Rechercher">
        @if($portal)<input type="hidden" name="portal" value="{{ $portal }}">@endif
        <button class="btn">Rechercher</button>
      </form>
    @endif
  </div>
  @if($q !== '')
    <div class="card">
      <h2>{{ $articles->count() }} résultat(s) pour « {{ $q }} »</h2>
      @forelse($articles as $a)
        <a class="art" href="{{ url('/aide/'.$company->slug.'/a/'.$a->slug) }}"><b>{{ $a->title }}</b><div class="muted">{{ $a->excerpt() }}</div></a>
      @empty
        <p class="muted">Aucun article ne correspond. Posez-nous la question directement.</p>
      @endforelse
    </div>
  @else
    @if($popular->isNotEmpty())
      <div class="card">
        <h2>Questions fréquentes</h2>
        @foreach($popular as $a)
          <a class="art" href="{{ url('/aide/'.$company->slug.'/a/'.$a->slug) }}"><b>{{ $a->title }}</b></a>
        @endforeach
      </div>
    @endif
    @foreach($sections as $name => $rows)
      <div class="sec">{{ $name }}</div>
      <div class="card">
        @foreach($rows as $a)
          <a class="art" href="{{ url('/aide/'.$company->slug.'/a/'.$a->slug) }}"><b>{{ $a->title }}</b><div class="muted">{{ $a->excerpt(120) }}</div></a>
        @endforeach
      </div>
    @endforeach
  @endif
  @if($settings['contact_form'])
    <div class="card" style="text-align:center">
      <p style="margin-top:0"><b>Vous ne trouvez pas la réponse ?</b></p>
      <a class="btn" href="{{ url('/aide/'.$company->slug.'/contact').'?'.http_build_query(array_filter(['q' => $q, 'portal' => $portal])) }}">Contacter le support</a>
    </div>
  @endif
@endsection
