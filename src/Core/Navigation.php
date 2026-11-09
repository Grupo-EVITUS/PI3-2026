<?php
declare(strict_types=1);

namespace Saa\Core;

use Saa\Security\Permissions;

/**
 * Menu lateral e atalhos a partir de config/navigation.php.
 *
 * Cada item: label, path, icon, permission (constante de Permissions ou null = qualquer autenticado), order,
 * description. Só aparece o que o perfil atual pode usar (Permissions::can) E o que já tem rota GET registrada
 * (Router::has) — telas de etapas futuras ficam invisíveis até existirem. O item cuja rota casa com o caminho
 * atual (o de prefixo mais longo) recebe active=true.
 */
final class Navigation
{
    /**
     * @param list<array<string, mixed>> $config conteúdo de config/navigation.php
     * @return list<array{label: string, path: string, icon: string, permission: string|null, order: int, description: string, active: bool}>
     */
    public static function items(array $config, ?string $profile, Router $router, string $currentPath): array
    {
        if ($profile === null) {
            return [];
        }
        $items = [];
        foreach ($config as $entry) {
            if (!is_array($entry) || !is_string($entry['label'] ?? null) || !is_string($entry['path'] ?? null)) {
                continue;
            }
            $permission = $entry['permission'] ?? null;
            if ($permission !== null && (!is_string($permission) || !Permissions::can($profile, $permission))) {
                continue;
            }
            if (!$router->has($entry['path'])) {
                continue;
            }
            $items[] = [
                'label' => $entry['label'],
                'path' => $entry['path'],
                'icon' => is_string($entry['icon'] ?? null) ? $entry['icon'] : 'info',
                'permission' => $permission,
                'order' => is_int($entry['order'] ?? null) ? $entry['order'] : 100,
                'description' => is_string($entry['description'] ?? null) ? $entry['description'] : '',
                'active' => false,
            ];
        }
        usort($items, static fn (array $a, array $b): int => [$a['order'], $a['label']] <=> [$b['order'], $b['label']]);

        $activeIndex = null;
        $activeLength = -1;
        foreach ($items as $index => $item) {
            $path = $item['path'];
            $matches = $currentPath === $path || ($path !== '/' && str_starts_with($currentPath, rtrim($path, '/') . '/'));
            if ($matches && strlen($path) > $activeLength) {
                $activeIndex = $index;
                $activeLength = strlen($path);
            }
        }
        if ($activeIndex !== null) {
            $items[$activeIndex]['active'] = true;
        }
        return $items;
    }

    /**
     * Lê o arquivo de configuração do menu (retorna array). Arquivo ausente ou inválido: lista vazia.
     *
     * @return list<array<string, mixed>>
     */
    public static function load(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $config = require $file;
        return is_array($config) ? array_values($config) : [];
    }
}
