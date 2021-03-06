<?php

namespace Kirby\Cms;

use Closure;
use Exception;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Toolkit\Collection as BaseCollection;
use Kirby\Toolkit\Str;

/**
 * The Collection class serves as foundation
 * for the Pages, Files, Users and Structure
 * classes. It handles object validation and sets
 * the parent collection property for each object.
 * The `getAttribute` method is also adjusted to
 * handle values from Field objects correctly, so
 * those can be used in filters as well.
 *
 * @package   Kirby Cms
 * @author    Bastian Allgeier <bastian@getkirby.com>
 * @link      https://getkirby.com
 * @copyright Bastian Allgeier GmbH
 * @license   https://getkirby.com/license
 */
class Collection extends BaseCollection
{
    use HasMethods;

    /**
     * Stores the parent object, which is needed
     * in some collections to get the finder methods right.
     *
     * @var object
     */
    protected $parent;

    /**
     * Magic getter function
     *
     * @param string $key
     * @param mixed $arguments
     * @return mixed
     */
    public function __call(string $key, $arguments)
    {
        // collection methods
        if ($this->hasMethod($key)) {
            return $this->callMethod($key, $arguments);
        }
    }

    /**
     * Creates a new Collection with the given objects
     *
     * @param array $objects
     * @param object $parent
     */
    public function __construct($objects = [], $parent = null)
    {
        $this->parent = $parent;

        foreach ($objects as $object) {
            $this->add($object);
        }
    }

    /**
     * Internal setter for each object in the Collection.
     * This takes care of Component validation and of setting
     * the collection prop on each object correctly.
     *
     * @param string $id
     * @param object $object
     */
    public function __set(string $id, $object)
    {
        $this->data[$id] = $object;
    }

    /**
     * Adds a single object or
     * an entire second collection to the
     * current collection
     *
     * @param mixed $object
     */
    public function add($object)
    {
        if (is_a($object, static::class) === true) {
            $this->data = array_merge($this->data, $object->data);
        } elseif (method_exists($object, 'id') === true) {
            $this->__set($object->id(), $object);
        } else {
            $this->append($object);
        }

        return $this;
    }

    /**
     * Appends an element to the data array
     *
     * @param mixed $key Optional collection key, will be determined from the item if not given
     * @param mixed $item
     * @return \Kirby\Cms\Collection
     */
    public function append(...$args)
    {
        if (count($args) === 1) {
            // try to determine the key from the provided item
            if (is_object($args[0]) === true && is_callable([$args[0], 'id']) === true) {
                return parent::append($args[0]->id(), $args[0]);
            } else {
                return parent::append($args[0]);
            }
        }

        return parent::append(...$args);
    }

    /**
     * Groups the items by a given field. Returns a collection
     * with an item for each group and a collection for each group.
     *
     * @param string $field
     * @param bool $i Ignore upper/lowercase for group names
     * @return \Kirby\Cms\Collection
     */
    public function groupBy($field, bool $i = true)
    {
        if (is_string($field) === false) {
            throw new Exception('Cannot group by non-string values. Did you mean to call group()?');
        }

        $groups = new Collection([], $this->parent());

        foreach ($this->data as $key => $item) {
            $value = $this->getAttribute($item, $field);

            // make sure that there's always a proper value to group by
            if (!$value) {
                throw new InvalidArgumentException('Invalid grouping value for key: ' . $key);
            }

            // ignore upper/lowercase for group names
            if ($i) {
                $value = Str::lower($value);
            }

            if (isset($groups->data[$value]) === false) {
                // create a new entry for the group if it does not exist yet
                $groups->data[$value] = new static([$key => $item]);
            } else {
                // add the item to an existing group
                $groups->data[$value]->set($key, $item);
            }
        }

        return $groups;
    }

    /**
     * Checks if the given object or id
     * is in the collection
     *
     * @param string|object $id
     * @return bool
     */
    public function has($id): bool
    {
        if (is_object($id) === true) {
            $id = $id->id();
        }

        return parent::has($id);
    }

    /**
     * Correct position detection for objects.
     * The method will automatically detect objects
     * or ids and then search accordingly.
     *
     * @param string|object $object
     * @return int
     */
    public function indexOf($object): int
    {
        if (is_string($object) === true) {
            return array_search($object, $this->keys());
        }

        return array_search($object->id(), $this->keys());
    }

    /**
     * Returns a Collection without the given element(s)
     *
     * @param mixed ...$keys any number of keys, passed as individual arguments
     * @return \Kirby\Cms\Collection
     */
    public function not(...$keys)
    {
        $collection = $this->clone();
        foreach ($keys as $key) {
            if (is_a($key, 'Kirby\Toolkit\Collection') === true) {
                $collection = $collection->not(...$key->keys());
            } elseif (is_object($key) === true) {
                $key = $key->id();
            }
            unset($collection->$key);
        }
        return $collection;
    }

    /**
     * Add pagination and return a sliced set of data.
     *
     * @param mixed ...$arguments
     * @return \Kirby\Cms\Collection
     */
    public function paginate(...$arguments)
    {
        $this->pagination = Pagination::for($this, ...$arguments);

        // slice and clone the collection according to the pagination
        return $this->slice($this->pagination->offset(), $this->pagination->limit());
    }

    /**
     * Returns the parent model
     *
     * @return \Kirby\Cms\Model
     */
    public function parent()
    {
        return $this->parent;
    }

    /**
     * Prepends an element to the data array
     *
     * @param mixed $key Optional collection key, will be determined from the item if not given
     * @param mixed $item
     * @return \Kirby\Cms\Collection
     */
    public function prepend(...$args)
    {
        if (count($args) === 1) {
            // try to determine the key from the provided item
            if (is_object($args[0]) === true && is_callable([$args[0], 'id']) === true) {
                return parent::prepend($args[0]->id(), $args[0]);
            } else {
                return parent::prepend($args[0]);
            }
        }

        return parent::prepend(...$args);
    }

    /**
     * Runs a combination of filterBy, sortBy, not
     * offset, limit, search and paginate on the collection.
     * Any part of the query is optional.
     *
     * @param array $query
     * @return self
     */
    public function query(array $query = [])
    {
        $paginate = $query['paginate'] ?? null;
        $search   = $query['search'] ?? null;

        unset($query['paginate']);

        $result = parent::query($query);

        if (empty($search) === false) {
            if (is_array($search) === true) {
                $result = $result->search($search['query'] ?? null, $search['options'] ?? []);
            } else {
                $result = $result->search($search);
            }
        }

        if (empty($paginate) === false) {
            $result = $result->paginate($paginate);
        }

        return $result;
    }

    /**
     * Removes an object
     *
     * @param mixed $key the name of the key
     */
    public function remove($key)
    {
        if (is_object($key) === true) {
            $key = $key->id();
        }

        return parent::remove($key);
    }

    /**
     * Searches the collection
     *
     * @param string $query
     * @param array $params
     * @return self
     */
    public function search(string $query = null, $params = [])
    {
        return Search::collection($this, $query, $params);
    }

    /**
     * Converts all objects in the collection
     * to an array. This can also take a callback
     * function to further modify the array result.
     *
     * @param Closure $map
     * @return array
     */
    public function toArray(Closure $map = null): array
    {
        return parent::toArray($map ?? function ($object) {
            return $object->toArray();
        });
    }
}
