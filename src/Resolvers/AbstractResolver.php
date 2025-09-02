<?php

namespace GeTracker\LaravelRedisPaginator\Resolvers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

abstract class AbstractResolver
{
    /**
     * The key that maps models to their Redis counterpart.
     */
    protected string $modelKey = 'id';

    /**
     * Field to be merged into the collection of models containing the Redis result.
     */
    protected string $scoreField = 'score';

    /**
     * Redis results.
     */
    protected Collection $results;

    /**
     * Model Key -> Member mapping.
     */
    protected array $resolvedKeyMembers;

    /**
     * Member -> Model Key mapping.
     */
    protected array $resolvedMemberKeys;

    /**
     * Resolve an array of Redis results to their respective models.
     * 
     * @psalm-suppress InvalidReturnType
     */
    public function resolve(Collection $results): Collection
    {
        $this->results = $results;

        $keys = $this->mapKeys();
        $models = $this->resolveModels($keys);

        if (!count($results)) {
            return new Collection();
        }

        if ($models instanceof Collection && $models->first() instanceof Model) {
            /** @psalm-suppress InvalidReturnStatement */
            return $this->mapModels($models, true);
        }

        return new Collection($this->mapModels($models, false));
    }

    /**
     * Map scores to eloquent models.
     *
     * @param Collection|Model[] $models
     *
     * @return Collection|Model[]
     * 
     * @psalm-suppress InvalidReturnType
     * @psalm-suppress MismatchingDocblockParamType
     */
    private function mapModels(Collection|array $models, bool $eloquent): Collection|array
    {
        // Key the models by the defined key
        $models = $this->keyModels($models);

        $collection = $this->results->map(function ($score, $redisKey) use ($models, $eloquent) {
            $eloquentKey = $this->getEloquentKey($redisKey);

            if (!$eloquentKey || !$model = $models->get($eloquentKey)) {
                return null;
            }

            $scoreFields = $this->resolveScoreFields($score);

            // Set the defined score property on the model
            if ($eloquent) {
                foreach ($scoreFields as $key => $value) {
                    $model->setRelation($key, $value);
                }
            } else {
                $model += $scoreFields;
            }

            return $model;
        })->filter()->values();

        return $eloquent ? $collection : $collection->toArray();
    }

    protected function resolveScoreFields($score): array
    {
        return [
            $this->scoreField => $score,
        ];
    }

    /**
     * Key collections by the defined model key.
     */
    private function keyModels(Collection|array $array): Collection
    {
        $models = collect($array);

        // Key the models by the defined key
        $models = $models->keyBy($this->modelKey);

        return $models;
    }

    /**
     * Get an already resolved Redis key.
     *
     * @param $model
     */
    private function getRedisKey($model): string|int|null
    {
        if ($model instanceof Model) {
            return $this->resolvedKeyMembers[$model->getAttribute($this->modelKey)] ?? null;
        }

        return $this->resolvedKeyMembers[$model[$this->modelKey]] ?? null;
    }

    /**
     * Get an already resolved Eloquent key.
     */
    private function getEloquentKey(string $key): string|int|null
    {
        return $this->resolvedMemberKeys[$key] ?? null;
    }

    /**
     * Map keys using the key resolver.
     */
    private function mapKeys(): array
    {
        return $this->results
            ->keys()
            ->map(function ($key) {
                // Resolve the key
                $resolved = $this->resolveKey($key);

                // Cache the resolved key to map scores to the models
                $this->resolvedKeyMembers[$resolved] = $key;
                $this->resolvedMemberKeys[$key] = $resolved;

                return $resolved;
            })->toArray();
    }

    /**
     * Load Eloquent models.
     *
     * @param array $keys
     *
     * @return Model[]|Collection
     */
    abstract protected function resolveModels(array $keys);

    /**
     * Resolve a key from Redis to an Eloquent incrementing ID or UUID.
     */
    abstract protected function resolveKey(string $key): string|int;
}
