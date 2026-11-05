@extends('install.layout')
@section('content')
  <h2>Votre société et le compte administrateur</h2>
  <p class="sub">Ce compte est le super administrateur : il gère les modules, leurs prix et voit les personnes connectées.</p>
  @foreach ($errors as $e) <div class="alert">{{ $e }}</div> @endforeach
  <form method="post" action="{{ url('/install/setup') }}" id="f">
    <label>Mode d'utilisation</label>
    <div class="choice">
      <label><input type="radio" name="mode" value="single" @checked(($values['mode'] ?? 'single') === 'single')> Mon entreprise</label>
      <label><input type="radio" name="mode" value="saas" @checked(($values['mode'] ?? '') === 'saas')> Plateforme SaaS (éditeur)</label>
    </div>
    <div class="row">
      <div><label>Nom du logiciel</label><input name="app_name" value="{{ $values['app_name'] ?? 'Yomara ERP' }}" required></div>
      <div><label>Devise</label><input name="currency" value="{{ $values['currency'] ?? 'XOF' }}" maxlength="3" required></div>
    </div>
    <label>Nom de la société</label><input name="company_name" value="{{ $values['company_name'] ?? '' }}" required>
    <label>Votre nom</label><input name="admin_name" value="{{ $values['admin_name'] ?? '' }}" required>
    <label>E-mail de connexion</label><input type="email" name="admin_email" value="{{ $values['admin_email'] ?? '' }}" required>
    <div class="row">
      <div><label>Mot de passe</label><input type="password" name="admin_password" minlength="8" required></div>
      <div><label>Confirmation</label><input type="password" name="admin_password_confirmation" minlength="8" required></div>
    </div>
    <div id="edk"><label>Clé éditeur</label><input type="password" name="editor_key" autocomplete="off" placeholder="Réservée à l'éditeur de la plateforme"></div>
    <p class="hint">L'installation crée les tables : cela peut prendre une minute.</p>
    <button class="btn" type="submit" id="go">Installer</button>
  </form>
@endsection
@push('scripts')
<script>(() => { const sync = () => { const saas = document.querySelector('input[name=mode]:checked')?.value === 'saas'; document.getElementById('edk').style.display = saas ? '' : 'none'; }; document.querySelectorAll('input[name=mode]').forEach(r => r.addEventListener('change', sync)); sync(); })();</script>
<script>document.getElementById('f').addEventListener('submit', () => { const b = document.getElementById('go'); b.disabled = true; b.textContent = 'Installation en cours…'; });</script>
@endpush
