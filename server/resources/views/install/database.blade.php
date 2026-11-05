@extends('install.layout')
@section('content')
  <h2>Base de données</h2>
  <p class="sub">Créez d'abord une base MySQL/MariaDB et son utilisateur depuis votre panneau d'hébergement. Choisissez SQLite pour une installation locale sans serveur de base.</p>
  @if ($error) <div class="alert">{{ $error }}</div> @endif
  <form method="post" action="{{ url('/install/database') }}">
    <label>Type de base</label>
    <select name="driver" id="driver">
      <option value="mysql" @selected(($values['driver'] ?? '') === 'mysql')>MySQL / MariaDB (recommandé)</option>
      <option value="sqlite" @selected(($values['driver'] ?? '') === 'sqlite')>SQLite (fichier local)</option>
    </select>
    <div id="mysql-fields">
      <div class="row">
        <div><label>Hôte</label><input name="host" value="{{ $values['host'] ?? 'localhost' }}"></div>
        <div><label>Port</label><input name="port" value="{{ $values['port'] ?? '3306' }}"></div>
      </div>
      <label>Nom de la base</label><input name="database" value="{{ $values['database'] ?? '' }}" placeholder="ex. moncompte_erp">
      <div class="row">
        <div><label>Utilisateur</label><input name="username" value="{{ $values['username'] ?? '' }}" autocomplete="off"></div>
        <div><label>Mot de passe</label><input type="password" name="password" autocomplete="new-password"></div>
      </div>
    </div>
    <button class="btn" type="submit">Tester et continuer</button>
  </form>
@endsection
@push('scripts')
<script>
  const d = document.getElementById('driver'), f = document.getElementById('mysql-fields');
  const sync = () => f.style.display = d.value === 'mysql' ? '' : 'none';
  d.addEventListener('change', sync); sync();
</script>
@endpush
