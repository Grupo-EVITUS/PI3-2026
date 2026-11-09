<?php
declare(strict_types=1);

namespace Saa\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saa\Core\Exceptions\ValidationException;
use Saa\Core\Validator;
use Saa\Domain\Profile;

final class ValidatorTest extends TestCase
{
    public function testRequiredReportsMissingBlankAndEmptyValues(): void
    {
        $validator = Validator::make(['a' => '', 'b' => '   ', 'c' => null, 'd' => [], 'e' => 'ok', 'f' => '0'])
            ->required('a', 'b', 'c', 'd', 'e', 'f', 'ausente');

        self::assertSame(['a', 'b', 'c', 'd', 'ausente'], array_keys($validator->errors()));
        self::assertSame('Este campo é obrigatório.', $validator->errors()['a']);
        self::assertFalse($validator->passes());
        self::assertTrue($validator->fails());
        self::assertSame(['e' => 'ok', 'f' => '0'], $validator->validated(), '"0" é um valor válido');
    }

    public function testRequiredUsesTheFieldLabelWhenProvided(): void
    {
        $validator = Validator::make([], ['name' => 'o nome', 'email' => 'o e-mail'])->required('name', 'email', 'outro');

        self::assertSame('Informe o nome.', $validator->errors()['name']);
        self::assertSame('Informe o e-mail.', $validator->errors()['email']);
        self::assertSame('Este campo é obrigatório.', $validator->errors()['outro']);
    }

    public function testOptionalRulesIgnoreBlankFields(): void
    {
        $validator = Validator::make(['email' => '', 'date' => '  ', 'name' => null, 'list' => []])
            ->email('email')->date('date')->string('name', 10, 3)->in('list', ['a'])->objectId('x')->integer('y');

        self::assertTrue($validator->passes());
        self::assertSame([], $validator->validated());
    }

    public function testStringRuleTrimsAndChecksLength(): void
    {
        $validator = Validator::make(['ok' => '  Ana Maria  ', 'short' => 'ab', 'long' => str_repeat('x', 11), 'accent' => 'ççççç'])
            ->string('ok', 20, 3)->string('short', 20, 3)->string('long', 10)->string('accent', 5, 5);

        self::assertSame(['short' => 'Use pelo menos 3 caracteres.', 'long' => 'Use no máximo 10 caracteres.'], $validator->errors());
        self::assertSame(['ok' => 'Ana Maria', 'accent' => 'ççççç'], $validator->validated(), 'comprimento em caracteres, não em bytes');
    }

    public function testStringRuleRejectsArraysControlCharactersAndInvalidUtf8(): void
    {
        $validator = Validator::make([
            'array' => ['$ne' => 'x'], 'nul' => "ab\0c", 'newline' => "linha1\nlinha2", 'escape' => "a\x1bb", 'utf8' => "ab\xB1cd", 'int' => 5,
        ])->string('array')->string('nul')->string('newline')->string('escape')->string('utf8')->string('int');

        self::assertSame(['array', 'nul', 'newline', 'escape', 'utf8', 'int'], array_keys($validator->errors()));
        self::assertSame('Valor inválido.', $validator->errors()['array']);
        self::assertSame('Contém caracteres inválidos.', $validator->errors()['newline']);
    }

    public function testMultilineStringKeepsLineBreaksButNotOtherControlCharacters(): void
    {
        $validator = Validator::make(['text' => "linha1\r\nlinha2\ttab", 'bad' => "a\0b"])
            ->string('text', 100, 0, true)->string('bad', 100, 0, true);

        self::assertSame("linha1\nlinha2\ttab", $validator->validated()['text']);
        self::assertArrayHasKey('bad', $validator->errors());
    }

    /** @return array<string, array{mixed, bool}> */
    public static function emails(): array
    {
        return [
            'simple' => ['maria@escola.local', true],
            'plus' => ['maria+aviso@escola.com.br', true],
            'trim' => ['  maria@escola.com  ', true],
            'no at' => ['maria.escola.com', false],
            'no domain' => ['maria@', false],
            'space inside' => ['ma ria@escola.com', false],
            'two ats' => ['a@b@c.com', false],
            'too long' => [str_repeat('a', 250) . '@x.com', false],
            'array' => [['a@b.com'], false],
            'newline injection' => ["a@b.com\nBcc: x@y.com", false],
        ];
    }

    #[DataProvider('emails')]
    public function testEmailRule(mixed $value, bool $valid): void
    {
        $validator = Validator::make(['email' => $value])->email('email');

        self::assertSame($valid, $validator->passes(), json_encode($value));
        if ($valid) {
            self::assertSame(trim((string)$value), $validator->validated()['email']);
        } else {
            self::assertArrayHasKey('email', $validator->errors());
            self::assertContains($validator->errors()['email'], ['Informe um e-mail válido.', 'Valor inválido.']);
        }
    }

    public function testInRuleIsStrictAboutTheAllowedList(): void
    {
        $profiles = Profile::loginValues();
        $validator = Validator::make(['ok' => 'AOE', 'guardian' => 'GUARDIAN', 'lower' => 'aoe', 'array' => ['AOE'], 'int' => 1, 'spaces' => ' ADMIN '])
            ->in('ok', $profiles)->in('guardian', $profiles)->in('lower', $profiles)->in('array', $profiles)->in('int', ['1', '2'])->in('spaces', $profiles);

        self::assertSame(['guardian', 'lower', 'array'], array_keys($validator->errors()));
        self::assertSame('Selecione uma opção válida.', $validator->errors()['guardian']);
        self::assertSame(['ok' => 'AOE', 'int' => '1', 'spaces' => 'ADMIN'], $validator->validated());
    }

