<?php

namespace App\Scout\Engines;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Pgvector\Laravel\Vector;

class PgVectorEngine extends Engine
{
    /**
     * Update the given model in the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function update($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $models->each(function ($model) {
            // Generate embedding for the model
            $embedding = $this->generateEmbedding($model);

            // Store in the database
            DB::table('embeddings')->updateOrInsert(
                [
                    'model_type' => get_class($model),
                    'model_id' => $model->getKey(),
                ],
                [
                    'content' => $model->toSearchableArray()['content'] ?? '',
                    'embedding' => new Vector($embedding),
                    'metadata' => json_encode($model->toSearchableArray()),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        });
    }

    /**
     * Remove the given model from the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function delete($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $models->each(function ($model) {
            DB::table('embeddings')
                ->where('model_type', get_class($model))
                ->where('model_id', $model->getKey())
                ->delete();
        });
    }

    /**
     * Perform the given search on the engine.
     *
     * @return mixed
     */
    public function search(Builder $builder)
    {
        $searchEmbedding = $this->generateEmbeddingForQuery($builder->query);

        $results = DB::table('embeddings')
            ->where('model_type', get_class($builder->model))
            ->selectRaw('*, embedding <-> ? as distance', [new Vector($searchEmbedding)])
            ->orderBy('distance')
            ->when($builder->limit, function ($query, $limit) {
                return $query->limit($limit);
            })
            ->get();

        return $results;
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  int  $perPage
     * @param  int  $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        $searchEmbedding = $this->generateEmbeddingForQuery($builder->query);

        $results = DB::table('embeddings')
            ->where('model_type', get_class($builder->model))
            ->selectRaw('*, embedding <-> ? as distance', [new Vector($searchEmbedding)])
            ->orderBy('distance')
            ->paginate($perPage, ['*'], 'page', $page);

        return $results;
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        return collect($results)->pluck('model_id');
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function map(Builder $builder, $results, $model)
    {
        if (count($results) === 0) {
            return $model->newCollection();
        }

        $keys = collect($results)->pluck('model_id')->values()->all();

        $models = $model->getScoutModelsByIds($builder, $keys)
            ->keyBy(function ($model) {
                return $model->getScoutKey();
            });

        return Collection::make($results)->map(function ($result) use ($models) {
            $model = $models[$result->model_id] ?? null;

            if ($model) {
                $model->withScoutMetadata('distance', $result->distance);
            }

            return $model;
        })->filter()->values();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed  $results
     * @return int
     */
    public function getTotalCount($results)
    {
        return count($results);
    }

    /**
     * Flush all of the model's records from the engine.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function flush($model)
    {
        DB::table('embeddings')
            ->where('model_type', get_class($model))
            ->delete();
    }

    /**
     * Create a search index.
     *
     * @param  string  $name
     * @return mixed
     */
    public function createIndex($name, array $options = [])
    {
        // Index is created via migration
        return true;
    }

    /**
     * Delete a search index.
     *
     * @param  string  $name
     * @return mixed
     */
    public function deleteIndex($name)
    {
        // Not applicable for pgvector
        return true;
    }

    /**
     * Generate embedding for a model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array
     */
    protected function generateEmbedding($model)
    {
        // This is a placeholder - you should integrate with an actual embedding service
        // like OpenAI, Cohere, or a local model

        $text = $model->toSearchableArray()['content'] ?? '';

        // For demo purposes, generate a random 384-dimensional vector
        // In production, use an actual embedding model
        return array_map(function () {
            return mt_rand() / mt_getrandmax() * 2 - 1;
        }, range(1, 384));
    }

    /**
     * Generate embedding for a search query.
     *
     * @param  string  $query
     * @return array
     */
    protected function generateEmbeddingForQuery($query)
    {
        // This is a placeholder - you should integrate with an actual embedding service
        // like OpenAI, Cohere, or a local model

        // For demo purposes, generate a random 384-dimensional vector
        // In production, use an actual embedding model
        return array_map(function () {
            return mt_rand() / mt_getrandmax() * 2 - 1;
        }, range(1, 384));
    }

    /**
     * Determine if the given model uses soft deletes.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    protected function usesSoftDelete($model)
    {
        return in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($model));
    }

    /**
     * Get the results of the query as a Collection of primary keys.
     *
     * @return \Illuminate\Support\Collection
     */
    public function keys(Builder $builder)
    {
        return $this->mapIds($this->search($builder));
    }

    /**
     * Get the results of the given query mapped onto models.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function get(Builder $builder)
    {
        return $this->map(
            $builder, $this->search($builder), $builder->model
        );
    }

    /**
     * Get a lazy collection for the given query.
     *
     * @return \Illuminate\Support\LazyCollection
     */
    public function lazyMap(Builder $builder, $results, $model)
    {
        // For pgvector, we'll just use regular map since we're working with DB results
        return new \Illuminate\Support\LazyCollection(function () use ($builder, $results, $model) {
            foreach ($this->map($builder, $results, $model) as $item) {
                yield $item;
            }
        });
    }
}
