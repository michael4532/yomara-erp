@extends('rdv.layout')
@section('title', 'Mon espace exposant')
@section('footer', 'Espace exposant / sponsor')
@section('content')
  <div class="card">
    <h2 style="margin-bottom:4px">Mon espace · {{ $event->name }}</h2>
    <div class="muted">{{ $b->exhibitor_name }}{{ $codes ? ' · Stand '.$codes : '' }} · {{ $event->start_date->format('d/m/Y') }}{{ $event->venue ? ' · '.$event->venue : '' }}</div>
  </div>
  @if($saved)<div class="flash ok">Fiche enregistrée{{ $b->profile_published ? ' et visible dans l\'annuaire' : '' }}.</div>@endif
  @if($error)<div class="flash bad">{{ $error }}</div>@endif
  <form method="post" enctype="multipart/form-data" class="card">
    @csrf
    <p class="muted" style="margin-top:0">Présentez votre entreprise aux visiteurs : ces informations apparaissent dans l'annuaire des exposants et partenaires de l'événement.</p>
    <div style="display:flex;gap:14px;align-items:center">
      <div class="logo" style="width:72px;height:72px;border:1px solid var(--line)">@if($logo)<img src="{{ $logo }}" alt="">@else{{ mb_substr($b->exhibitor_name ?: '?', 0, 1) }}@endif</div>
      <div style="flex:1"><label style="margin-top:0">Logo (PNG, JPG, WEBP · 2 Mo max)</label><input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></div>
    </div>
    @php
      $f = [['tagline', 'Accroche', 'Ex. : Le meilleur du cacao ivoirien'], ['sector', 'Secteur d\'activité', ''], ['description', 'Présentation de l\'entreprise', ''], ['products', 'Produits et services présentés', ''],
            ['offer', 'Offre spéciale salon', 'Ex. : -15 % pour toute commande sur le stand'], ['website', 'Site web', 'www.exemple.com'], ['email', 'E-mail de contact', ''], ['phone', 'Téléphone', ''], ['whatsapp', 'WhatsApp', ''],
            ['address', 'Adresse', ''], ['facebook', 'Facebook', ''], ['instagram', 'Instagram', ''], ['linkedin', 'LinkedIn', ''], ['tiktok', 'TikTok', ''], ['youtube', 'YouTube', '']];
    @endphp
    @foreach($f as [$k, $l, $ph])
      <label for="{{ $k }}">{{ $l }}</label>
      @if(in_array($k, ['description', 'products'], true))
        <textarea id="{{ $k }}" name="{{ $k }}" rows="4" maxlength="4000" placeholder="{{ $ph }}">{{ old($k, $p[$k] ?? '') }}</textarea>
      @else
        <input id="{{ $k }}" name="{{ $k }}" maxlength="300" value="{{ old($k, $p[$k] ?? '') }}" placeholder="{{ $ph }}">
      @endif
    @endforeach
    @if($b->status === 'confirmed')
      <label style="display:flex;gap:8px;align-items:center;font-weight:600"><input type="checkbox" name="published" value="1" style="width:auto" @checked($b->profile_published)> Afficher ma fiche dans l'annuaire public</label>
    @else
      <p class="muted">Votre fiche pourra être publiée dans l'annuaire dès la confirmation de votre réservation.</p>
    @endif
    <button class="btn" style="margin-top:14px">Enregistrer ma fiche</button>
  </form>
  <a class="btn ghost" href="{{ $directory }}">Voir l'annuaire des exposants</a>
@endsection
