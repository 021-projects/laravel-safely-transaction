<?php

namespace O21;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use JetBrains\PhpStorm\Pure;

class SafelyTransaction
{
    protected Closure $closure;

    protected ?Model $lockModel = null;

    protected ?BuilderContract $query = null;

    protected ?Closure $catch = null;

    protected bool $throw = false;

    /**
     * SafelyTransaction constructor.
     *
     * @param  \Closure  $closure
     * @param  \Illuminate\Database\Eloquent\Model|\Illuminate\Contracts\Database\Query\Builder|null  $query
     */
    public function __construct(
        Closure $closure,
        Model|BuilderContract $query = null
    ) {
        $this->closure = $closure;

        if ($query) {
            $this->lockOn($query);
        }
    }

    public function onCatch(Closure $catch): self
    {
        $this->catch = $catch;
        return $this;
    }

    public function setThrow(bool $throw): self
    {
        $this->throw = $throw;
        return $this;
    }

    public function lockOn(Model|BuilderContract $query): self
    {
        if ($query instanceof Model) {
            $this->lockOnModel($query);
        }

        if ($query instanceof BuilderContract) {
            $this->lockOnQuery($query);
        }

        return $this;
    }

    protected function lockOnModel(Model $model): void
    {
        $query = $model->query();

        if (is_array($key = $model->getKey())) {
            $query->where($key);
        } else {
            $query->where($model->getKeyName(), $model->getKey());
        }

        $this->query = $query;
        $this->lockModel = $model;
    }

    protected function lockOnQuery(BuilderContract $query): void
    {
        $this->query = $query;
    }

    public function run($result = null)
    {
        \DB::beginTransaction();

        try {
            if ($this->lockModel) {
                $queryResult = $this->lockModel = $this->query->lockForUpdate()
                    ->first();
            } else {
                $queryResult = $this->query?->lockForUpdate()->first();
            }

            $result = ($this->closure)(...$this->bindParametersForClosure($queryResult));

            \DB::commit();
        } catch (\Throwable $e) {
            \DB::rollBack();

            if ($this->catch) {
                ($this->catch)($e);
            }

            throw_if($this->throw, $e);
        }

        return $result;
    }

    protected function bindParametersForClosure($queryResult = null): array
    {
        $params = [];

        $reflection = new \ReflectionFunction($this->closure);

        foreach ($reflection->getParameters() as $key => $parameter) {
            if ($this->shouldBindSelf($parameter)) {
                $params[$key] = $this;
            }

            if ($this->shouldBindQuery($parameter)) {
                $params[$key] = $queryResult;
            }
        }

        return $params;
    }

    #[Pure]
    protected function shouldBindSelf(\ReflectionParameter $parameter): bool
    {
        if (! ($type = $parameter->getType())) {
            return false;
        }

        return $type->getName() === self::class;
    }

    #[Pure]
    protected function shouldBindQuery(\ReflectionParameter $parameter): bool
    {
        $typeName = $parameter->getType()
            ? $parameter->getType()->getName()
            : '';

        if ($this->lockModel) {
            return $typeName === get_class($this->lockModel);
        }

        if ($this->query) {
            return $typeName === get_class($this->query)
                || $parameter->getName() === 'query';
        }

        return false;
    }
}