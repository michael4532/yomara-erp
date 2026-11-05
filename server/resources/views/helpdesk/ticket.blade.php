@extends('rdv.layout')
@section('title', 'Demande '.$t->number)
@section('footer', 'Service client')
@section('content')
  @include('helpdesk.style')
  @if($ok)<div class="flash ok">{{ $ok }}</div>@endif
  @if($error)<div class="flash bad">{{ $error }}</div>@endif
  @if($errors->any())<div class="flash bad">{{ $errors->first() }}</div>@endif
  @php
    $label = ['new' => 'Reçue', 'open' => 'En cours de traitement', 'pending' => 'Nous attendons votre réponse', 'resolved' => 'Résolue', 'closed' => 'Fermée'][$t->status] ?? $t->status;
    $cls = in_array($t->status, ['resolved', 'closed']) ? 'p-confirmed' : ($t->status === 'pending' ? 'p-pending' : 'p-pending');
  @endphp
  <div class="card">
    <div class="row" style="border:0;align-items:center"><span>{{ $t->number }} · ouverte le {{ $t->created_at->format('d/m/Y à H:i') }}</span><b class="pill {{ $cls }}">{{ $label }}</b></div>
    <h2 style="font-size:21px;margin:6px 0 0">{{ $t->subject }}</h2>
  </div>
  <div class="card">
    <div class="msg customer"><small>{{ $t->contact_name ?? 'Vous' }} · {{ $t->created_at->format('d/m H:i') }}</small>{{ $t->description }}</div>
    @foreach($messages as $m)
      @if($m->author_type !== 'system')
        <div class="msg {{ $m->author_type === 'customer' ? 'customer' : 'agent' }}"><small>{{ $m->author_type === 'customer' ? ($m->author_name ?? 'Vous') : ($m->author_name.' · '.$company->name) }} · {{ $m->created_at->format('d/m H:i') }}</small>{{ $m->body }}</div>
      @endif
    @endforeach
    @if($files->isNotEmpty())
      <div style="margin-top:6px">
        <b style="font-size:14px">Documents</b>
        @foreach($files as $f)
          <a class="art" href="{{ url('/support/'.$t->token.'/fichier/'.$f->id) }}">📎 {{ $f->name }} <span class="muted">· {{ number_format($f->size / 1024, 0, ',', ' ') }} Ko{{ $f->uploaded_by ? ' · '.$f->uploaded_by : '' }}</span></a>
        @endforeach
      </div>
    @endif
    @if(in_array($t->status, ['new', 'open']) && ! $messages->where('author_type', 'agent')->count() && $t->first_response_due)
      <p class="muted">Réponse prévue au plus tard le {{ $t->first_response_due->format('d/m/Y à H:i') }}.</p>
    @endif
  </div>
  @if(in_array($t->status, ['resolved', 'closed']) && $settings['csat'])
    <form class="card" method="post" action="{{ url('/support/'.$t->token.'/avis') }}">
      @csrf
      @if($t->rating)
        <p style="margin:0">Votre avis : <b>{{ str_repeat('★', $t->rating) }}{{ str_repeat('☆', 5 - $t->rating) }}</b>. Merci !</p>
      @else
        <h2>Êtes-vous satisfait de notre réponse ?</h2>
        <div class="stars">
          @for($i = 5; $i >= 1; $i--)<input type="radio" id="s{{ $i }}" name="rating" value="{{ $i }}" required><label for="s{{ $i }}" title="{{ $i }}/5">★</label>@endfor
        </div>
        <label for="comment">Un commentaire (facultatif)</label>
        <textarea id="comment" name="comment" rows="2"></textarea>
        <button class="btn" style="margin-top:12px">Envoyer mon avis</button>
      @endif
    </form>
  @endif
  <form class="card" method="post" action="{{ url('/support/'.$t->token) }}" enctype="multipart/form-data">
    @csrf
    <h2>{{ in_array($t->status, ['resolved', 'closed']) ? 'Le problème persiste ? Répondez pour rouvrir la demande' : 'Répondre' }}</h2>
    <textarea name="body" rows="4" required placeholder="Votre message"></textarea>
    <label for="file">Joindre un fichier (capture d'écran, document — 15 Mo au plus)</label>
    <input id="file" type="file" name="file">
    <input class="hp" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
    <button class="btn" style="margin-top:12px">Envoyer</button>
  </form>
@endsection
