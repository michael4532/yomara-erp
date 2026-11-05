@extends('portail.layout')
@section('title', 'Interventions')
@php($st = \App\Modules\Interventions\Models\Intervention::STATUSES)
@section('content')
  <h1>Interventions et dépannages</h1>
  <p class="muted">Signalez une panne ou demandez une visite ; suivez vos interventions et consultez les rapports.</p>
  @if($ok)<div class="flash ok">{{ $ok }}</div>@endif
  @if($errors->any())<div class="flash bad">{{ $errors->first() }}</div>@endif

  <div class="card">
    <h2>Demander une intervention</h2>
    <form method="post" action="{{ url('/portail/'.$token.'/interventions') }}">
      @csrf
      @if($equipments->isNotEmpty())
        <label for="equipment_id">Équipement concerné</label>
        <select id="equipment_id" name="equipment_id">
          <option value="">— Autre / je ne sais pas —</option>
          @foreach($equipments as $e)<option value="{{ $e->id }}">{{ $e->name }}{{ $e->brand ? ' · '.$e->brand : '' }}{{ $e->location ? ' ('.$e->location.')' : '' }}</option>@endforeach
        </select>
      @endif
      <label for="title">Problème</label>
      <input type="text" id="title" name="title" required value="{{ old('title') }}" placeholder="Ex. La climatisation de la salle de réunion ne refroidit plus">
      <label for="description">Détails (facultatif)</label>
      <textarea id="description" name="description" rows="3" placeholder="Depuis quand, message d'erreur, bruit…">{{ old('description') }}</textarea>
      <div class="row" style="align-items:flex-start">
        <div class="sp" style="min-width:200px"><label for="priority">Urgence</label>
          <select id="priority" name="priority"><option value="1">Normale</option><option value="2">Haute</option><option value="3">Urgente (activité arrêtée)</option><option value="0">Peut attendre</option></select></div>
        <div class="sp" style="min-width:200px"><label for="contact_phone">Téléphone sur place</label><input type="text" id="contact_phone" name="contact_phone" value="{{ old('contact_phone', $partner->phone) }}"></div>
      </div>
      <label for="address">Adresse de l'intervention</label>
      <input type="text" id="address" name="address" value="{{ old('address', $partner->address) }}">
      <div style="margin-top:14px"><button class="btn" type="submit">Envoyer la demande</button></div>
    </form>
  </div>

  @if($contracts->isNotEmpty())
  <div class="card"><h2>Vos contrats de maintenance</h2>
    @foreach($contracts as $c)<div class="row" style="padding:6px 0"><div class="sp"><strong>{{ $c->name }}</strong><div class="muted">{{ $c->number }}@if($c->next_visit) · prochaine visite vers le {{ $c->next_visit->format('d/m/Y') }}@endif @if($c->response_hours) · intervention sous {{ $c->response_hours }} h @endif</div></div><span class="pill ok">Actif</span></div>@endforeach
  </div>
  @endif

  <div class="card">
    <h2>Historique</h2>
    @if($rows->isEmpty())<p class="muted">Aucune intervention pour le moment.</p>@else
    <table>
      <tr><th>N°</th><th>Objet</th><th class="hide-sm">Date</th><th>Statut</th><th></th></tr>
      @foreach($rows as $i)
        <tr>
          <td><strong>{{ $i->number }}</strong></td>
          <td>{{ $i->title }}</td>
          <td class="hide-sm">{{ ($i->finished_at ?? $i->scheduled_at ?? $i->created_at)->format('d/m/Y') }}</td>
          <td><span class="pill {{ ['done' => 'ok', 'cancelled' => 'grey', 'requested' => 'wait', 'en_route' => 'wait', 'in_progress' => 'wait'][$i->status] ?? 'info' }}">{{ $st[$i->status] ?? $i->status }}</span></td>
          <td class="r"><a class="btn sm ghost" href="{{ $i->reportUrl() }}">{{ $i->status === 'done' ? 'Rapport' : 'Suivre' }}</a></td>
        </tr>
      @endforeach
    </table>@endif
  </div>
@endsection
