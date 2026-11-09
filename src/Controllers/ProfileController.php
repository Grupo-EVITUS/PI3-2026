<?php
declare(strict_types=1);

namespace Saa\Controllers;

use Saa\Core\Request;
use Saa\Core\Response;
use Saa\Domain\Profile;
use Saa\Security\Permissions;

/** GET /me (dados da conta e permissões do perfil) e GET /profiles (matriz de perfis e permissões). */
final class ProfileController extends Controller
{
    public function me(Request $request): Response
    {
        $user = $this->user($request);
        $permissions = [];
        foreach (Permissions::all() as $code => $description) {
            $permissions[] = [
                'code' => $code,
                'label' => $description,
                'granted' => Permissions::can($user['profile'], $code),
            ];
        }
        return $this->render('profile/me', [
            'title' => 'Meu perfil',
            'eyebrow' => 'Conta',
            'activeNav' => '/me',
            'user' => $user,
            'permissions' => $permissions,
        ]);
    }

    public function matrix(Request $request): Response
    {
        $profiles = [];
        foreach (Profile::cases() as $profile) {
            $profiles[] = [
                'code' => $profile->value,
                'label' => $profile->label(),
                'description' => $profile->description(),
                'login' => $profile->canLogin(),
            ];
        }
        return $this->render('profile/matrix', [
            'title' => 'Perfis e permissões',
            'eyebrow' => 'Segurança',
            'activeNav' => '/profiles',
            'profiles' => $profiles,
            'permissions' => Permissions::all(),
            'grants' => Permissions::grants(),
        ]);
    }
}
