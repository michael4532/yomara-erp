@extends('rdv.layout')
@section('title', $d->title)
@section('footer', 'Espace client')
@section('content')
  @if($ok)<div class="flash ok">{{ $ok }}</div>@endif
  @if($error)<div class="flash bad">{{ $error }}</div>@endif
  @if($errors->any())<div class="flash bad">{{ $errors->first() }}</div>@endif
  <div class="card">
    <div class="muted">{{ $d->number }} · version {{ $d->version_no }} · envoyé le {{ $d->sent_at?->format('d/m/Y') }}</div>
    <h2 style="font-size:22px;margin:6px 0">{{ $d->title }}</h2>
    @if($d->description)<p style="white-space:pre-line">{{ $d->description }}</p>@endif
    @if($d->url)<p><a class="btn ghost" href="{{ $d->url }}" target="_blank" rel="noopener">Ouvrir le lien ↗</a></p>@endif
    @if($d->file_name)<p><a class="btn ghost" href="{{ url('/livrable/'.$d->token.'/fichier') }}">Télécharger {{ $d->file_name }} ({{ number_format(($d->file_size ?? 0) / 1048576, 1, ',', ' ') }} Mo)</a></p>@endif
    @if($d->due_date && $d->status === 'sent')<p class="muted">Merci de répondre avant le {{ $d->due_date->format('d/m/Y') }}.</p>@endif
  </div>
  @if($d->status === 'approved')
    <div class="flash ok">Validé par {{ $d->decided_by }} le {{ $d->decided_at?->format('d/m/Y à H:i') }}.</div>
  @elseif($d->status === 'changes')
    <div class="card"><b>Modifications demandées</b> par {{ $d->decided_by }} le {{ $d->decided_at?->format('d/m/Y') }} :<p style="white-space:pre-line">{{ $d->client_comment }}</p><p class="muted">L'équipe prépare une nouvelle version.</p></div>
  @elseif($d->status === 'sent')
    <form class="card" method="post" action="{{ url('/livrable/'.$d->token) }}">
      @csrf
      <h2>Votre décision</h2>
      <label for="name">Votre nom</label>
      <input id="name" name="name" required value="{{ old('name') }}">
      <label for="comment">Commentaire (obligatoire pour demander des modifications)</label>
      <textarea id="comment" name="comment" rows="4">{{ old('comment') }}</textarea>
      <div style="display:flex;gap:10px;margin-top:14px;flex-wrap:wrap">
        <button class="btn" style="flex:1;background:var(--ok)" name="decision" value="approve">Je valide</button>
        <button class="btn ghost" style="flex:1" name="decision" value="changes">Je demande des modifications</button>
      </div>
    </form>
  @endif
@endsection
