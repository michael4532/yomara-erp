@extends('portail.layout')
@section('title', 'Livraisons')
@section('content')
  <h1>Livraisons</h1>
  <p class="muted">Cliquez sur une livraison pour suivre son avancement.</p>
  <div class="card">
    @if($rows->isEmpty())<p class="muted">Aucune livraison.</p>@else
    <table>
      <tr><th>N°</th><th>Date</th><th class="hide-sm">Adresse</th><th>Statut</th></tr>
      @foreach($rows as $l)
        <tr>
          <td><a href="{{ $l->trackingUrl() }}"><strong>{{ $l->number }}</strong></a></td>
          <td>{{ $l->scheduled_date?->format('d/m/Y') }}</td>
          <td class="hide-sm">{{ $l->address }}{{ $l->city ? ', '.$l->city : '' }}</td>
          <td><span class="pill {{ ['delivered' => 'ok', 'in_transit' => 'wait', 'failed' => 'bad', 'returned' => 'bad'][$l->status] ?? 'info' }}">{{ ['planned' => 'Prévue', 'in_transit' => 'En route', 'delivered' => 'Livrée', 'failed' => 'À reprogrammer', 'returned' => 'Retournée', 'draft' => 'En préparation'][$l->status] ?? $l->status }}</span></td>
        </tr>
      @endforeach
    </table>@endif
  </div>
@endsection
