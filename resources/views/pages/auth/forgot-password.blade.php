<x-layouts::auth :title="__('Esqueci minha senha')">

    <div class="ec-login ec-forgot-password">

        <div class="ec-login-header">

            <h1>
                Esqueceu sua senha?
            </h1>

            <p>
                Informe seu e-mail e enviaremos
                <br>
                um link para redefinir sua senha.
            </p>

        </div>


        @if (session('status'))

            <div class="ec-auth-status-success">
                {{ session('status') }}
            </div>

        @endif


        <form
            method="POST"
            action="{{ route('password.email') }}"
            class="ec-login-form"
        >

            @csrf


            <div>

                <label
                    for="email"
                    class="ec-login-label"
                >
                    E-mail
                </label>

                <div class="ec-login-field">

                    <span
                        class="ec-login-field-icon"
                        aria-hidden="true"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.7"
                        >
                            <rect
                                x="3"
                                y="5"
                                width="18"
                                height="14"
                                rx="2"
                            />

                            <path
                                d="m3 7 9 6 9-6"
                            />
                        </svg>
                    </span>

                    <input
                        id="email"
                        name="email"
                        type="email"
                        value="{{ old('email') }}"
                        required
                        autofocus
                        autocomplete="email"
                        placeholder="seu@email.com.br"
                        class="ec-login-input"
                    >

                </div>


                @error('email')

                    <p class="ec-login-error">
                        {{ $message }}
                    </p>

                @enderror

            </div>


            <button
                type="submit"
                class="ec-login-button"
                data-test="email-password-reset-link-button"
            >
                <span>
                    Enviar link de redefinição
                </span>

                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    aria-hidden="true"
                >
                    <path
                        d="M5 12h14"
                    />

                    <path
                        d="m14 7 5 5-5 5"
                    />
                </svg>
            </button>

        </form>


        <div class="ec-forgot-back">

            <a
                href="{{ route('login') }}"
                wire:navigate
            >
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    aria-hidden="true"
                >
                    <path
                        d="M19 12H5"
                    />

                    <path
                        d="m10 17-5-5 5-5"
                    />
                </svg>

                <span>
                    Voltar para o login
                </span>
            </a>

        </div>

    </div>

</x-layouts::auth>
