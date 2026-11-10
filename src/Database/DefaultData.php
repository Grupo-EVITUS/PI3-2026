<?php
declare(strict_types=1);

namespace Saa\Database;

use MongoDB\Database;
use Saa\Models\MessageTemplateModel;

/**
 * Dados padrão que o sistema precisa para funcionar (não são dados de demonstração). Idempotente: só cria o que
 * falta e NUNCA altera o que já existe (a escola pode ter editado o texto). Chamado por bin/init_db.php depois do
 * Schema.
 *
 * Hoje: o modelo de e-mail ABSENCE_NOTICE (aviso de ausência ao responsável, issue #29).
 */
final class DefaultData
{
    public const ABSENCE_NOTICE = 'ABSENCE_NOTICE';

    public const ABSENCE_NOTICE_SUBJECT = 'Comunicado de ausência: {{student_name}} ({{absence_date}})';

    public const ABSENCE_NOTICE_BODY = <<<'TXT'
Olá, {{guardian_name}}.

Informamos que {{student_name}}, da turma {{class_name}}, não compareceu às aulas em {{absence_date}}.

{{note}}

Se quiser informar o motivo da ausência, use o link individual abaixo. Ele é pessoal, pode ser usado uma única vez e vale até {{expires_at}}:
{{justification_link}}

Se você já conversou com a escola sobre esta ausência, pode desconsiderar esta mensagem.

Atenciosamente,
{{school_name}}
TXT;

    /** @var list<string> variáveis usadas no modelo padrão */
    public const ABSENCE_NOTICE_VARIABLES = [
        'guardian_name', 'student_name', 'class_name', 'absence_date', 'note', 'justification_link', 'expires_at', 'school_name',
    ];

    /**
     * Garante os dados padrão.
     *
     * @return list<string> descrição do que foi criado agora (vazio se já estava tudo lá)
     */
    public static function apply(Database $database): array
    {
        $created = [];
        $templates = new MessageTemplateModel($database);
        if ($templates->insertIfMissing(self::ABSENCE_NOTICE, self::ABSENCE_NOTICE_SUBJECT, self::ABSENCE_NOTICE_BODY, self::ABSENCE_NOTICE_VARIABLES)) {
            $created[] = 'modelo de e-mail ' . self::ABSENCE_NOTICE;
        }
        return $created;
    }
}
