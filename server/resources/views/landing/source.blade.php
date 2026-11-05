@extends('landing.layout')
@section('title', 'Code source de '.$appName.' (licence AGPL)')
@section('description', 'Téléchargez le code source de '.$appName.', logiciel libre sous licence GNU AGPL v3.')

@section('content')
<section class="phead" style="padding-bottom:110px">
  <div class="wrap">
    <div class="crumb"><a href="/">Accueil</a><span>›</span><a href="/telecharger">Télécharger</a><span>›</span><span>Code source</span></div>
    <h1>Code source</h1>
    <p>{{ $appName }} est un logiciel libre distribué sous licence GNU Affero General Public License v3 (AGPL-3.0). Vous pouvez l'étudier, le modifier et l'installer sur votre propre serveur selon les termes de cette licence.</p>
    <div class="hero-cta">
      @if($sourceRepo)<a class="btn btn-gold" href="{{ $sourceRepo }}" rel="noopener" target="_blank">Dépôt {{ str_contains($sourceRepo, 'gitlab') ? 'GitLab' : (str_contains($sourceRepo, 'github') ? 'GitHub' : 'du code source') }}</a>@endif
      @if($server)<a class="btn {{ $sourceRepo ? 'btn-ghost' : 'btn-gold' }}" href="{{ $server }}" rel="nofollow">Serveur et interface web{{ $version ? ' — version '.$version : '' }}</a>@endif
      @if($app)<a class="btn btn-ghost" href="{{ $app }}" rel="nofollow">Applications (Flutter : Windows, Mac, Linux, Android, iPhone)</a>@endif
    </div>
    @if(! $server && ! $app && ! $sourceRepo)<p>Le code source de cette version est en cours de publication. Écrivez-nous depuis la page <a href="/contact" style="text-decoration:underline">Contact</a> pour le recevoir.</p>@endif
    <p style="font-size:14px;opacity:.8;margin-top:22px">© {{ date('Y') }} Yomara Technologies. « Yomara » et « Yomara ERP » sont des marques de Yomara Technologies, non couvertes par la licence : une version modifiée et redistribuée doit porter un autre nom.</p>
  </div>
</section>
@include('landing._end')
@endsection
