<?php

namespace Kirschbaum\PowerJoins\Mixins;

use Kirschbaum\PowerJoins\PowerJoins;
use Kirschbaum\PowerJoins\StaticCache;
use Kirschbaum\PowerJoins\PowerJoinClause;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;

/**
 * @method getModel
 */
class RelationshipsExtraMethods
{
    /**
     * Perform the JOIN clause for eloquent power joins.
     */
    public function performJoinForEloquentPowerJoins()
    {
        return function ($builder, $joinType = 'leftJoin', $callback = null, $alias = null, bool $disableExtraConditions = false) {
            if ($this instanceof BelongsToMany) {
                return $this->performJoinForEloquentPowerJoinsForBelongsToMany($builder, $joinType, $callback, $alias, $disableExtraConditions);
            } elseif ($this instanceof MorphOneOrMany) {
                $this->performJoinForEloquentPowerJoinsForMorph($builder, $joinType, $callback, $alias, $disableExtraConditions);
            } elseif ($this instanceof HasMany || $this instanceof HasOne) {
                return $this->performJoinForEloquentPowerJoinsForHasMany($builder, $joinType, $callback, $alias, $disableExtraConditions);
            } elseif ($this instanceof HasManyThrough) {
                return $this->performJoinForEloquentPowerJoinsForHasManyThrough($builder, $joinType, $callback, $alias, $disableExtraConditions);
            } else {
                return $this->performJoinForEloquentPowerJoinsForBelongsTo($builder, $joinType, $callback, $alias, $disableExtraConditions);
            }
        };
    }

    /**
     * Perform the JOIN clause for the BelongsTo (or similar) relationships.
     */
    protected function performJoinForEloquentPowerJoinsForBelongsTo()
    {
        return function ($query, $joinType, $callback = null, $alias = null, bool $disableExtraConditions = false) {
            $joinedTable = $this->query->getModel()->getTable();
            $parentTable = $this->getTableOrAliasForModel($this->parent, $this->parent->getTable());

            $query->{$joinType}($joinedTable, function (PowerJoinClause $join) use ($callback, $joinedTable, $parentTable, $alias, $disableExtraConditions) {
                if ($alias) {
                    $join->as($alias);
                }

                $join->on(
                    "{$parentTable}.{$this->foreignKey}",
                    '=',
                    "{$joinedTable}.{$this->ownerKey}"
                );

                if ($disableExtraConditions === false && $this->usesSoftDeletes($this->query->getModel())) {
                    $join->whereNull("{$joinedTable}.{$this->query->getModel()->getDeletedAtColumn()}");
                }

                if ($disableExtraConditions === false) {
                    $this->applyExtraConditions($join);
                }

                if ($callback && is_callable($callback)) {
                    $callback($join);
                }
            }, $this->query->getModel());
        };
    }

    /**
     * Perform the JOIN clause for the BelongsToMany (or similar) relationships.
     */
    protected function performJoinForEloquentPowerJoinsForBelongsToMany()
    {
        return function ($builder, $joinType, $callback = null, $alias = null, bool $disableExtraConditions = false) {
            [$alias1, $alias2] = $alias;

            $pivotTable = $alias1 ?: $this->getTable();
            $relatedTable = $alias2 ?: $this->getModel()->getTable();
            $parentTable = $this->getTableOrAliasForModel($this->parent) ?? $this->parent->getTable();

            $builder->{$joinType}($this->getTable(), function (PowerJoinClause $join) use ($callback, $pivotTable, $parentTable, $alias1) {
                if ($alias1) {
                    $join->as($alias1);
                }

                $join->on(
                    "{$pivotTable}.{$this->getForeignPivotKeyName()}",
                    '=',
                    "{$parentTable}.{$this->parentKey}"
                );

                if (is_array($callback) && isset($callback['pivot'])) {
                    $callback['pivot']($join);
                }
            });

            $builder->{$joinType}($this->getModel()->getTable(), function (PowerJoinClause $join) use ($callback, $pivotTable, $alias2, $disableExtraConditions, $relatedTable) {
                if ($alias2) {
                    $join->as($alias2);
                }

                $join->on(
                    "{$relatedTable}.{$this->getModel()->getKeyName()}",
                    '=',
                    "{$pivotTable}.{$this->getRelatedPivotKeyName()}"
                );

                if ($disableExtraConditions === false && $this->usesSoftDeletes($this->query->getModel())) {
                    $join->whereNull($this->query->getModel()->getQualifiedDeletedAtColumn());
                }

                // applying any extra conditions to the belongs to many relationship
                if ($disableExtraConditions === false) {
                    $this->applyExtraConditions($join);
                }

                if (is_array($callback) && isset($callback['related'])) {
                    $callback['related']($join);
                }
            }, $this->getModel());

            return $this;
        };
    }

