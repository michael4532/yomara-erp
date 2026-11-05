@extends('pay.layout')
@section('title', $link->label.' — '.$company->name)
@php($cur = $link->currency ?: $company->currency)
@php($fmt = fn ($v) => number_format((float) $v, in_array($cur, ['XOF','XAF','GNF']) ? 0 : 2, ',', ' ').' '.($cur === 'XOF' ? 'F CFA' : $cur))
@section('content')
  <div class="brand"><div class="logo">{{ mb_substr($company->name, 0, 1) }}</div><div><strong>{{ $company->name }}</strong><div class="muted">{{ $company->phone }} {{ $company->email }}</div></div></div>
  @if ($error)<div class="err">{{ $error }}</div>@endif
  <div class="card">
    <h1>{{ $link->label }}</h1>
    @if ($link->description)<p class="muted"><pre>{{ $link->description }}</pre></p>@endif
    @if ($status === 'paid')
      <div class="amount">{{ $fmt($link->collected) }}</div><span class="pill ok">Paiement reçu — merci !</span>
    @elseif ($status !== 'open')
      <span class="pill bad">Ce lien de paiement n'est plus actif</span>
    @elseif ($link->amount !== null)
      <div class="amount">{{ $fmt($link->amount) }}</div><span class="pill wait">À payer</span>
    @endif
  </div>
  @if ($status === 'open')
    @php($online = $gateways->filter->isOnline())
    @if ($online->isEmpty())
      <div class="card"><p class="muted">Le paiement en ligne n'est pas encore disponible. Contactez {{ $company->name }}.</p></div>
    @else
      <form method="post" id="f" class="card">
        @csrf
        <h2>Vos coordonnées</h2>
        @if ($link->amount === null)
          <label class="muted">Montant ({{ $cur === 'XOF' ? 'F CFA' : $cur }})</label>
          <input name="amount" type="number" min="{{ $link->min_amount ?? 1 }}" step="1" required value="{{ old('amount') }}" style="width:100%;padding:12px;border:1px solid var(--line);border-radius:10px;font-size:20px;font-weight:700;margin:6px 0 12px">
        @endif
        <input name="name" placeholder="Nom et prénom" value="{{ old('name', $link->customer_name) }}" style="width:100%;padding:12px;border:1px solid var(--line);border-radius:10px;margin-bottom:8px">
        <input name="phone" placeholder="Téléphone" value="{{ old('phone', $link->customer_phone) }}" style="width:100%;padding:12px;border:1px solid var(--line);border-radius:10px;margin-bottom:8px">
        <input name="email" type="email" placeholder="E-mail (pour recevoir le reçu)" value="{{ old('email', $link->customer_email) }}" style="width:100%;padding:12px;border:1px solid var(--line);border-radius:10px">
        <h2 style="margin-top:16px">Payer avec</h2>
        @foreach ($online as $g)
          <button class="btn" type="submit" formaction="{{ url('/pay/l/'.$link->token.'/'.$g->id) }}">{{ $g->label }}<small>{{ \App\Payments\GatewayRegistry::meta($g->driver)['channels'] ?? '' }}</small></button>
        @endforeach
      </form>
    @endif
  @endif
@endsection
