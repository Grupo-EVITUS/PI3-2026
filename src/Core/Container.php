<?php
declare(strict_types=1);

namespace Saa\Core;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use Saa\Core\Exceptions\ContainerException;

/**
 * Container de dependências com autowiring pelo tipo dos parâmetros do construtor.
 *
 * Regras de resolução de cada parâmetro (nesta ordem):
 *  1. valor informado explicitamente em make($classe, ['nome' => valor]);
 *  2. tipo de classe/interface: se houver binding ou instância registrada, usa-a; senão usa o valor
 *     padrão do parâmetro; senão, se aceitar null, injeta null (dependências opcionais só são injetadas
 *     quando ligadas de forma explícita); senão tenta construir a classe (autowiring);
 *  3. tipos escalares/união: valor padrão, ou null se permitido; caso contrário, ContainerException.
 *
 * Classes sem binding são criadas a cada get() (sem compartilhamento). Use singleton()/instance()
 * para serviços compartilhados (conexão, sessão, roteador...).
 */
final class Container
{
    /** @var array<string, array{concrete: Closure|string|null, shared: bool}> */
    private array $bindings = [];
    /** @var array<string, object> */
    private array $instances = [];
    /** @var array<string, true> */
    private array $resolving = [];

    /**
     * @param Closure(Container): object|string|null $concrete fábrica, nome de classe concreta (alias) ou null (a própria classe)
     */
    public function bind(string $id, Closure|string|null $concrete = null, bool $shared = false): void
    {
        unset($this->instances[$id]);
        $this->bindings[$id] = ['concrete' => $concrete, 'shared' => $shared];
    }

    /** @param Closure(Container): object|string|null $concrete */
    public function singleton(string $id, Closure|string|null $concrete = null): void
    {
        $this->bind($id, $concrete, true);
    }

    public function instance(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
    }

    /** true se há binding ou instância registrada explicitamente para o identificador. */
    public function isBound(string $id): bool
    {
        return isset($this->bindings[$id]) || isset($this->instances[$id]);
    }

    /** true se o identificador está ligado ou é uma classe que pode ser construída. */
    public function has(string $id): bool
    {
        if ($this->isBound($id)) {
            return true;
        }
        return class_exists($id) && (new ReflectionClass($id))->isInstantiable();
    }

    /**
     * @template T of object
     * @param class-string<T>|string $id
     * @return ($id is class-string<T> ? T : object)
     */
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (!isset($this->bindings[$id])) {
            return $this->make($id);
        }
        if (isset($this->resolving['binding:' . $id])) {
            throw new ContainerException('Dependência circular ao resolver ' . $id . '.');
        }
        $this->resolving['binding:' . $id] = true;
        try {
            $binding = $this->bindings[$id];
            $concrete = $binding['concrete'];
            if ($concrete instanceof Closure) {
                $object = $concrete($this);
                if (!is_object($object)) {
                    throw new ContainerException('A fábrica de ' . $id . ' não devolveu um objeto.');
                }
            } elseif (is_string($concrete) && $concrete !== $id) {
                $object = $this->get($concrete);
            } else {
                $object = $this->make($id);
            }
            if ($binding['shared']) {
                $this->instances[$id] = $object;
            }
            return $object;
        } finally {
            unset($this->resolving['binding:' . $id]);
        }
    }

    /**
     * Cria uma nova instância de $class resolvendo as dependências do construtor.
     *
     * @template T of object
     * @param class-string<T>|string $class
     * @param array<string, mixed> $parameters valores explícitos por nome de parâmetro
     * @return ($class is class-string<T> ? T : object)
     */
    public function make(string $class, array $parameters = []): object
    {
        if (!class_exists($class)) {
            $kind = interface_exists($class) ? 'A interface' : 'A classe ou serviço';
            throw new ContainerException($kind . ' ' . $class . ' não pode ser resolvida: registre um binding.');
        }
        $reflection = new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new ContainerException($class . ' não pode ser instanciada (abstrata ou construtor privado): registre um binding.');
        }
        if (isset($this->resolving[$class])) {
            throw new ContainerException('Dependência circular: ' . implode(' -> ', [...array_keys($this->resolving), $class]));
        }
        $this->resolving[$class] = true;
        try {
            $constructor = $reflection->getConstructor();
            if ($constructor === null) {
                return $reflection->newInstance();
            }
            $arguments = [];
            foreach ($constructor->getParameters() as $parameter) {
                $arguments[] = $this->resolveParameter($class, $parameter, $parameters);
            }
            return $reflection->newInstanceArgs($arguments);
        } finally {
            unset($this->resolving[$class]);
        }
    }

    /** @param array<string, mixed> $overrides */
    private function resolveParameter(string $class, ReflectionParameter $parameter, array $overrides): mixed
    {
        $name = $parameter->getName();
        if (array_key_exists($name, $overrides)) {
            return $overrides[$name];
        }
        $type = $parameter->getType();
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $dependency = $type->getName();
            if ($this->isBound($dependency)) {
                return $this->get($dependency);
            }
            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }
            if ($type->allowsNull()) {
                return null;
            }
            return $this->get($dependency);
        }
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }
        if ($type !== null && $type->allowsNull()) {
            return null;
        }
        throw new ContainerException(sprintf('Não foi possível resolver o parâmetro $%s de %s.', $name, $class));
    }
}
