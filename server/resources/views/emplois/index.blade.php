@extends('emplois.layout')
@section('title', 'Emplois')
@section('content')
@php $ct = ['CDI' => 'CDI', 'CDD' => 'CDD', 'stage' => 'Stage', 'journalier' => 'Journalier', 'consultant' => 'Prestation']; @endphp
@if($intro)<div class="card text">{{ $intro }}</div>@endif
<h2>{{ $jobs->count() ? $jobs->count().' offre'.($jobs->count() > 1 ? 's' : '').' en cours' : 'Aucune offre en ce moment' }}</h2>
@foreach($jobs as $job)
  <a class="card job" href="/emplois/{{ $company->slug }}/{{ $job->id }}">
    <h3>{{ $job->title }}</h3>
    <div class="muted">{{ \Illuminate\Support\Str::limit(strip_tags($job->description), 160) }}</div>
    <div class="tags">
      <span class="tag">{{ $ct[$job->contract_type] ?? $job->contract_type }}</span>
      @if($job->location)<span class="tag">{{ $job->location }}</span>@endif
      @if($job->department)<span class="tag">{{ $job->department }}</span>@endif
      @if($job->closes_at)<span class="tag">Jusqu'au {{ $job->closes_at->format('d/m/Y') }}</span>@endif
    </div>
  </a>
@endforeach
<div class="card">
  <b>Candidature spontanée</b>
  <p class="muted">Aucune offre ne correspond ? Envoyez-nous votre CV, nous le garderons en vue de prochains recrutements.</p>
  <a class="btn ghost" href="/emplois/{{ $company->slug }}/spontanee">Envoyer mon CV</a>
</div>
@endsection
