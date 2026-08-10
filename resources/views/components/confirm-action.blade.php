{{-- A single-button form behind a confirm dialog — Release, Resend, Delete.

     The prompt goes through @js rather than into the attribute by hand, so a
     message containing an apostrophe ("Postmaster's record") doesn't have to
     be escaped at each call site and can't break the handler by being missed.

     Props:
       $action  : where the form posts
       $confirm : the prompt text
       $label   : the button's text
       $method  : spoofed verb, when it isn't POST
       $title   : optional hover note (a caveat about what the action will do)
     Any other attribute lands on the button, which already carries pm-btn —
     so pass modifiers only ("pm-btn--danger"). The slot is for hidden fields
     the action needs to carry. --}}
@props(['action', 'confirm', 'label', 'method' => 'POST', 'title' => null])

<form method="POST" action="{{ $action }}" onsubmit="return confirm(@js($confirm))">
    @csrf
    @unless ($method === 'POST')
        @method($method)
    @endunless
    {{ $slot }}
    <button type="submit" {{ $attributes->merge(['class' => 'pm-btn']) }} @if ($title) title="{{ $title }}" @endif>
        {{ $label }}
    </button>
</form>
