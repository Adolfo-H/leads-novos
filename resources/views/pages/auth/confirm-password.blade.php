<x-layouts::auth :title="'Confirme sua senha'">

    <div class="ec-login ec-confirm-password">

        <div class="ec-login-header">

            <div class="ec-confirm-password-icon">

                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.7"
                >
                    <path
                        d="
                            M12 3
                            19 6
                            v5
                            c0 5
                            -3 8
                            -7 10
                            -4-2
                            -7-5
                            -7-10
                            V6
                            Z
                        "
                    />

                    <path
                        d="m9 12 2 2 4-5"
                    />
                </svg>

            </div>

            <h1>
                Confirme sua senha
            </h1>

            <p>
                Esta é uma área protegida.
                <br>
                Confirme sua senha para continuar.
            </p>

        </div>


        <x-auth-session-status
            class="text-center"
            :status="session('status')"
        />


        <form
            method="POST"
            action="{{
                route(
                    'password.confirm.store'
                )
            }}"
            class="ec-login-form"
        >

            @csrf


            <div>

                <label
                    for="confirm-password"
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
                                    M8 10
                                    V7
                                    a4 4 0 0 1
                                    8 0
                                    v3
                                "
                            />
                        </svg>
                    </span>


                    <input
                        id="confirm-password"
                        name="password"
                        type="password"
                        required
                        autofocus
                        autocomplete="current-password"
                        placeholder="Sua senha"
                        class="ec-login-input"
                    >


                    <button
                        type="button"
                        class="ec-login-password-toggle"
                        aria-label="Mostrar ou ocultar senha"
                        onclick="
                            const input =
                                document.getElementById(
                                    'confirm-password'
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
                                    s3.5-6
                                    9.5-6
                                    9.5 6
                                    9.5 6
                                    -3.5 6
                                    -9.5 6
                                    -9.5-6
                                    -9.5-6
                                    Z
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

            </div>


            <button
                type="submit"
                class="ec-login-button"
                data-test="confirm-password-button"
            >

                <span>
                    Confirmar e continuar
                </span>

                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                >
                    <path d="M5 12h14" />
                    <path d="m14 7 5 5-5 5" />
                </svg>

            </button>

        </form>


        <div class="ec-confirm-password-note">

            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.7"
            >
                <circle
                    cx="12"
                    cy="12"
                    r="9"
                />

                <path d="M12 11v5" />
                <path d="M12 8h.01" />
            </svg>

            <span>
                A confirmação é solicitada antes
                de acessar configurações sensíveis.
            </span>

        </div>

    </div>

</x-layouts::auth>
