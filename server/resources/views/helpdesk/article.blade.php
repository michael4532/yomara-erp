@extends('rdv.layout')
@section('title', $a->title)
@section('footer', 'Centre d\'aide')
@section('robots', 'index, follow')
@section('content')
  @include('helpdesk.style')
  <p><a href="{{ url('/aide/'.$company->slug) }}">← Centre d'aide</a></p>
  @if($ok)<div class="flash ok">{{ $ok }}</div>@endif
  <div class="card">
    @if($a->section)<div class="muted">{{ $a->section }}</div>@endif
    <h2 style="font-size:23px;margin:4px 0 6px">{{ $a->title }}</h2>
    <div class="muted">Mis à jour le {{ $a->updated_at->format('d/m/Y') }}</div>
    <div class="body">{!! \App\Modules\Helpdesk\HelpdeskService::render($a->body) !!}</div>
  </div>
  <div class="card">
    @if($voted)
      <p class="muted" style="margin:0">Merci pour votre avis.</p>
    @else
      <form method="post" action="{{ url('/aide/'.$company->slug.'/a/'.$a->slug.'/vote') }}" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        @csrf
        <b class="sp" style="flex:1">Cet article vous a-t-il aidé ?</b>
        <button class="btn ghost" style="width:auto;padding:10px 18px" name="helpful" value="yes">Oui</button>
        <button class="btn ghost" style="width:auto;padding:10px 18px" name="helpful" value="no">Non</button>
      </form>
    @endif
  </div>
  @if($related->isNotEmpty())
    <div class="card">
      <h2>Articles liés</h2>
      @foreach($related as $r)<a class="art" href="{{ url('/aide/'.$company->slug.'/a/'.$r->slug) }}"><b>{{ $r->title }}</b></a>@endforeach
    </div>
  @endif
  @if($settings['contact_form'])
    <a class="btn ghost" href="{{ url('/aide/'.$company->slug.'/contact') }}?q={{ urlencode($a->title) }}">Toujours besoin d'aide ? Contacter le support</a>
  @endif
@endsection
