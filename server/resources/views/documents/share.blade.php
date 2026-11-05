@extends('signature.layout')
@section('title', $s->file_id ? ($files->first()->name ?? 'Document partagé') : ($folder->name ?? 'Dossier partagé'))
@section('subtitle', 'Document partagé avec vous')
@section('footer', 'Partage de documents')
@section('style')
  .files { list-style:none; margin:0; padding:0; } .files li { display:flex; gap:12px; align-items:center; padding:12px 0; border-top:1px solid var(--line); }
  .files li:first-child { border-top:0; } .ico { width:40px; height:40px; border-radius:10px; background:#EEF3FF; color:var(--blue); display:flex; align-items:center; justify-content:center; font-weight:800; font-size:11px; flex:none; text-transform:uppercase; }
  .files .n { flex:1; min-width:0; } .files .n b { display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
@endsection
@section('content')
@if(! $s->active())
  <div class="card"><p style="margin:0"><span class="pill bad">Lien expiré</span> Ce lien de partage n'est plus valide. Demandez un nouveau lien à {{ $company->name }}.</p></div>
@elseif($locked)
  <div class="card">
    <h2>Document protégé</h2>
    <p class="muted">Saisissez le mot de passe communiqué par {{ $company->name }}.</p>
    <form method="post" action="/d/{{ $token }}">@csrf
      <input type="text" name="password" autocomplete="off" style="-webkit-text-security:disc" required>
      <button class="btn" type="submit">Ouvrir</button>
    </form>
  </div>
@else
  <div class="card">
    <h2>{{ $s->file_id ? 'Document' : ($folder->name ?? 'Dossier') }}</h2>
    @if($files->isEmpty())<p class="muted" style="margin:0">Aucun fichier disponible.</p>@endif
    <ul class="files">
      @foreach($files as $f)
        <li><div class="ico">{{ $f->extension ?: 'doc' }}</div>
          <div class="n"><b>{{ $f->name }}</b><span class="muted">{{ $f->size >= 1048576 ? number_format($f->size / 1048576, 1, ',', ' ').' Mo' : max(1, round($f->size / 1024)).' Ko' }} · {{ $f->updated_at->format('d/m/Y') }}</span></div>
          <a class="btn small" href="/d/{{ $token }}/telecharger/{{ $f->id }}">Télécharger</a></li>
      @endforeach
    </ul>
    @if($s->expires_at)<p class="muted" style="margin:12px 0 0">Lien valable jusqu'au {{ $s->expires_at->format('d/m/Y') }}.</p>@endif
  </div>
@endif
@endsection
