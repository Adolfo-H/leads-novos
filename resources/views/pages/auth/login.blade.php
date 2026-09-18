<x-layouts::auth :title="__('Entrar')">

    <div class="ec-login">

        <div class="ec-login-header">

            <h1>
                Bem-vindo
            </h1>

            <p>
                Acesse sua conta para continuar no
                <br>
                ExportControl Prospector.
            </p>

        </div>


        <x-auth-session-status
            class="text-center"
            :status="session('status')"
        />


        <form
            method="POST"
            action="{{ route('login.store') }}"
            class="ec-login-form"
        >

            @csrf


            {{-- E-MAIL --}}
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
                        placeholder="E-mail"
                        class="ec-login-input"
                    >

                </div>

                @error('email')
                    <p class="ec-login-error">
                        {{ $message }}
                    </p>
                @enderror

            </div>


            {{-- SENHA --}}
            <div>

                <label
                    for="password"
                    class="ec-login-label"
                >
                    Senha
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
                                x="5"
                                y="10"
                                width="14"
                                height="11"
                                rx="2"
                            />

                            <path
                                d="
                                    M8 10V7
                                    a4 4 0 0 1 8 0
                                    v3
                                "
                            />
                        </svg>
                    </span>

                    <input
                        id="password"
                        name="password"
                        type="password"
                        required
                        autocomplete="current-password"
                        placeholder="Senha"
                        class="ec-login-input"
                    >

                    <button
                        type="button"
                        class="ec-login-password-toggle"
                        aria-label="Mostrar ou ocultar senha"
                        onclick="
                            const input =
                                document.getElementById(
                                    'password'
                                );

                            input.type =
                                input.type === 'password'
                                    ? 'text'
                                    : 'password';
                        "
                    >
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.7"
                        >
                            <path
                                d="
                                    M2.5 12
                                    s3.5-6 9.5-6
                                    9.5 6 9.5 6
                                    -3.5 6-9.5 6
                                    -9.5-6-9.5-6Z
                                "
                            />

                            <circle
                                cx="12"
                                cy="12"
                                r="2.5"
                            />
                        </svg>
                    </button>

                </div>

                @error('password')
                    <p class="ec-login-error">
                        {{ $message }}
                    </p>
                @enderror

            </div>


            <div class="ec-login-meta">

                <label class="ec-login-remember">

                    <input
                        type="checkbox"
                        name="remember"
                        @checked(old('remember'))
                    >

                    <span>
                        Manter conectado
                    </span>

                </label>


                @if (Route::has('password.request'))

                    <a
                        href="{{ route('password.request') }}"
                        wire:navigate
                        class="ec-login-forgot"
                    >
                        Esqueci minha senha
                    </a>

                @endif

            </div>


            <button
                type="submit"
                class="ec-login-button"
                data-test="login-button"
            >
                <span>
                    Entrar
                </span>

                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    aria-hidden="true"
                >
                    <path d="M5 12h14" />
                    <path d="m14 7 5 5-5 5" />
                </svg>
            </button>

        </form>

    </div>

</x-layouts::auth>
