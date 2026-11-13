<?php
declare(strict_types=1);

namespace Saa\Controllers\Api;

use Saa\Core\Csrf;
use Saa\Core\Request;
use Saa\Core\Response;
use Saa\Domain\Profile;
use Saa\Security\Permissions;

/**
 * GET /api/me (qualquer usuário autenticado): quem está na sessão, o perfil, as permissões e o token CSRF que o
 * cliente deve mandar no cabeçalho X-CSRF-Token em toda escrita (POST/PATCH/DELETE).
 */
final class MeController extends ApiController
{
    public function __construct(private readonly Csrf $csrf)
    {
    }

    public function show(Request $request): Response
    {
        $user = $this->user($request);
        return $this->json([
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'profile' => $user['profile'],
                'profile_label' => Profile::tryFrom($user['profile'])?->label() ?? $user['profile'],
            ],
            'permissions' => Permissions::forProfile($user['profile']),
            'csrf_token' => $this->csrf->token(),
        ]);
    }
}
