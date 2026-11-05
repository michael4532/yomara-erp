@extends('portail.layout')
@php($label = ['quote' => 'Devis', 'order' => 'Commande', 'invoice' => 'Facture', 'credit_note' => 'Avoir'][$doc->type] ?? 'Document')
@php($c = $doc->currency)
@section('title', $label.' '.$doc->number)
@section('content')
  @if($ok)<div class="flash ok noprint">{{ $ok }}</div>@endif
  @if($error)<div class="flash bad noprint">{{ $error }}</div>@endif
  @if($errors->any())<div class="flash bad noprint">{{ $errors->first() }}</div>@endif

  <div class="row noprint" style="margin-bottom:12px">
    <a href="{{ url('/portail/'.$token.'/'.(['quote' => 'devis', 'order' => 'commandes'][$doc->type] ?? 'factures')) }}" class="muted">← {{ ['quote' => 'Devis', 'order' => 'Commandes'][$doc->type] ?? 'Factures' }}</a>
    <span class="sp"></span>
    <a class="btn ghost sm" href="{{ url('/portail/'.$token.'/messages?document='.$doc->id) }}">Poser une question</a>
    <button class="btn ghost sm" onclick="window.print()">Imprimer / PDF</button>
    @if($doc->type === 'invoice' && $doc->balance() > 0.009)<a class="btn sm" href="{{ url('/portail/'.$token.'/document/'.$doc->id.'/payer') }}">Payer {{ $fmt($doc->schedule ? $doc->amountDueNow() : $doc->balance(), $c) }}</a>@endif
  </div>

  <div class="card">
    <div class="row" style="align-items:flex-start">
      <div class="sp">
        <div style="font-size:12px;letter-spacing:.08em;color:var(--muted);font-weight:700">{{ mb_strtoupper($label) }}</div>
        <h1 style="margin-top:2px">{{ $doc->number }}</h1>
        <div class="muted">Date : {{ $doc->date->format('d/m/Y') }}@if($doc->due_date) · {{ $doc->type === 'quote' ? 'Valable jusqu\'au' : 'Échéance' }} : {{ $doc->due_date->format('d/m/Y') }}@endif</div>
        @if(is_array($doc->schedule) && count($doc->schedule) > 1)
            @php($left = (float) $doc->amount_paid)
            <div class="muted" style="margin-top:6px"><strong>Échéancier</strong></div>
            <table style="margin-top:4px">
              @foreach($doc->schedule as $i)
                @php($settled = $left >= (float) $i['amount'] - 0.009)
                @php($left = max(0, $left - (float) $i['amount']))
                <tr><td>{{ \Carbon\Carbon::parse($i['date'])->format('d/m/Y') }}</td><td class="r">{{ $fmt($i['amount'], $c) }}</td>
                  <td class="r muted">{{ $settled ? 'réglée' : ($i['date'] < now()->toDateString() ? 'échue' : 'à venir') }}</td></tr>
              @endforeach
            </table>
        @endif
        @if($doc->reference)<div class="muted">Réf. : {{ $doc->reference }}</div>@endif
        <div style="margin-top:8px">@include('portail._docpill', ['d' => $doc, 'state' => $state])</div>
      </div>
      <div style="text-align:right;font-size:13px;line-height:1.5">
        <strong>{{ $company->name }}</strong><br>
        @if($company->address){{ $company->address }}<br>@endif
        {{ $company->city }}<br>
        @if($company->tax_id)NCC : {{ $company->tax_id }}<br>@endif
        @if($company->trade_register)RCCM : {{ $company->trade_register }}<br>@endif
      </div>
    </div>
    <div style="margin:16px 0;padding:12px 14px;background:var(--bg);border-radius:12px;font-size:14px">
      <div class="muted" style="font-size:12px">Client</div>
      <strong>{{ $doc->partner_name ?: $partner->name }}</strong>
      @if($partner->address)<div>{{ $partner->address }}{{ $partner->city ? ', '.$partner->city : '' }}</div>@endif
      @if($partner->tax_id)<div class="muted">NCC : {{ $partner->tax_id }}</div>@endif
    </div>
    <table>
      <tr><th>Désignation</th><th class="r">Qté</th><th class="r hide-sm">P.U. HT</th><th class="r hide-sm">TVA</th><th class="r">Total TTC</th></tr>
      @foreach($doc->lines as $l)
        <tr>
          <td>{{ $l->description }}@if($l->discount_percent > 0)<div class="muted">Remise {{ rtrim(rtrim(number_format($l->discount_percent, 2, ',', ''), '0'), ',') }} %</div>@endif</td>
          <td class="r">{{ rtrim(rtrim(number_format($l->quantity, 2, ',', ' '), '0'), ',') }}{{ $l->unit ? ' '.$l->unit : '' }}</td>
          <td class="r hide-sm">{{ $fmt($l->unit_price, $c) }}</td>
          <td class="r hide-sm">{{ rtrim(rtrim(number_format($l->tax_rate, 2, ',', ''), '0'), ',') }} %</td>
          <td class="r">{{ $fmt($l->total, $c) }}</td>
        </tr>
      @endforeach
    </table>
    <table style="max-width:360px;margin:12px 0 0 auto">
      <tr><td>Total HT</td><td class="r">{{ $fmt($doc->subtotal, $c) }}</td></tr>
      @if($doc->discount_total > 0)<tr><td>Remises</td><td class="r">− {{ $fmt($doc->discount_total, $c) }}</td></tr>@endif
      <tr><td>TVA</td><td class="r">{{ $fmt($doc->tax_total, $c) }}</td></tr>
      <tr><td><strong>Total TTC</strong></td><td class="r"><strong style="font-size:18px">{{ $fmt($doc->total, $c) }}</strong></td></tr>
      @foreach($payments as $p)<tr><td class="muted">Règlement du {{ $p->date->format('d/m/Y') }}</td><td class="r muted">− {{ $fmt($p->amount, $c) }}</td></tr>@endforeach
      @if($doc->type === 'invoice')<tr><td><strong>Reste à payer</strong></td><td class="r"><strong>{{ $fmt($doc->balance(), $c) }}</strong></td></tr>@endif
    </table>
    @if($doc->notes)<p class="muted" style="white-space:pre-line;margin-top:16px">{{ $doc->notes }}</p>@endif
    @if($doc->terms)<p class="muted" style="white-space:pre-line">{{ $doc->terms }}</p>@endif
    @if($doc->type === 'invoice' && $doc->balance() > 0.009 && $bank_accounts->isNotEmpty())
      <div style="margin-top:16px;font-size:13px"><strong>Virement bancaire</strong>
        @foreach($bank_accounts as $b)<div>{{ $b['bank'] ?? '' }}{{ !empty($b['holder']) ? ' — '.$b['holder'] : '' }}{{ !empty($b['account']) ? ' · RIB '.$b['account'] : '' }}{{ !empty($b['iban']) ? ' · IBAN '.$b['iban'] : '' }}</div>@endforeach
        <div class="muted">Référence à indiquer : {{ $doc->number }}</div>
      </div>
    @endif
  </div>

  @if($doc->type === 'quote')
    @if($state === 'pending')
      <div class="card noprint" id="accord">
        <h2>Accepter ce devis</h2>
        <p class="muted">En validant, vous donnez votre bon pour accord sur ce devis de {{ $fmt($doc->total, $c) }}. {{ $company->name }} est prévenu immédiatement et confirme votre commande.</p>
        <form method="post" action="{{ url('/portail/'.$token.'/document/'.$doc->id.'/accepter') }}">
          @csrf
          <label for="signer">Votre nom et fonction</label>
          <input type="text" id="signer" name="signer" required value="{{ old('signer') }}" placeholder="Ex. Aminata Koné, gérante">
          <label style="font-weight:400;display:flex;gap:8px;align-items:flex-start"><input type="checkbox" name="agree" value="1" required style="margin-top:3px"> <span>Bon pour accord. J'accepte ce devis et ses conditions.</span></label>
          <div class="row" style="margin-top:12px"><button class="btn green" type="submit">Accepter le devis</button></div>
        </form>
        <details style="margin-top:16px"><summary class="muted" style="cursor:pointer">Décliner ce devis</summary>
          <form method="post" action="{{ url('/portail/'.$token.'/document/'.$doc->id.'/refuser') }}">
            @csrf
            <label for="reason">Motif (facultatif)</label>
            <textarea id="reason" name="reason" rows="2" placeholder="Prix, délai, autre fournisseur…"></textarea>
            <div style="margin-top:10px"><button class="btn red sm" type="submit">Décliner</button></div>
          </form>
        </details>
      </div>
    @elseif($answer)
      <div class="card">
        @if($answer->type === 'quote_accepted')
          <strong>Bon pour accord</strong> donné en ligne par {{ $answer->data['signer'] ?? '' }} le {{ $answer->created_at->format('d/m/Y à H:i') }}.
          @if(!empty($answer->data['order_number']))<div class="muted">Commande {{ $answer->data['order_number'] }} confirmée.</div>@endif
        @else
          Devis décliné le {{ $answer->created_at->format('d/m/Y') }}@if(!empty($answer->data['reason'])) : {{ $answer->data['reason'] }}@endif.
        @endif
      </div>
    @endif
  @endif
  @if($print)<script>window.addEventListener('load', () => window.print());</script>@endif
@endsection
