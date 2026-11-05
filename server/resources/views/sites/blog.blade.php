@extends('sites.layout')
@php extract(\App\Modules\Sites\SiteView::vars(get_defined_vars())); @endphp
@section('title', 'Blog — '.$name)
@section('main')
<section class="page-head"><div class="wrap"><div class="crumb"><a href="{{ $home }}">Accueil</a> › Blog</div><h1>Actualités</h1><p>Conseils, nouveautés et coulisses de {{ $name }}.</p></div></section>
<section><div class="wrap">
  @if($list->isEmpty())<div class="card muted" style="text-align:center">Les premiers articles arrivent bientôt.</div>@endif
  <div class="grid g3">@foreach($list as $po)
    <a class="card prod reveal" style="text-decoration:none" href="{{ $base }}/blog/{{ $po->slug }}{{ $qs }}"><div class="im">@if($po->cover_path)<img src="{{ \App\Modules\Sites\SiteService::img($po->cover_path) }}" alt="" loading="lazy">@else{!! $ic('book', 40) !!}@endif</div>
      <div class="bd"><span class="muted" style="font-size:.85rem">{{ $po->published_at?->locale('fr')->isoFormat('D MMMM YYYY') }}</span><h3 style="margin:0">{{ $po->title }}</h3>
        <span class="muted" style="font-size:.93rem">{{ $po->excerpt ?: \Illuminate\Support\Str::limit(strip_tags((string) $po->body), 150) }}</span><span style="margin-top:auto;font-weight:700;color:var(--p)">Lire l'article →</span></div></a>
  @endforeach</div>
  @if($list->hasPages())<div style="display:flex;justify-content:center;gap:10px;margin-top:30px">@if($list->previousPageUrl())<a class="btn alt" style="color:var(--p)" href="{{ $list->previousPageUrl() }}">← Plus récents</a>@endif @if($list->nextPageUrl())<a class="btn alt" style="color:var(--p)" href="{{ $list->nextPageUrl() }}">Plus anciens →</a>@endif</div>@endif
</div></section>
@endsection
