@extends('portail.layout')
@section('title', 'Rendez-vous')
@php($st = \App\Modules\RendezVous\Models\Appointment::STATUSES)
@section('content')
  <div class="row"><div class="sp"><h1>Rendez-vous</h1><p class="muted">Vos rendez-vous avec {{ $company->name }}.</p></div><a class="btn" href="{{ $bookUrl }}">Prendre rendez-vous</a></div>
  <div class="card" style="margin-top:12px">
    @if($rows->isEmpty())<p class="muted">Aucun rendez-vous pour le moment.</p>@else
    <table>
      <tr><th>Date</th><th>Prestation</th><th>Statut</th><th></th></tr>
      @foreach($rows as $a)
        <tr>
          <td><strong>{{ $a->starts_at->copy()->timezone($tz)->format('d/m/Y H:i') }}</strong></td>
          <td>{{ $services[$a->service_id] ?? '—' }}</td>
          <td><span class="pill {{ ['confirmed' => 'ok', 'done' => 'ok', 'pending' => 'wait', 'cancelled' => 'grey', 'no_show' => 'bad'][$a->status] ?? 'info' }}">{{ $st[$a->status] ?? $a->status }}</span></td>
          <td class="r"><a class="btn sm ghost" href="{{ $a->manageUrl() }}">{{ in_array($a->status, ['pending', 'confirmed']) && $a->starts_at->isFuture() ? 'Gérer' : 'Voir' }}</a></td>
        </tr>
      @endforeach
    </table>@endif
  </div>
@endsection