    /**
     * Perform the JOIN clause for the Morph (or similar) relationships.
     */
    protected function performJoinForEloquentPowerJoinsForMorph()
    {
        return function ($builder, $joinType, $callback = null, $alias = null, bool $disableExtraConditions = false) {
            $builder->{$joinType}($this->getModel()->getTable(), function (PowerJoinClause $join) use ($callback, $disableExtraConditions) {
                $join->on(
                    "{$this->getModel()->getTable()}.{$this->getForeignKeyName()}",
                    '=',
                    "{$this->parent->getTable()}.{$this->localKey}"
                )->where($this->getMorphType(), '=', $this->getMorphClass());

                if ($disableExtraConditions === false && $this->usesSoftDeletes($this->query->getModel())) {
                    $join->whereNull($this->query->getModel()->getQualifiedDeletedAtColumn());
                }

                if ($disableExtraConditions === false) {
                    $this->applyExtraConditions($join);
                }

                if ($callback && is_callable($callback)) {
                    $callback($join);
                }
            }, $this->getModel());

            return $this;
        };
    }

    /**
     * Perform the JOIN clause for the HasMany (or similar) relationships.
     */
    protected function performJoinForEloquentPowerJoinsForHasMany()
    {
        return function ($builder, $joinType, $callback = null, $alias = null, bool $disableExtraConditions = false) {
            $joinedTable = $alias ?: $this->query->getModel()->getTable();
            $parentTable = $this->getTableOrAliasForModel($this->parent, $this->parent->getTable());

            $builder->{$joinType}($this->query->getModel()->getTable(), function (PowerJoinClause $join) use ($callback, $joinedTable, $parentTable, $alias, $disableExtraConditions) {
                if ($alias) {
                    $join->as($alias);
                }

                $join->on(
                    $this->foreignKey,
                    '=',
                    "{$parentTable}.{$this->localKey}"
                );

                if ($disableExtraConditions === false && $this->usesSoftDeletes($this->query->getModel())) {
                    $join->whereNull(
                        "{$joinedTable}.{$this->query->getModel()->getDeletedAtColumn()}"
                    );
                }

                if ($disableExtraConditions === false) {
                    $this->applyExtraConditions($join);
                }

                if ($callback && is_callable($callback)) {
                    $callback($join);
                }
            }, $this->query->getModel());
        };
    }

    /**
     * Perform the JOIN clause for the HasManyThrough relationships.
     */
    protected function performJoinForEloquentPowerJoinsForHasManyThrough()
    {
        return function ($builder, $joinType, $callback = null, $alias = null, bool $disableExtraConditions = false) {
            [$alias1, $alias2] = $alias;
            $throughTable = $alias1 ?: $this->getThroughParent()->getTable();
            $farTable = $alias2 ?: $this->getModel()->getTable();

            $builder->{$joinType}($this->getThroughParent()->getTable(), function (PowerJoinClause $join) use ($callback, $throughTable, $alias1, $disableExtraConditions) {
                if ($alias1) {
                    $join->as($alias1);
                }

                $join->on(
                    "{$throughTable}.{$this->getFirstKeyName()}",
                    '=',
                    $this->getQualifiedLocalKeyName()
                );

                if ($disableExtraConditions === false && $this->usesSoftDeletes($this->getThroughParent())) {
                    $join->whereNull($this->getThroughParent()->getQualifiedDeletedAtColumn());
                }

                if ($disableExtraConditions === false) {
                    $this->applyExtraConditions($join);
                }

                if (is_array($callback) && isset($callback[$this->getThroughParent()->getTable()])) {
                    $callback[$this->getThroughParent()->getTable()]($join);
                }

                if (is_array($callback) && isset($callback['pivot'])) {
                    $callback['pivot']($join);
                }
            }, $this->getThroughParent());

            $builder->{$joinType}($this->getModel()->getTable(), function (PowerJoinClause $join) use ($callback, $throughTable, $farTable, $alias1, $alias2) {
                if ($alias2) {
                    $join->as($alias2);
                }

                $join->on(
                    "{$farTable}.{$this->secondKey}",
                    '=',
                    "{$throughTable}.{$this->secondLocalKey}"
                );

                if ($this->usesSoftDeletes($this->getModel())) {
                    $join->whereNull("{$farTable}.{$this->getModel()->getDeletedAtColumn()}");
                }

                if (is_array($callback) && isset($callback['related'])) {
                    $callback['related']($join);
                }
            }, $this->getModel());

            return $this;
        };
    }

