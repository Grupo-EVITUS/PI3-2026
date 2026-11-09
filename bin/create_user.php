<?php
declare(strict_types=1);

/**
 * Cria um usuário interno (login do SAA) — uso único, linha de comando, sem sessão nem HTTP.
 *
 *     php bin/create_user.php --name "Maria Souza" --email maria@escola.local --profile ADMIN
 *
 * Perfis com conta: ADMIN, COORDINATION, AOE, SECRETARY (GUARDIAN não tem login: o responsável usa o link do e-mail).
 * A senha NUNCA é passada na linha de comando (apareceria no histórico e na lista de processos): defina
 * INITIAL_USER_PASSWORD no ambiente (mínimo de 10 caracteres) ou deixe em branco para gerar uma senha aleatória
 * forte, exibida uma única vez.
 *
 * Códigos de saída: 0 = criado; 1 = erro (dados inválidos, e-mail repetido, MongoDB indisponível); 2 = uso incorreto.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Saa\Core\App;
use Saa\Core\Config;
use Saa\Core\Exceptions\ConflictException;
use Saa\Core\Exceptions\ValidationException;
use Saa\Domain\Profile;
use Saa\Services\AuthService;

const EXIT_OK = 0;
const EXIT_ERROR = 1;
const EXIT_USAGE = 2;

function fail(string $message, int $code): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
}

function usage(): string
{
    return <<<'TEXT'
    Uso: php bin/create_user.php --name "Nome Completo" --email usuario@escola.local --profile PERFIL

    Perfis: ADMIN, COORDINATION, AOE, SECRETARY
    Senha:  variável de ambiente INITIAL_USER_PASSWORD (mínimo 10 caracteres). Sem ela, uma senha aleatória
            forte é gerada e exibida uma única vez.

    Exemplo (PowerShell):  $env:INITIAL_USER_PASSWORD = "uma-senha-forte"; php bin/create_user.php --name "Admin" --email admin@escola.local --profile ADMIN
    TEXT;
}

function generatePassword(int $length = 20): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789-_@#%';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $password;
}

$options = getopt('', ['name:', 'email:', 'profile:', 'help']);
if ($options === false || isset($options['help'])) {
    fwrite(isset($options['help']) ? STDOUT : STDERR, usage() . PHP_EOL);
    exit(isset($options['help']) ? EXIT_OK : EXIT_USAGE);
}

$name = isset($options['name']) && is_string($options['name']) ? trim($options['name']) : '';
$email = isset($options['email']) && is_string($options['email']) ? trim($options['email']) : '';
$profileCode = isset($options['profile']) && is_string($options['profile']) ? strtoupper(trim($options['profile'])) : '';
if ($name === '' || $email === '' || $profileCode === '') {
    fail("Informe --name, --email e --profile.\n\n" . usage(), EXIT_USAGE);
}
if ($profileCode === Profile::GUARDIAN->value) {
    fail('O perfil GUARDIAN (Responsável) não tem conta de acesso: ele justifica pelo link recebido por e-mail. Use um destes perfis: ' . implode(', ', Profile::loginValues()) . '.', EXIT_USAGE);
}
if (!in_array($profileCode, Profile::loginValues(), true)) {
    fail('Perfil inválido: ' . $profileCode . '. Use um destes: ' . implode(', ', Profile::loginValues()) . '.', EXIT_USAGE);
}

$password = Config::get('INITIAL_USER_PASSWORD');
$generated = $password === null || $password === '';
if ($generated) {
    $password = generatePassword();
}

try {
    $app = App::boot();
    $user = $app->container()->get(AuthService::class)->createUser([
        'name' => $name,
        'email' => $email,
        'password' => $password,
        'profile' => $profileCode,
    ]);
} catch (ValidationException $error) {
    $lines = [];
    foreach ($error->errors() as $field => $message) {
        $lines[] = '  - ' . $field . ': ' . $message;
    }
    fail("Dados inválidos:\n" . implode("\n", $lines), EXIT_ERROR);
} catch (ConflictException $error) {
    fail('Não foi possível criar o usuário: ' . $error->getMessage(), EXIT_ERROR);
} catch (Throwable $error) {
    fail('Erro ao criar o usuário: ' . $error->getMessage(), EXIT_ERROR);
}

fwrite(STDOUT, 'Usuário criado com sucesso.' . PHP_EOL);
fwrite(STDOUT, '  Id:     ' . $user['id'] . PHP_EOL);
fwrite(STDOUT, '  Nome:   ' . $user['name'] . PHP_EOL);
fwrite(STDOUT, '  E-mail: ' . $user['email'] . PHP_EOL);
fwrite(STDOUT, '  Perfil: ' . Profile::labelFor($user['profile']) . ' (' . $user['profile'] . ')' . PHP_EOL);
if ($generated) {
    fwrite(STDOUT, PHP_EOL . 'Senha gerada (exibida uma única vez; guarde-a em local seguro): ' . $password . PHP_EOL);
}
exit(EXIT_OK);
