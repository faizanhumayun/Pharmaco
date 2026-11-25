{{--
    The security code page: what a visitor meets first while the gate is on
    (config/security-code.php, switched in the back office).

    Drawn with the sign-in page's own parts (resources/css/pharmaco/login.css),
    so it carries no colours of its own.
--}}
@php($name = config('app.name', 'Pharmaco'))
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Security code — {{ $name }}</title>
    @include('partials.pharmaco-fonts')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="pharmaco">
    <div class="ph-login">
        <main class="ph-login__form">
            <span class="ph-wordmark">{{ $name }}</span>

            <div class="ph-login__body">
                <div class="ph-login__intro">
                    <p class="text-label">Protected</p>
                    <h1 class="text-heading">Enter security code</h1>
                    <p class="text-body">This area is protected. Enter your access code to continue.</p>
                </div>

                <form method="POST" action="{{ route('security-code.verify') }}" class="ph-form" id="otp-form">
                    @csrf
                    <input type="hidden" name="security_code" id="security_code">

                    <div class="ph-field">
                        <div class="ph-code" id="otp-wrap">
                            @for ($i = 0; $i < 4; $i++)
                                <input type="text" maxlength="1" inputmode="numeric" autocomplete="off"
                                       aria-label="Digit {{ $i + 1 }}" data-otp
                                       @if ($i === 0) autofocus @endif
                                       @class(['ph-input', 'is-wrong' => $errors->has('security_code')])>
                            @endfor
                        </div>

                        @error('security_code')
                            <p class="ph-field__error" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <button type="submit" class="ph-button">Continue</button>
                </form>
            </div>

            <p class="text-small ph-login__note">Ask the administrator of {{ $name }} for the code.</p>
        </main>
    </div>

    <script>
        (function () {
            const inputs = Array.from(document.querySelectorAll('[data-otp]'));
            const hidden = document.getElementById('security_code');
            const form = document.getElementById('otp-form');
            if (! inputs.length) {
                return;
            }

            const sync = () => { hidden.value = inputs.map((i) => i.value).join(''); };

            inputs.forEach((input, idx) => {
                input.addEventListener('input', () => {
                    input.value = input.value.replace(/\D/g, '').slice(0, 1);
                    sync();
                    if (input.value && idx < inputs.length - 1) {
                        inputs[idx + 1].focus();
                    }
                });

                input.addEventListener('keydown', (e) => {
                    if (e.key === 'Backspace' && ! input.value && idx > 0) {
                        inputs[idx - 1].focus();
                    }
                });

                input.addEventListener('paste', (e) => {
                    e.preventDefault();
                    const digits = (e.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, inputs.length).split('');
                    digits.forEach((d, k) => { if (inputs[k]) { inputs[k].value = d; } });
                    sync();
                    inputs[Math.min(digits.length, inputs.length - 1)].focus();
                });
            });

            form.addEventListener('submit', sync);
        })();
    </script>
</body>
</html>
