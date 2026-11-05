@extends('install.layout')
@section('content')
  <h2>Installation terminée</h2>
  <p class="sub">Connectez-vous avec <strong>{{ $email }}</strong>.</p>
  <label>Application web</label>
  <pre>{{ $appUrl }}</pre>
  <label>Adresse du serveur pour les applications Windows, Mac et Linux</label>
  <pre>{{ $apiUrl }}</pre>
  <label>Tâche cron à ajouter (cPanel → Tâches cron, toutes les minutes)</label>
  <pre>{{ $cron }}</pre>
  <a class="btn" href="{{ $appUrl }}">Ouvrir Yomara ERP</a>
@endsection
