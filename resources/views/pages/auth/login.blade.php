<x-layouts::auth :title="__('Entrar')">

    <div class="ec-login">

        <div class="ec-login-header">

            <h1>
                Bem-vindo
            </h1>

            <p>
                Entre com sua conta para acessar o Prospector ExportControl.
            </p>

        </div>


        <x-auth-session-status
            class="text-center"
            :status="session('status')"
        />


        <x-passkey-verify />


        <form
            method="POST"
            action="{{ route('login.store') }}"
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

                @error('email')

                    <p class="ec-login-error">
                        {{ $message }}
                    </p>

                @enderror

            </div>


            <div>

                <div class="ec-login-password-head">

                    <label
                        for="password"
                        class="ec-login-label"
                    >
                        Senha
                    </label>

                    @if (Route::has('password.request'))

                        <a
                            href="{{ route('password.request') }}"
                            wire:navigate
                        >
                            Esqueci minha senha
                        </a>

                    @endif

                </div>


                <input
                    id="password"
                    name="password"
                    type="password"
                    required
                    autocomplete="current-password"
                    placeholder="Digite sua senha"
                    class="ec-login-input"
                >

                @error('password')

                    <p class="ec-login-error">
                        {{ $message }}
                    </p>

                @enderror

            </div>


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


            <button
                type="submit"
                class="ec-login-button"
                data-test="login-button"
            >
                Entrar
            </button>

        </form>


        @if (Route::has('register'))

            <div class="ec-login-register">

                <span>
                    Não possui uma conta?
                </span>

                <a
                    href="{{ route('register') }}"
                    wire:navigate
                >
                    Criar conta
                </a>

            </div>

        @endif

    </div>

</x-layouts::auth>
