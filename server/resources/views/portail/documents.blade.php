@extends('portail.layout')
@section('title', ['factures' => 'Factures', 'devis' => 'Devis', 'commandes' => 'Commandes'][$kind])
@section('content')
  <h1>{{ ['factures' => 'Factures et avoirs', 'devis' => 'Devis', 'commandes' => 'Commandes'][$kind] }}</h1>
  <p class="muted">{{ $docs->count() }} document(s)</p>
  <div class="card">
    @if($docs->isEmpty())
      <p class="muted">Aucun document pour le moment.</p>
    @else
    <table>
      <tr><th>N°</th><th>Date</th><th class="r">Montant</th>@if($kind === 'factures')<th class="r hide-sm">Reste dû</th>@endif<th>Statut</th><th></th></tr>
      @foreach($docs as $d)
        <tr>
          <td><a href="{{ url('/portail/'.$token.'/document/'.$d->id) }}"><strong>{{ $d->number }}</strong></a>@if($d->type === 'credit_note')<div class="muted">Avoir</div>@endif</td>
          <td>{{ $d->date->format('d/m/Y') }}</td>
          <td class="r">{{ $fmt($d->total, $d->currency) }}</td>
          @if($kind === 'factures')<td class="r hide-sm">{{ $d->type === 'invoice' && $d->balance() > 0.009 ? $fmt($d->balance(), $d->currency) : '—' }}</td>@endif
          <td>@include('portail._docpill', ['d' => $d, 'state' => $states[$d->id] ?? null])</td>
          <td class="r">
            @if($d->type === 'invoice' && $d->balance() > 0.009)<a class="btn sm" href="{{ url('/portail/'.$token.'/document/'.$d->id.'/payer') }}">Payer</a>
            @elseif($d->type === 'quote' && ($states[$d->id] ?? '') === 'pending')<a class="btn sm green" href="{{ url('/portail/'.$token.'/document/'.$d->id) }}">Répondre</a>
            @else<a class="btn sm ghost" href="{{ url('/portail/'.$token.'/document/'.$d->id) }}">Voir</a>@endif
          </td>
        </tr>
      @endforeach
    </table>
    @endif
  </div>
@endsection
