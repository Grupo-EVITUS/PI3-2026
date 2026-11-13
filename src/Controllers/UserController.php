<?php
declare(strict_types=1);

namespace Saa\Controllers;

use Saa\Core\Exceptions\ValidationException;
use Saa\Core\Request;
use Saa\Core\Response;
use Saa\Domain\Profile;
use Saa\Services\UserService;

/**
 * Usuários internos (issues #36 e #37), somente ADMIN (users.manage):
 * GET/POST /users, GET/POST /users/{id} e POST /users/{id}/password.
 */
final class UserController extends Controller
{
    public function __construct(private readonly UserService $users)
    {
    }

    public function index(Request $request): Response
    {
        return $this->listPage($request);
    }

    public function store(Request $request): Response
    {
        try {
            $user = $this->users->create([
                'name' => $request->string('name'),
                'email' => $request->string('email'),
                'profile' => $request->string('profile'),
                'password' => $request->string('password', false),
                'password_confirmation' => $request->string('password_confirmation', false),
            ], $this->user($request));
        } catch (ValidationException $error) {
            return $this->listPage($request, ['errors' => $error->errors(), 'old' => $this->oldInput($request), 'openForm' => true], 422);
        }
        $this->flash('success', 'Usuário ' . $user['name'] . ' cadastrado com o perfil ' . Profile::labelFor($user['profile']) . '.');
        return $this->redirect('/users/' . $user['id']);
    }

    public function edit(Request $request): Response
    {
        return $this->editPage($request, $this->users->get((string)$request->param('id')));
    }

    public function update(Request $request): Response
    {
        $user = $this->users->get((string)$request->param('id'));
        try {
            $updated = $this->users->update($user['id'], [
                'name' => $request->string('name'),
                'profile' => $request->string('profile'),
                'active' => $request->string('active'),
            ], $this->user($request));
        } catch (ValidationException $error) {
            return $this->editPage($request, $user, ['errors' => $error->errors(), 'old' => $this->oldInput($request), 'activeForm' => 'account'], 422);
        }
        $this->flash('success', 'Dados de ' . $updated['name'] . ' atualizados.');
        return $this->redirect('/users/' . $updated['id']);
    }

    public function password(Request $request): Response
    {
        $user = $this->users->get((string)$request->param('id'));
        try {
            $this->users->resetPassword($user['id'], [
                'password' => $request->string('password', false),
                'password_confirmation' => $request->string('password_confirmation', false),
            ], $this->user($request));
        } catch (ValidationException $error) {
            return $this->editPage($request, $user, ['errors' => $error->errors(), 'activeForm' => 'password'], 422);
        }
        $this->flash('success', 'Nova senha definida para ' . $user['name'] . '. Informe-a à pessoa por um canal seguro.');
        return $this->redirect('/users/' . $user['id']);
    }

    // ------------------------------------------------------------------ internos

    /** @param array<string, mixed> $extra */
    private function listPage(Request $request, array $extra = [], int $status = 200): Response
    {
        $list = $this->users->list(
            $request->method() === 'GET' ? $request->string('q') : null,
            $request->method() === 'GET' ? $request->string('profile') : null,
            $request->method() === 'GET' ? $request->string('status') : null,
            $request->int('page', 1) ?? 1
        );
        return $this->render('users/index', array_replace([
            'title' => 'Usuários',
            'eyebrow' => 'Administração',
            'activeNav' => '/users',
            'users' => $list['items'],
            'pagination' => $list['pagination'],
            'filters' => $list['filters'],
            'profileOptions' => UserService::profileOptions(),
            'openForm' => false,
        ], $extra), $status);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $extra
     */
    private function editPage(Request $request, array $user, array $extra = [], int $status = 200): Response
    {
        return $this->render('users/edit', array_replace([
            'title' => $user['name'],
            'eyebrow' => 'Usuários',
            'activeNav' => '/users',
            'account' => $user,
            'isSelf' => $this->user($request)['id'] === $user['id'],
            'profileOptions' => UserService::profileOptions(),
            'activeForm' => null,
        ], $extra), $status);
    }
}
