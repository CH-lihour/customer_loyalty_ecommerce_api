<x-mail::message>
# Verify your email

Hi {{ $name }},

Use this code to finish creating your {{ config('app.name') }} account:

<x-mail::panel>
<div style="font-size: 28px; font-weight: bold; letter-spacing: 8px; text-align: center;">{{ $code }}</div>
</x-mail::panel>

The code expires in {{ $expiresInMinutes }} minutes. If you did not sign up, you can ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
