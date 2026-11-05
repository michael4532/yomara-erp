@extends('pay.layout')
@section('title', 'Payer la facture '.$doc->number)
@php($cur = $doc->currency ?: $company->currency)
@php($fmt = fn ($v) => number_format((float) $v, in_array($cur, ['XOF','XAF','GNF']) ? 0 : 2, ',', ' ').' '.($cur === 'XOF' ? 'F CFA' : $cur))
@section('content')
  <div class="brand"><div class="logo">{{ mb_substr($company->name, 0, 1) }}</div><div><strong>{{ $company->name }}</strong><div class="muted">{{ $company->phone }} {{ $company->email }}</div></div></div>
  @if ($error)<div class="err">{{ $error }}</div>@endif
  <div class="card">
    <div class="muted">Facture {{ $doc->number }} du {{ $doc->date?->format('d/m/Y') }} · {{ $doc->partner_name }}</div>
    @if ($due > 0 && count($installments) > 1 && $due_now < $due)
      <div class="amount">{{ $fmt($due_now) }}</div>
      <span class="pill wait">Échéance à régler</span> <span class="muted">· reste dû au total {{ $fmt($due) }}</span>
    @elseif ($due > 0)
      <div class="amount">{{ $fmt($due) }}</div>
      <span class="pill wait">Reste à payer</span>
    @else
      <div class="amount">{{ $fmt($doc->total) }}</div>
      <span class="pill ok">Facture réglée — merci !</span>
    @endif
    <table style="margin-top:16px">
      @foreach ($doc->lines as $l)
        <tr><td>{{ $l->description }} × {{ rtrim(rtrim(number_format($l->quantity, 2, ',', ' '), '0'), ',') }}</td><td class="r">{{ $fmt($l->total) }}</td></tr>
      @endforeach
      <tr><td><strong>Total TTC</strong></td><td class="r"><strong>{{ $fmt($doc->total) }}</strong></td></tr>
      @if ($doc->amount_paid > 0)<tr><td>Déjà payé</td><td class="r">{{ $fmt($doc->amount_paid) }}</td></tr>@endif
    </table>
    @if (count($installments) > 1)
      <h2 style="margin-top:16px">Échéancier</h2>
      <table>
        @foreach ($installments as $i)
          @php($late = $i['date'] < now()->toDateString())
          <tr><td>{{ \Carbon\Carbon::parse($i['date'])->format('d/m/Y') }} @if($late)<span class="pill wait">échue</span>@endif</td><td class="r">{{ $fmt($i['amount']) }}</td></tr>
        @endforeach
      </table>
    @endif
  </div>

  @if ($due > 0)
    @php($online = $gateways->filter->isOnline())
    @if ($online->isNotEmpty())
      <div class="card">
        <h2>Payer en ligne</h2>
        @php($split = count($installments) > 1 && $due_now < $due)
        @foreach ($online as $g)
          <form method="post" action="{{ url('/pay/'.request()->route('token').'/'.$g->id) }}">
            @csrf
            @if ($split)
              <input type="hidden" name="mode" value="due">
              <button class="btn" type="submit">{{ $g->label }} — payer l'échéance ({{ $fmt($due_now) }})<small>{{ \App\Payments\GatewayRegistry::meta($g->driver)['channels'] ?? '' }}</small></button>
            @else
              <button class="btn" type="submit">{{ $g->label }}<small>{{ \App\Payments\GatewayRegistry::meta($g->driver)['channels'] ?? '' }}</small></button>
            @endif
          </form>
          @if ($split)
            <form method="post" action="{{ url('/pay/'.request()->route('token').'/'.$g->id) }}">
              @csrf
              <input type="hidden" name="mode" value="all">
              <button class="btn" type="submit" style="opacity:.85">{{ $g->label }} — tout régler ({{ $fmt($due) }})</button>
            </form>
          @endif
        @endforeach
      </div>
    @endif

    @php($offline = $gateways->reject->isOnline())
    @if ($offline->isNotEmpty() || $bank_accounts->isNotEmpty() || $instructions)
      <div class="card">
        <h2>Autres moyens de paiement</h2>
        @foreach ($bank_accounts as $b)
          <p><strong>{{ $b['bank'] }}</strong>@if(!empty($b['holder'])) — {{ $b['holder'] }}@endif<br>
          @if(!empty($b['account']))RIB : {{ $b['account'] }}<br>@endif
          @if(!empty($b['iban']))IBAN : {{ $b['iban'] }}<br>@endif
          @if(!empty($b['swift']))SWIFT : {{ $b['swift'] }}@endif</p>
        @endforeach
        @foreach ($offline as $g)
          <p><strong>{{ $g->label }}</strong>@if($g->instructions)<br><pre>{{ $g->instructions }}</pre>@endif</p>
          @if($g->publicUrl())<p><a class="btn" href="{{ $g->publicUrl() }}" target="_blank" rel="noopener">Payer avec {{ $g->label }}</a></p>@endif
        @endforeach
        @if ($instructions)<p class="muted"><pre>{{ $instructions }}</pre></p>@endif
        <p class="muted">Indiquez la référence <strong>{{ $doc->number }}</strong> avec votre règlement.</p>
      </div>
    @endif
  @endif
@endsection
