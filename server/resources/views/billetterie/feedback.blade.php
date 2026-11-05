@extends('rdv.layout')
@section('title', 'Votre avis — '.$event->name)
@section('footer', 'Avis des participants')
@section('content')
<style>.stars{display:flex;gap:6px;flex-direction:row-reverse;justify-content:flex-end}.stars input{display:none}.stars label{font-size:38px;color:#D7DEEA;cursor:pointer;transition:transform .15s,color .15s}.stars label:hover,.stars label:hover~label,.stars input:checked~label{color:#FFB400}.stars label:hover{transform:scale(1.15)}.nps{display:flex;flex-wrap:wrap;gap:6px}.nps label{display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border:1px solid var(--line);border-radius:10px;cursor:pointer;font-weight:700}.nps input{display:none}.nps input:checked+span{color:#fff}.nps label:has(input:checked){background:var(--blue);color:#fff;border-color:var(--blue)}</style>
  <div class="card">
    <div class="muted">{{ $company->name }}</div>
    <h2 style="font-size:22px;margin:4px 0">{{ $event->name }}</h2>
    <div class="muted">{{ ucfirst($event->start_date->locale('fr')->isoFormat('dddd D MMMM YYYY')) }}{{ $event->venue ? ' · '.$event->venue : '' }}</div>
  </div>
  @if($saved || $done)
    <div class="card" style="text-align:center">
      <div style="font-size:44px">🙏</div>
      <h2>Merci {{ $ticket->holder_name }} !</h2>
      <p class="muted">Votre avis a bien été enregistré{{ $done ? ' (note '.$done->rating.'/5)' : '' }}. Vous pouvez le modifier ci-dessous.</p>
    </div>
  @endif
  @if($errors->any())<div class="flash bad">{{ $errors->first() }}</div>@endif
  <form method="post" class="card">
    @csrf
    <input class="hp" type="text" name="website" tabindex="-1" autocomplete="off">
    <label>Votre note</label>
    @php($cur = (int) old('rating', $done->rating ?? ($note ?: 0)))
    <div class="stars">
      @for($s = 5; $s >= 1; $s--)
        <input type="radio" id="s{{ $s }}" name="rating" value="{{ $s }}" @checked($cur === $s)><label for="s{{ $s }}" title="{{ $s }} / 5">★</label>
      @endfor
    </div>
    <label style="margin-top:14px">Recommanderiez-vous cet événement à un proche ? (0 = pas du tout, 10 = certainement)</label>
    @php($np = old('nps', $done->nps ?? null))
    <div class="nps">
      @for($i = 0; $i <= 10; $i++)
        <label><input type="radio" name="nps" value="{{ $i }}" @checked($np !== null && (int) $np === $i)><span>{{ $i }}</span></label>
      @endfor
    </div>
    <label for="comment" style="margin-top:14px">Un commentaire, une suggestion ? (facultatif)</label>
    <textarea id="comment" name="comment" rows="4">{{ old('comment', $done->comment ?? '') }}</textarea>
    <button class="btn" type="submit" style="margin-top:14px">Envoyer mon avis</button>
  </form>
@endsection
