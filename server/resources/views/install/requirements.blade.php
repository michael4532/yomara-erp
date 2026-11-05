@extends('install.layout')
@section('content')
  <h2>Vérification du serveur</h2>
  <p class="sub">Yomara ERP fonctionne sur un hébergement mutualisé (cPanel, Plesk…), un VPS ou un serveur local.</p>
  <ul class="checks">
    @foreach ($checks as $c)
      <li><span>{{ $c['label'] }}</span>
        @if ($c['ok']) <span class="ok">OK</span>
        @elseif ($c['optional']) <span class="opt">Absent</span>
        @else <span class="ko">Manquant</span> @endif
      </li>
    @endforeach
  </ul>
  @if ($ok)
    <a class="btn" href="{{ url('/install/database') }}">Continuer</a>
  @else
    <p class="alert" style="margin-top:18px">Corrigez les éléments manquants (activez les extensions dans « Sélectionner la version PHP » de cPanel, ou mettez les dossiers en 755/775), puis rechargez cette page.</p>
  @endif
@endsection
