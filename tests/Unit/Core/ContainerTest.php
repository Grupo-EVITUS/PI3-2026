<?php
declare(strict_types=1);

namespace Saa\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Saa\Core\Container;
use Saa\Core\Exceptions\ContainerException;

interface ContainerFixtureGreeter
{
    public function greet(): string;
}

final class ContainerFixtureEnglish implements ContainerFixtureGreeter
{
    public function greet(): string
    {
        return 'hello';
    }
}

final class ContainerFixtureLeaf
{
}

final class ContainerFixtureMiddle
{
    public function __construct(public readonly ContainerFixtureLeaf $leaf)
    {
    }
}

final class ContainerFixtureTop
{
    public function __construct(public readonly ContainerFixtureMiddle $middle, public readonly ContainerFixtureLeaf $leaf)
    {
    }
}

final class ContainerFixtureWithDefaults
{
    public function __construct(
        public readonly string $label = 'padrao',
        public readonly int $size = 3,
        public readonly ?ContainerFixtureLeaf $optional = null,
        public readonly ?ContainerFixtureGreeter $nullable = null,
    ) {
    }
}

final class ContainerFixtureNeedsScalar
{
    public function __construct(public readonly string $name)
    {
    }
}

final class ContainerFixtureNeedsInterface
{
    public function __construct(public readonly ContainerFixtureGreeter $greeter)
    {
    }
}

final class ContainerFixtureCycleA
{
    public function __construct(public readonly ContainerFixtureCycleB $b)
    {
    }
}

final class ContainerFixtureCycleB
{
    public function __construct(public readonly ContainerFixtureCycleA $a)
    {
    }
}

abstract class ContainerFixtureAbstract
{
}

final class ContainerTest extends TestCase
{
    public function testAutowiresNestedDependenciesByConstructorType(): void
    {
        $top = (new Container())->get(ContainerFixtureTop::class);

        self::assertInstanceOf(ContainerFixtureTop::class, $top);
        self::assertInstanceOf(ContainerFixtureLeaf::class, $top->middle->leaf);
        self::assertInstanceOf(ContainerFixtureLeaf::class, $top->leaf);
    }

    public function testUnboundClassesAreCreatedFreshOnEveryGet(): void
    {
        $container = new Container();

        self::assertNotSame($container->get(ContainerFixtureLeaf::class), $container->get(ContainerFixtureLeaf::class));
    }

    public function testSingletonIsBuiltOnceAndShared(): void
    {
        $container = new Container();
        $calls = 0;
        $container->singleton(ContainerFixtureLeaf::class, static function () use (&$calls): ContainerFixtureLeaf {
            $calls++;
            return new ContainerFixtureLeaf();
        });

        $first = $container->get(ContainerFixtureLeaf::class);
        $second = $container->get(ContainerFixtureLeaf::class);
        $top = $container->get(ContainerFixtureTop::class);

        self::assertSame($first, $second);
        self::assertSame($first, $top->leaf, 'dependências do autowiring também usam o singleton');
        self::assertSame(1, $calls);
    }

    public function testBindMapsAnInterfaceToAConcreteClassName(): void
    {
        $container = new Container();
        $container->bind(ContainerFixtureGreeter::class, ContainerFixtureEnglish::class);

        $service = $container->get(ContainerFixtureNeedsInterface::class);

        self::assertSame('hello', $service->greeter->greet());
    }

    public function testClosureBindingReceivesTheContainer(): void
    {
        $container = new Container();
        $container->instance(ContainerFixtureLeaf::class, $leaf = new ContainerFixtureLeaf());
        $container->bind(ContainerFixtureMiddle::class, static fn (Container $c): ContainerFixtureMiddle => new ContainerFixtureMiddle($c->get(ContainerFixtureLeaf::class)));

        self::assertSame($leaf, $container->get(ContainerFixtureMiddle::class)->leaf);
    }

    public function testInstanceReturnsExactlyTheRegisteredObject(): void
    {
        $container = new Container();
        $container->instance(ContainerFixtureGreeter::class, $greeter = new ContainerFixtureEnglish());

        self::assertSame($greeter, $container->get(ContainerFixtureGreeter::class));
        self::assertTrue($container->isBound(ContainerFixtureGreeter::class));
    }

    public function testMakeAcceptsExplicitParametersByName(): void
    {
        $object = (new Container())->make(ContainerFixtureNeedsScalar::class, ['name' => 'Maria']);

        self::assertSame('Maria', $object->name);
    }

    public function testDefaultValuesAreUsedForScalarsAndOptionalObjectsAreNotAutowired(): void
    {
        $object = (new Container())->get(ContainerFixtureWithDefaults::class);

        self::assertSame('padrao', $object->label);
        self::assertSame(3, $object->size);
        self::assertNull($object->optional, 'dependência opcional só é injetada quando ligada explicitamente');
        self::assertNull($object->nullable);
    }

    public function testOptionalObjectsAreInjectedWhenExplicitlyBound(): void
    {
        $container = new Container();
        $container->instance(ContainerFixtureGreeter::class, $greeter = new ContainerFixtureEnglish());
        $container->singleton(ContainerFixtureLeaf::class);

        $object = $container->get(ContainerFixtureWithDefaults::class);

        self::assertSame($greeter, $object->nullable);
        self::assertInstanceOf(ContainerFixtureLeaf::class, $object->optional);
    }

    public function testUnresolvableScalarParameterThrowsAClearError(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('$name');

        (new Container())->get(ContainerFixtureNeedsScalar::class);
    }

    public function testCircularDependenciesAreDetected(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/circular/i');

        (new Container())->get(ContainerFixtureCycleA::class);
    }

    public function testCircularFactoryBindingsAreDetected(): void
    {
        $container = new Container();
        $container->bind('loop', static fn (Container $c): object => $c->get('loop'));

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/circular/i');

        $container->get('loop');
    }

    public function testUnboundInterfaceAndAbstractClassCannotBeBuilt(): void
    {
        $container = new Container();

        foreach ([ContainerFixtureGreeter::class, ContainerFixtureAbstract::class, 'Nao\\Existe\\Classe'] as $id) {
            try {
                $container->get($id);
                self::fail("{$id} não deveria ser resolvido");
            } catch (ContainerException $error) {
                self::assertStringContainsString('binding', $error->getMessage());
            }
        }
    }

    public function testHasReportsBindingsAndInstantiableClasses(): void
    {
        $container = new Container();
        $container->bind('servico', static fn (): object => new ContainerFixtureLeaf());

        self::assertTrue($container->has('servico'));
        self::assertTrue($container->has(ContainerFixtureLeaf::class));
        self::assertFalse($container->has(ContainerFixtureGreeter::class));
        self::assertFalse($container->has(ContainerFixtureAbstract::class));
        self::assertFalse($container->has('nao existe'));
    }

    public function testRebindingDiscardsTheOldSharedInstance(): void
    {
        $container = new Container();
        $container->singleton('x', static fn (): object => new ContainerFixtureLeaf());
        $old = $container->get('x');

        $container->singleton('x', static fn (): object => new ContainerFixtureLeaf());

        self::assertNotSame($old, $container->get('x'));
    }
}
