@extends('portail.layout')
@section('title', 'Messages')
@section('content')
  <h1>Messages</h1>
  <p class="muted">Une question sur une facture, une commande, une livraison ? Écrivez-nous ici.</p>
  @if($ok)<div class="flash ok">{{ $ok }}</div>@endif
  @if($errors->any())<div class="flash bad">{{ $errors->first() }}</div>@endif
  @if($events->isNotEmpty())
  <div class="card">
    @foreach($events as $e)
      <div style="display:flex;{{ $e->type === 'reply' ? '' : 'justify-content:flex-end;' }}margin:8px 0">
        <div style="max-width:80%;padding:10px 14px;border-radius:14px;{{ $e->type === 'reply' ? 'background:var(--bg)' : 'background:#E8F0FE' }}">
          <div style="font-size:12px" class="muted">{{ $e->type === 'reply' ? ($e->data['author'] ?? $company->name) : 'Vous' }} · {{ $e->created_at->format('d/m/Y H:i') }}</div>
          @if(!empty($e->data['subject']))<strong>{{ $e->data['subject'] }}</strong><br>@endif
          <span style="white-space:pre-line">{{ $e->data['body'] ?? '' }}</span>
        </div>
      </div>
    @endforeach
  </div>
  @endif
  <div class="card">
    <h2>Nouveau message</h2>
    <form method="post" action="{{ url('/portail/'.$token.'/messages') }}">
      @csrf
      <label for="document_id">Au sujet de</label>
      <select id="document_id" name="document_id">
        <option value="">— Question générale —</option>
        @foreach($docs as $d)<option value="{{ $d->id }}" @selected($ref === $d->id)>{{ ['quote' => 'Devis', 'order' => 'Commande', 'invoice' => 'Facture', 'credit_note' => 'Avoir'][$d->type] }} {{ $d->number }} · {{ $fmt($d->total, $d->currency) }}</option>@endforeach
      </select>
      <label for="subject">Objet</label>
      <input type="text" id="subject" name="subject" required value="{{ old('subject') }}">
      <label for="body">Message</label>
      <textarea id="body" name="body" rows="5" required>{{ old('body') }}</textarea>
      <div style="margin-top:12px"><button class="btn" type="submit">Envoyer</button></div>
    </form>
  </div>
@endsection
