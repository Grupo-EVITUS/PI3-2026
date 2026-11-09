<?php
declare(strict_types=1);

namespace Saa\Controllers;

use Saa\Core\Clock;
use Saa\Core\Request;
use Saa\Core\Response;
use Saa\Domain\Profile;
use Saa\Services\SystemStatusService;

/** Tela inicial (GET /home) e o atalho GET / (redireciona para /home). */
final class HomeController extends Controller
{
    public function __construct(private readonly SystemStatusService $system)
    {
    }

    public function root(Request $request): Response
    {
        return $this->redirect('/home');
    }

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $shortcuts = [];
        foreach (nav_items() as $item) {
            if ($item['path'] === '/home') {
                continue;
            }
            $shortcuts[] = [
                'label' => $item['label'],
                'path' => $item['path'],
                'icon' => $item['icon'],
                'description' => $item['description'],
            ];
        }

        return $this->render('home/index', [
            'title' => 'Início',
            'eyebrow' => 'Visão geral',
            'activeNav' => '/home',
            'user' => $user,
            'greeting' => self::greeting((int)Clock::now()->format('G')),
            'shortcuts' => $shortcuts,
            // o card "Ambiente" (conexão com o MongoDB) é exclusivo do administrador
            'environment' => $user['profile'] === Profile::ADMIN->value ? $this->system->environment() : null,
        ]);
    }

    /** Saudação pelo horário escolar: 5h–11h59 Bom dia, 12h–17h59 Boa tarde, demais horários Boa noite. */
    public static function greeting(int $hour): string
    {
        return match (true) {
            $hour >= 5 && $hour < 12 => 'Bom dia',
            $hour >= 12 && $hour < 18 => 'Boa tarde',
            default => 'Boa noite',
        };
    }
}
