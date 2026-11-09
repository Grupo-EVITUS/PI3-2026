<?php
declare(strict_types=1);

namespace Saa\Controllers;

use Saa\Core\Exceptions\AuthenticationException;
use Saa\Core\Exceptions\TooManyRequestsException;
use Saa\Core\Request;
use Saa\Core\Response;
use Saa\Core\Validator;
use Saa\Services\AuthService;
use Saa\Support\SafeRedirect;

/** Entrada e saída do sistema: GET/POST /login, POST /logout e a página informativa /forgot-password. */
final class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function showLogin(Request $request): Response
    {
        return $this->loginForm('', SafeRedirect::next($request->string('next')));
    }

    public function login(Request $request): Response
    {
        $email = $request->string('email') ?? '';
        $password = $request->string('password', false) ?? '';
        $next = SafeRedirect::next($request->string('next'));

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => 'o e-mail', 'password' => 'a senha']
        )->required('email', 'password');
        if ($validator->fails()) {
            return $this->loginForm($email, $next, null, $validator->errors(), 422);
        }

        try {
            $this->auth->attempt($email, $password, $request->ip());
        } catch (TooManyRequestsException $limit) {
            return $this->loginForm($email, $next, $limit->getMessage(), [], 429)
                ->withHeader('Retry-After', (string)$limit->retryAfterSeconds());
        } catch (AuthenticationException $failure) {
            return $this->loginForm($email, $next, $failure->getMessage(), [], 401);
        }
        return $this->redirect($next ?? SafeRedirect::DEFAULT);
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout($request->user(), $request->ip());
        $this->flash('success', 'Você saiu do sistema.');
        return $this->redirect('/login');
    }

    /** Página informativa: o fluxo completo de redefinição por e-mail chega na Etapa 3. */
    public function forgotPassword(Request $request): Response
    {
        return $this->render('auth/forgot', ['title' => 'Recuperar acesso'], 200, self::LAYOUT_AUTH);
    }

    /**
     * @param array<string, string> $errors
     */
    private function loginForm(string $email, ?string $next, ?string $error = null, array $errors = [], int $status = 200): Response
    {
        return $this->render('auth/login', [
            'title' => 'Entrar',
            'email' => mb_substr($email, 0, 254),
            'next' => $next,
            'error' => $error,
            'errors' => $errors,
            'old' => ['email' => mb_substr($email, 0, 254)],
        ], $status, self::LAYOUT_AUTH);
    }
}
