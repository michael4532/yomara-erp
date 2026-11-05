@extends('portail.layout')
@section('title', 'Support')
@section('content')
  <h1>Support</h1>
  <div class="card">
    <div class="row" style="border:0;flex-wrap:wrap;gap:10px">
      <p class="muted sp" style="margin:0">Une question, une panne ? Ouvrez une demande : vous suivez la réponse ici et par e-mail.</p>
      <a class="btn" href="{{ $newUrl }}">Nouvelle demande</a>
      <a class="btn ghost" href="{{ $helpUrl }}" target="_blank">Centre d'aide</a>
    </div>
  </div>
  <div class="card">
    <h2>Vos demandes</h2>
    @if($rows->isEmpty())
      <p class="muted">Aucune demande pour le moment.</p>
    @else
      <div class="list">
        @foreach($rows as $t)
          <a class="item" href="{{ $t->publicUrl() }}">
            <div class="sp"><strong>{{ $t->subject }}</strong><div class="muted">{{ $t->number }} · ouverte le {{ $t->created_at->format('d/m/Y') }}@if($t->resolved_at) · résolue le {{ $t->resolved_at->format('d/m/Y') }}@endif</div></div>
            <span class="pill {{ in_array($t->status, ['resolved', 'closed']) ? 'ok' : ($t->status === 'pending' ? 'wait' : 'info') }}">{{ ['new' => 'Reçue', 'open' => 'En cours', 'pending' => 'Réponse attendue de votre part', 'resolved' => 'Résolue', 'closed' => 'Fermée'][$t->status] ?? $t->status }}</span>
          </a>
        @endforeach
      </div>
    @endif
  </div>
@endsection
