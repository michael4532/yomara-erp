@php($bal = $d->balance())
@if($d->type === 'invoice')
  @if($bal <= 0.009)<span class="pill ok">Payée</span>
  @elseif($d->due_date && $d->due_date->lt(today()))<span class="pill bad">En retard</span>
  @elseif($d->amount_paid > 0)<span class="pill wait">Partiellement payée</span>
  @else<span class="pill wait">À payer</span>@endif
@elseif($d->type === 'quote')
  @php($st = $state ?? 'pending')
  <span class="pill {{ ['accepted' => 'ok', 'refused' => 'bad', 'expired' => 'grey', 'pending' => 'info'][$st] }}">{{ ['accepted' => 'Accepté', 'refused' => 'Refusé', 'expired' => 'Expiré', 'pending' => 'En attente de votre accord'][$st] }}</span>
@elseif($d->type === 'credit_note')<span class="pill ok">Avoir</span>
@else<span class="pill info">Confirmée</span>@endif