    public function testDateRuleAcceptsRealCalendarDaysOnly(): void
    {
        $validator = Validator::make(['ok' => '2026-02-28', 'leap' => '2028-02-29', 'bad_leap' => '2026-02-29', 'month' => '2026-13-01', 'format' => '28/02/2026', 'text' => 'amanhã', 'array' => ['2026-01-01']])
            ->date('ok')->date('leap')->date('bad_leap')->date('month')->date('format')->date('text')->date('array');

        self::assertSame(['bad_leap', 'month', 'format', 'text', 'array'], array_keys($validator->errors()));
        self::assertSame('Informe uma data válida.', $validator->errors()['month']);
        self::assertSame(['ok' => '2026-02-28', 'leap' => '2028-02-29'], $validator->validated());
    }

    public function testObjectIdRuleNormalizesToLowercaseHex(): void
    {
        $validator = Validator::make(['ok' => '507F1F77BCF86CD799439011', 'short' => '507f1f77', 'op' => ['$ne' => '1'], 'text' => 'zzzzzzzzzzzzzzzzzzzzzzzz'])
            ->objectId('ok')->objectId('short')->objectId('op')->objectId('text');

        self::assertSame(['short', 'op', 'text'], array_keys($validator->errors()));
        self::assertSame('Seleção inválida.', $validator->errors()['short']);
        self::assertSame('507f1f77bcf86cd799439011', $validator->validated()['ok']);
    }

    public function testPasswordRuleChecksLengthWithoutTrimming(): void
    {
        $validator = Validator::make([
            'ok' => 'abcdefghij', 'spaces' => '  abcdefgh  ', 'short' => 'abc123', 'accents' => 'ççççççççç', 'huge' => str_repeat('a', 73), 'nul' => "abcdefghij\0",
        ])->password('ok')->password('spaces')->password('short')->password('accents')->password('huge')->password('nul');

        self::assertSame(['short', 'accents', 'huge', 'nul'], array_keys($validator->errors()));
        self::assertSame('A senha deve ter pelo menos 10 caracteres.', $validator->errors()['short']);
        self::assertSame('A senha deve ter no máximo 72 caracteres.', $validator->errors()['huge']);
        self::assertSame('  abcdefgh  ', $validator->validated()['spaces'], 'senhas não são aparadas');
    }

    public function testIntegerRule(): void
    {
        $validator = Validator::make(['ok' => '15', 'native' => 7, 'neg' => '-3', 'low' => '0', 'high' => '101', 'text' => '1.5', 'array' => [1]])
            ->integer('ok', 1, 100)->integer('native')->integer('neg')->integer('low', 1)->integer('high', null, 100)->integer('text')->integer('array');

        self::assertSame(['low', 'high', 'text', 'array'], array_keys($validator->errors()));
        self::assertSame(['ok' => 15, 'native' => 7, 'neg' => -3], $validator->validated());
    }

    public function testOnlyTheFirstErrorOfEachFieldIsKept(): void
    {
        $validator = Validator::make(['email' => ''], ['email' => 'o e-mail'])
            ->required('email')->email('email')->string('email', 5)->addError('email', 'Outra mensagem.');

        self::assertSame(['email' => 'Informe o e-mail.'], $validator->errors());
    }

    public function testAddErrorSupportsBusinessRules(): void
    {
        $validator = Validator::make(['code' => 'RA1'])->string('code', 10)->addError('code', 'Matrícula já cadastrada.');

        self::assertSame(['code' => 'Matrícula já cadastrada.'], $validator->errors());
        self::assertSame([], $validator->validated(), 'campo com erro não aparece nos valores validados');
    }

    public function testValidateReturnsNormalizedValuesOrThrows(): void
    {
        $data = Validator::make(['name' => '  Ana  ', 'email' => ' ana@escola.com ', 'extra' => 'ignorado'])
            ->required('name', 'email')->string('name', 50)->email('email')->validate();
        self::assertSame(['name' => 'Ana', 'email' => 'ana@escola.com'], $data);

        try {
            Validator::make([], ['name' => 'o nome'])->required('name')->validate();
            self::fail('deveria lançar ValidationException');
        } catch (ValidationException $error) {
            self::assertSame(422, $error->status());
            self::assertSame(['name' => 'Informe o nome.'], $error->errors());
            self::assertSame('validation_error', $error->errorCode());
        }
    }

    public function testRequestShapedInputWithOperatorsFailsEveryTypedRule(): void
    {
        $input = ['email' => ['$ne' => ''], 'name' => ['$gt' => ''], 'profile' => ['$in' => ['ADMIN']], 'id' => ['$ne' => null]];

        $validator = Validator::make($input)->string('name')->email('email')->in('profile', Profile::loginValues())->objectId('id');

        self::assertSame(['name', 'email', 'profile', 'id'], array_keys($validator->errors()));
        self::assertSame([], $validator->validated());
    }
}
