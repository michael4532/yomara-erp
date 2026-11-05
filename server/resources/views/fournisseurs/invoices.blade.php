@extends('rdv.layout')
@section('title', 'Factures')
@section('content')
  @include('fournisseurs.nav')
  <form class="card" method="post" action="{{ url('/fournisseur/'.$token.'/factures') }}" enctype="multipart/form-data">
    @csrf
    <h2>Déposer une facture</h2>
    <div class="two">
      <div><label for="number">N° de facture</label><input id="number" name="number" required value="{{ old('number') }}"></div>
      <div><label for="date">Date</label><input id="date" type="date" name="date" required value="{{ old('date', now()->format('Y-m-d')) }}"></div>
      <div><label for="amount">Montant TTC ({{ $company->currency }})</label><input id="amount" name="amount" inputmode="decimal" required value="{{ old('amount') }}"></div>
      <div><label for="order_id">Bon de commande</label>
        <select id="order_id" name="order_id"><option value="">—</option>@foreach($orders as $o)<option value="{{ $o->id }}" @selected(old('order_id') === $o->id)>{{ $o->number }} · {{ number_format($o->total, 0, ',', ' ') }} {{ $o->currency }}</option>@endforeach</select>
      </div>
    </div>
    <label for="file">Facture (PDF ou photo, 10 Mo au plus)</label>
    <input id="file" type="file" name="file" accept=".pdf,.png,.jpg,.jpeg,.webp" required>
    <label for="comment">Commentaire</label>
    <textarea id="comment" name="comment" rows="2">{{ old('comment') }}</textarea>
    <button class="btn" style="margin-top:12px">Déposer la facture</button>
  </form>
  <div class="card">
    <h2>Vos factures</h2>
    @if($rows->isEmpty())
      <p class="muted">Aucune facture déposée.</p>
    @else
      <table>
        <tr><th>N°</th><th>Date</th><th class="r">Montant</th><th>État</th></tr>
        @foreach($rows as $i)
          @php($st = $svc->invoiceState($i))
          <tr><td>{{ $i->number }}</td><td>{{ $i->date->format('d/m/Y') }}</td><td class="r">{{ number_format($i->amount, 0, ',', ' ') }}</td>
            <td><span class="pill {{ $st['status'] === 'paid' ? 'p-ok' : ($i->status === 'rejected' ? 'p-bad' : 'p-info') }}">{{ $st['label'] }}</span>@if($i->status === 'rejected')<div class="muted">{{ $i->reject_reason }}</div>@elseif($st['paid'] > 0 && $st['status'] !== 'paid')<div class="muted">{{ number_format($st['paid'], 0, ',', ' ') }} payés</div>@endif</td></tr>
        @endforeach
      </table>
    @endif
  </div>
@endsection
