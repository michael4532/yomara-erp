@extends('sites.layout')
@php extract(\App\Modules\Sites\SiteView::vars(get_defined_vars())); @endphp
@section('title', $p->title.' — '.$name)
@section('description', $p->excerpt ?: \Illuminate\Support\Str::limit(strip_tags((string) $p->body), 160))
@if($cover)@section('og_image', $cover)@endif
@push('head')
<style>
.article{max-width:760px;margin:0 auto}.article .meta{color:var(--mut);margin-bottom:18px}
.article .cover{border-radius:calc(var(--r) * 1.3);overflow:hidden;margin:0 0 30px;aspect-ratio:16/8;background:var(--d)}.article .cover img{width:100%;height:100%;object-fit:cover}
.prose{font-size:1.08rem;line-height:1.8}.prose h2{margin-top:1.6em}.prose h3{margin-top:1.3em}.prose a{color:var(--p)}.prose ul{padding-left:1.2em}.prose li{margin:.3em 0}
</style>
@endpush
@section('main')
<section style="padding-top:50px"><div class="wrap"><article class="article">
  <div class="crumb muted" style="opacity:1"><a href="{{ $home }}">Accueil</a> › <a href="{{ $blogUrl }}">Blog</a></div>
  <h1 style="font-size:clamp(1.9rem,4.4vw,3rem)">{{ $p->title }}</h1>
  <div class="meta">{{ $p->published_at?->locale('fr')->isoFormat('D MMMM YYYY') ?? 'Brouillon' }} · {{ $name }}</div>
  @if($cover)<div class="cover"><img src="{{ $cover }}" alt=""></div>@endif
  @if($p->excerpt)<p class="lead" style="max-width:none;margin-bottom:24px"><b>{{ $p->excerpt }}</b></p>@endif
  <div class="prose">{!! $html !!}</div>
  <div class="band" style="margin-top:44px"><div><h2 style="font-size:1.5rem">{{ $c['cta_label'] ?? 'Nous contacter' }}</h2><p>{{ \Illuminate\Support\Str::limit($c['hero_subtitle'] ?? '', 120) }}</p></div><a class="btn light" href="{{ $shopOn ? $shopUrl : $href('contact') }}">{{ $shopOn ? 'Voir la boutique' : 'Nous écrire' }}</a></div>
</article>
@if($others->isNotEmpty())<div style="max-width:1160px;margin:60px auto 0"><h2>À lire aussi</h2><div class="grid g3" style="margin-top:20px">@foreach($others as $po)
  <a class="card" style="text-decoration:none" href="{{ $base }}/blog/{{ $po->slug }}{{ $qs }}"><span class="muted" style="font-size:.85rem">{{ $po->published_at?->locale('fr')->isoFormat('D MMMM YYYY') }}</span><h3 style="margin:6px 0 0">{{ $po->title }}</h3></a>
@endforeach</div></div>@endif
</div></section>
@endsection
