@extends('signature.layout')
@section('title', 'Vérifier un document signé')
@section('subtitle', 'Contrôlez l\'authenticité d\'un document signé électroniquement')
@section('content')
<div class="card">
  <h2>Vérifier un document</h2>
  <p class="muted">Déposez le PDF reçu : nous calculons son empreinte et la comparons aux documents signés. Le fichier n'est pas conservé.</p>
  <form method="post" action="/signature/verifier" enctype="multipart/form-data">@csrf
    <input type="file" name="file" accept="application/pdf" required>
    <button class="btn" type="submit">Vérifier</button>
  </form>
</div>
@if($checked)
  @if($result)
  @php $rq = $result['request']; @endphp
  <div class="card">
    @if($result['kind'] === 'signed')
      <p><span class="pill ok">Document authentique</span></p>
      <p style="margin:0">Ce fichier est l'exemplaire signé, non modifié, du document <b>« {{ $rq->title }} »</b> (réf. {{ $rq->number }}) émis par <b>{{ $result['company']->name }}</b>.</p>
    @else
      <p><span class="pill warn">Document d'origine</span></p>
      <p style="margin:0">Ce fichier est la version <b>avant signature</b> du document « {{ $rq->title }} » (réf. {{ $rq->number }}) de {{ $result['company']->name }}. @if($rq->status === 'completed')Le document a été signé depuis : demandez l'exemplaire signé.@else Les signatures ne sont pas encore toutes recueillies.@endif</p>
    @endif
    <ul class="muted" style="margin:12px 0 0;padding-left:18px">
      @foreach($result['signers'] as $o)
        <li>{{ $o->name }}{{ $o->label ? ' ('.$o->label.')' : '' }} — @if($o->signed_at)signé le {{ $o->signed_at->setTimezone($result['company']->setting('timezone') ?: config('app.timezone'))->format('d/m/Y à H:i') }}@else non signé @endif</li>
      @endforeach
    </ul>
    <p class="muted" style="font-size:12px;word-break:break-all;margin-top:10px">Empreinte SHA-256 : {{ $result['sha256'] }}</p>
  </div>
  @else
  <div class="card"><p style="margin:0"><span class="pill bad">Inconnu</span> Ce fichier ne correspond à aucun document signé. Il a pu être modifié, ou il ne s'agit pas de l'exemplaire signé final.</p></div>
  @endif
@endif
@endsection
