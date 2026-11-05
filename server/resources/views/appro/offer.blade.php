@extends('rdv.layout')
@section('title', 'Demande de prix · '.$cmp->title)
@section('content')
<style>
  table { width:100%; border-collapse:collapse; font-size:14px; } th, td { text-align:left; padding:8px 6px; border-bottom:1px solid #EEF1F6; vertical-align:top; } th { color:var(--muted); font-weight:600; font-size:13px; }
  td.r, th.r { text-align:right; } input.price { text-align:right; padding:9px; } input.note { margin-top:6px; padding:7px; font-size:13px; }
  .two { display:grid; grid-template-columns:1fr 1fr; gap:0 12px; } @media (max-width:560px) { .two { grid-template-columns:1fr; } .hide-s { display:none; } }
  .total { font-size:18px; font-weight:800; text-align:right; margin-top:10px; }
  .msg { padding:10px 12px; border-radius:12px; margin-bottom:8px; max-width:88%; } .msg.in { background:#F2F6FF; margin-left:auto; } .msg.out { background:#FFF8E1; }
  .msg small { display:block; color:var(--muted); font-size:12px; margin-bottom:3px; }
  .check { display:flex; gap:10px; align-items:flex-start; margin-top:12px; font-weight:600; font-size:14px; } .check input { width:auto; margin-top:3px; }
  details summary { cursor:pointer; color:var(--bad); font-weight:600; margin-top:6px; }
</style>
@if($ok)<div class="flash ok">{{ $ok }}</div>@endif
@if($error)<div class="flash bad">{{ $error }}</div>@endif
@if($errors->any())<div class="flash bad">{{ $errors->first() }}</div>@endif

<div class="card">
  <div class="muted">Demande de prix {{ $cmp->number }} · adressée à <b>{{ $inv->name }}</b></div>
  <h2 style="font-size:21px;margin:4px 0 8px">{{ $cmp->title }}</h2>
  @if($cmp->instructions)<p style="white-space:pre-line">{{ $cmp->instructions }}</p>@endif
  @if($cmp->deadline)<div class="row"><span>Date limite des offres</span><b>{{ $cmp->deadline->format('d/m/Y') }}</b></div>@endif
  @if($spec)
    <div class="row"><span>Cahier des charges</span><b><a href="{{ url('/offre/'.$inv->token.'/cahier-des-charges.pdf') }}" target="_blank">{{ $spec->number }} (PDF)</a></b></div>
    @if($spec->site)<div class="row"><span>Lieu / chantier</span><b>{{ $spec->site }}</b></div>@endif
  @endif
  @if($answered && $offer)<div class="row"><span>Votre offre</span><b>déposée le {{ \Illuminate\Support\Carbon::parse($offer['submitted_at'] ?? $inv->answered_at)->format('d/m/Y à H:i') }}{{ $inv->revisions ? ' (modifiée '.$inv->revisions.' fois)' : '' }}</b></div>@endif
  @if($cmp->status === 'awarded')
    <div class="flash {{ $won ? 'ok' : 'bad' }}" style="margin:10px 0 0">{{ $won ? 'Votre offre est retenue : nous vous envoyons le bon de commande.' : 'Cette consultation est attribuée. Merci pour votre offre.' }}</div>
  @elseif(! $open)
    <div class="flash bad" style="margin:10px 0 0">La date limite est passée : les offres sont closes.</div>
  @elseif($inv->status === 'declined')
    <div class="flash bad" style="margin:10px 0 0">Vous avez indiqué ne pas donner suite. Vous pouvez quand même déposer une offre ci-dessous.</div>
  @endif
</div>

@if($spec && $spec->requirements)
<div class="card">
  <h2>Spécifications demandées</h2>
  <table>
    <tr><th>Désignation</th><th>Caractéristiques</th><th class="r hide-s">Exigence</th></tr>
    @foreach($spec->requirements as $r)
      <tr><td>{{ $r['label'] }}</td><td>{{ $r['value'] }}</td><td class="r hide-s">{{ $r['mandatory'] ? 'Obligatoire' : 'Souhaitée' }}</td></tr>
    @endforeach
  </table>
</div>
@endif

<form class="card" method="post" action="{{ url('/offre/'.$inv->token) }}" enctype="multipart/form-data" id="offre">
  @csrf
  <h2>{{ $open ? ($answered ? 'Modifier votre offre' : 'Votre offre') : 'Articles demandés' }}</h2>
  @php($cur = old('currency', $offer['currency'] ?? $company->currency))
  @if($open)
    <label for="currency">Devise de vos prix</label>
    <select id="currency" name="currency">@foreach($currencies as $x)<option value="{{ $x }}" @selected($x === $cur)>{{ $x }}</option>@endforeach</select>
  @endif
  <table style="margin-top:10px">
    <tr><th>Article</th><th class="r">Quantité</th><th class="r">Prix unitaire HT</th></tr>
    @foreach($cmp->lines as $l)
      @php($pv = old('price.'.$l['key'], isset($offer['prices'][$l['key']]) ? rtrim(rtrim(number_format($offer['prices'][$l['key']], 2, '.', ''), '0'), '.') : ''))
      <tr>
        <td>{{ $l['description'] }}
          @if($open)<input class="note" name="note[{{ $l['key'] }}]" placeholder="Marque, référence, remarque…" value="{{ old('note.'.$l['key'], $offer['line_notes'][$l['key']] ?? '') }}">
          @elseif($offer['line_notes'][$l['key']] ?? null)<div class="muted">{{ $offer['line_notes'][$l['key']] }}</div>@endif
        </td>
        <td class="r" style="white-space:nowrap">{{ rtrim(rtrim(number_format($l['quantity'], 2, ',', ' '), '0'), ',') }} {{ $l['unit'] }}</td>
        <td class="r" style="width:160px">
          @if($open)<input class="price" name="price[{{ $l['key'] }}]" data-q="{{ $l['quantity'] }}" inputmode="decimal" placeholder="non proposé" value="{{ $pv }}">
          @else{{ $pv !== '' ? number_format((float) $pv, 2, ',', ' ') : '—' }}@endif
        </td>
      </tr>
    @endforeach
  </table>
  @if($open)
    <div class="total">Total HT : <span id="tot">0</span> <span id="cur">{{ $cur }}</span></div>
    <div class="two">
      <div><label for="delay_days">Délai de livraison (jours)</label><input id="delay_days" name="delay_days" type="number" min="0" value="{{ old('delay_days', $offer['delay_days'] ?? '') }}"></div>
      <div><label for="validity">Offre valable jusqu'au</label><input id="validity" name="validity" type="date" value="{{ old('validity', $offer['validity'] ?? '') }}"></div>
      <div><label for="extra">Frais de transport / livraison ({{ $company->currency }})</label><input id="extra" name="extra" inputmode="decimal" value="{{ old('extra', ! empty($offer['extra']) ? (int) $offer['extra'] : '') }}"></div>
      <div><label for="payment_terms">Conditions de paiement</label><input id="payment_terms" name="payment_terms" placeholder="ex. 30 jours, 50 % à la commande" value="{{ old('payment_terms', $offer['payment_terms'] ?? '') }}"></div>
    </div>
    <label class="check"><input type="checkbox" name="compliant" value="1" @checked(old('compliant', ($offer['compliant'] ?? true) || ! $answered))> Mon offre respecte les spécifications demandées</label>
    <label for="comment">Commentaire (variantes, garanties, réserves)</label>
    <textarea id="comment" name="comment" rows="3">{{ old('comment', $offer['notes'] ?? '') }}</textarea>
    <label for="file">Joindre votre devis ou une fiche technique (PDF, image, Excel, Word · 10 Mo)</label>
    <input id="file" name="file" type="file" accept=".pdf,.jpg,.jpeg,.png,.xlsx,.xls,.docx,.doc">
    @if($inv->file_name)<p class="muted">Fichier déjà joint : {{ $inv->file_name }} (un nouveau fichier le remplace).</p>@endif
    <p class="muted">Laissez vide le prix d'un article que vous ne proposez pas. Vous pouvez modifier votre offre jusqu'à la date limite.</p>
    <button class="btn">{{ $answered ? 'Mettre à jour mon offre' : 'Envoyer mon offre' }}</button>
  @endif
</form>
@if($open && $inv->status !== 'answered')
<form class="card" method="post" action="{{ url('/offre/'.$inv->token.'/decliner') }}">
  @csrf
  <details><summary>Je ne donne pas suite à cette demande</summary>
    <label for="reason">Motif (facultatif)</label>
    <input id="reason" name="reason" placeholder="ex. article non disponible, délai impossible">
    <button class="btn red" style="margin-top:10px">Décliner la demande</button>
  </details>
</form>
@endif

<div class="card" id="messages">
  <h2>Questions et réponses</h2>
  @forelse($messages as $m)
    <div class="msg {{ $m->direction }}"><small>{{ $m->direction === 'in' ? 'Vous' : ($m->author ?: $company->name) }} · {{ $m->created_at->format('d/m/Y H:i') }}</small>{!! nl2br(e($m->body)) !!}</div>
  @empty
    <p class="muted">Une question sur la demande ? Écrivez-nous ici, nous vous répondrons sur cette page.</p>
  @endforelse
  <form method="post" action="{{ url('/offre/'.$inv->token.'/message') }}">
    @csrf
    <textarea name="body" rows="3" placeholder="Votre question ou votre réponse" required></textarea>
    <button class="btn ghost" style="margin-top:8px">Envoyer le message</button>
  </form>
</div>
<script>
  (function () {
    var inputs = document.querySelectorAll('input.price'), tot = document.getElementById('tot'), cur = document.getElementById('currency'), lab = document.getElementById('cur');
    if (!tot) return;
    function n(v) { v = (v || '').replace(/[\s  ]/g, '').replace(',', '.'); return v === '' || isNaN(v) ? 0 : parseFloat(v); }
    function upd() { var t = 0; inputs.forEach(function (i) { t += n(i.value) * parseFloat(i.dataset.q || '1'); }); tot.textContent = t.toLocaleString('fr-FR', { maximumFractionDigits: 2 }); if (cur) lab.textContent = cur.value; }
    inputs.forEach(function (i) { i.addEventListener('input', upd); }); if (cur) cur.addEventListener('change', upd); upd();
  })();
</script>
@endsection
@section('footer', 'Demande de prix')