    /**
     * Perform the "HAVING" clause for eloquent power joins.
     */
    public function performHavingForEloquentPowerJoins()
    {
        return function ($builder, $operator, $count) {
            $builder
                ->selectRaw(sprintf('count(%s) as %s_count', $this->query->getModel()->getQualifiedKeyName(), $this->query->getModel()->getTable()))
                ->havingRaw(sprintf('%s_count %s %d', $this->query->getModel()->getTable(), $operator, $count));
        };
    }

    /**
     * Checks if the relationship model uses soft deletes.
     */
    public function usesSoftDeletes()
    {
        return function ($model) {
            return in_array(SoftDeletes::class, class_uses_recursive($model));
        };
    }

    /**
     * Get the throughParent for the HasManyThrough relationship.
     */
    public function getThroughParent()
    {
        return function () {
            return $this->throughParent;
        };
    }

    /**
     * Get the farParent for the HasManyThrough relationship.
     */
    public function getFarParent()
    {
        return function () {
            return $this->farParent;
        };
    }

    public function getTableOrAliasForModel()
    {
        return function ($model, $default = null) {
            return StaticCache::$powerJoinAliasesCache[spl_object_id($model)] ?? $default;
        };
    }

    public function applyExtraConditions()
    {
        return function (PowerJoinClause $join) {
            foreach ($this->getQuery()->getQuery()->wheres as $index => $condition) {
                if ($this->shouldNotApplyExtraCondition($condition)) {
                    continue;
                }

                if (! in_array($condition['type'], ['Basic', 'Null', 'NotNull', 'Nested'])) {
                    continue;
                }

                $method = "apply{$condition['type']}Condition";
                $this->$method($join, $condition);
            }
        };
    }

    public function applyBasicCondition()
    {
        return function ($join, $condition) {
            $join->where($condition['column'], $condition['operator'], $condition['value'], $condition['boolean']);
        };
    }

    public function applyNullCondition()
    {
        return function ($join, $condition) {
            $join->whereNull($condition['column'], $condition['boolean']);
        };
    }

    public function applyNotNullCondition()
    {
        return function ($join, $condition) {
            $join->whereNotNull($condition['column'], $condition['boolean']);
        };
    }

    public function applyNestedCondition()
    {
        return function ($join, $condition) {
            foreach ($condition['query']->wheres as $condition) {
                $method = "apply{$condition['type']}Condition";
                $this->$method($join, $condition);
            }
        };
    }

    public function shouldNotApplyExtraCondition()
    {
        return function ($condition) {
            $key = $this->getPowerJoinExistenceCompareKey();

            if (isset($condition['query'])) {
                return false;
            }

            if (is_array($key)) {
                return in_array($condition['column'], $key);
            }

            return $condition['column'] === $key;
        };
    }

    public function getPowerJoinExistenceCompareKey()
    {
        return function () {
            if ($this instanceof BelongsTo) {
                return $this->getQualifiedOwnerKeyName();
            }

            if ($this instanceof HasMany || $this instanceof HasOne) {
                return $this->getExistenceCompareKey();
            }

            if ($this instanceof HasManyThrough) {
                return $this->getQualifiedFirstKeyName();
            }

            if ($this instanceof BelongsToMany) {
                return $this->getExistenceCompareKey();
            }

            if ($this instanceof MorphOneOrMany) {
                return [$this->getQualifiedMorphType(), $this->getExistenceCompareKey()];
            }
        };
    }
}
