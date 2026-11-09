<?php
declare(strict_types=1);

use Saa\Security\Permissions;

/**
 * Itens do menu lateral e dos atalhos da tela inicial (issue #16).
 *
 * Lido por nav_items(), que só devolve o item quando:
 *   1. o perfil do usuário tem a permissão ('permission' = constante de Permissions; null = qualquer usuário autenticado); e
 *   2. a rota GET do 'path' existe (Router::has) — assim o menu cresce sozinho conforme as etapas entregam as telas.
 *
 * Campos: label (texto do menu), path, icon (id do sprite public/assets/img/icons.svg, sem "icon-"),
 * permission, order (ordem crescente) e description (frase curta mostrada no atalho da tela inicial).
 *
 * @return list<array{label:string,path:string,icon:string,permission:?string,order:int,description:string}>
 */
return [
    [
        'label' => 'Início',
        'path' => '/home',
        'icon' => 'home',
        'permission' => null,
        'order' => 10,
        'description' => 'Resumo do seu acesso e atalhos para as áreas do sistema.',
    ],
    [
        'label' => 'Painel',
        'path' => '/dashboard',
        'icon' => 'dashboard',
        'permission' => Permissions::DASHBOARD_VIEW,
        'order' => 20,
        'description' => 'Indicadores de ausência por turma, mês e ano para acompanhar a frequência.',
    ],
    [
        'label' => 'Registrar ausência',
        'path' => '/absences',
        'icon' => 'absences',
        'permission' => Permissions::ABSENCES_REGISTER,
        'order' => 30,
        'description' => 'Registre a falta de um aluno e avise os responsáveis por e-mail.',
    ],
    [
        'label' => 'Ocorrências',
        'path' => '/occurrences',
        'icon' => 'occurrences',
        'permission' => Permissions::OCCURRENCES_VIEW,
        'order' => 40,
        'description' => 'Consulte ausências, e-mails enviados e justificativas pendentes ou respondidas.',
    ],
    [
        'label' => 'Alunos',
        'path' => '/students',
        'icon' => 'students',
        'permission' => Permissions::REGISTRY_VIEW,
        'order' => 50,
        'description' => 'Cadastro de alunos, matrículas e responsáveis vinculados.',
    ],
    [
        'label' => 'Responsáveis',
        'path' => '/guardians',
        'icon' => 'guardians',
        'permission' => Permissions::REGISTRY_VIEW,
        'order' => 60,
        'description' => 'Contatos dos responsáveis e situação dos e-mails cadastrados.',
    ],
    [
        'label' => 'Turmas',
        'path' => '/classes',
        'icon' => 'classes',
        'permission' => Permissions::REGISTRY_VIEW,
        'order' => 70,
        'description' => 'Turmas do período letivo e os alunos matriculados em cada uma.',
    ],
    [
        'label' => 'Usuários',
        'path' => '/users',
        'icon' => 'users',
        'permission' => Permissions::USERS_MANAGE,
        'order' => 80,
        'description' => 'Cadastre usuários internos e defina o perfil de cada um.',
    ],
    [
        'label' => 'Meu perfil',
        'path' => '/me',
        'icon' => 'profile',
        'permission' => null,
        'order' => 90,
        'description' => 'Seus dados de acesso e o que o seu perfil permite fazer.',
    ],
    [
        'label' => 'Perfis e permissões',
        'path' => '/profiles',
        'icon' => 'shield',
        'permission' => null,
        'order' => 100,
        'description' => 'Matriz com o que cada perfil pode fazer no sistema.',
    ],
];
