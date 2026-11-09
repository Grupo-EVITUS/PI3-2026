<?php
declare(strict_types=1);

namespace Saa\Core;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Renderizador de templates PHP simples (views/<nome>.php), com layout opcional.
 *
 *   $view->render('auth/login', $dados, 'layouts/auth');
 *
 * O template recebe as chaves de $dados como variáveis. O layout recebe as mesmas variáveis mais $content
 * (HTML já renderizado do template) e $title (padrão "SAA"); $eyebrow, $pageActions e $activeNav valem null
 * quando não informados. As chaves "errors" (campo => mensagem) e "old" (campo => valor digitado) alimentam
 * os helpers field_error() e old().
 *
 * Todo valor dinâmico deve passar por e(); templates não consultam Models/Services.
 */
final class View
{
    private const LAYOUT_DEFAULTS = ['title' => 'SAA', 'eyebrow' => null, 'pageActions' => null, 'activeNav' => null];

    /** @var array<string, mixed> */
    private array $errors = [];
    /** @var array<string, mixed> */
    private array $old = [];

    /** @param string $viewsPath pasta dos templates (padrão: views/ na raiz do projeto) */
    public function __construct(private readonly string $viewsPath)
    {
    }

    public function path(): string
    {
        return $this->viewsPath;
    }

    public function exists(string $template): bool
    {
        return self::isValidName($template) && is_file($this->file($template));
    }

    /**
     * @param array<string, mixed> $data
     * @throws InvalidArgumentException nome de template inválido
     * @throws RuntimeException template inexistente
     */
    public function render(string $template, array $data = [], ?string $layout = null): string
    {
        $previous = [$this->errors, $this->old];
        $this->errors = is_array($data['errors'] ?? null) ? $data['errors'] : [];
        $this->old = is_array($data['old'] ?? null) ? $data['old'] : [];
        try {
            $content = $this->include($this->file($template), $data, $template);
            if ($layout === null) {
                return $content;
            }
            $layoutData = array_replace(self::LAYOUT_DEFAULTS, $data, ['content' => $content]);
            return $this->include($this->file($layout), $layoutData, $layout);
        } finally {
            [$this->errors, $this->old] = $previous;
        }
    }

    /** Renderiza views/partials/<nome>.php. */
    public function partial(string $name, array $data = []): string
    {
        return $this->include($this->file('partials/' . $name), $data, 'partials/' . $name);
    }

    /** Mensagem de erro do campo no formulário em exibição (null se não houver). */
    public function fieldError(string $field): ?string
    {
        $error = $this->errors[$field] ?? null;
        return is_string($error) && $error !== '' ? $error : null;
    }

    /** Valor digitado anteriormente para o campo (ou $default). */
    public function old(string $field, mixed $default = ''): mixed
    {
        return $this->old[$field] ?? $default;
    }

    // ------------------------------------------------------------------ internos

    private static function isValidName(string $template): bool
    {
        return preg_match('#^[A-Za-z0-9_][A-Za-z0-9_\-]*(/[A-Za-z0-9_][A-Za-z0-9_\-]*)*$#', $template) === 1;
    }

    private function file(string $template): string
    {
        if (!self::isValidName($template)) {
            throw new InvalidArgumentException('Nome de template inválido: ' . $template);
        }
        return rtrim($this->viewsPath, '/\\') . '/' . $template . '.php';
    }

    /** @param array<string, mixed> $data */
    private function include(string $file, array $data, string $name): string
    {
        if (!is_file($file)) {
            throw new RuntimeException('Template não encontrado: ' . $name);
        }
        $render = static function (string $__file, array $__data): void {
            extract($__data, EXTR_SKIP);
            require $__file;
        };
        $level = ob_get_level();
        ob_start();
        try {
            $render($file, $data);
            $output = ob_get_clean();
            return $output === false ? '' : $output;
        } catch (Throwable $error) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $error;
        }
    }
}
