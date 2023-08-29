<?php

namespace Bisual\LaravelShortcuts;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * HOW TO USE ABSTRACTPRECALCULATEDMODEL
 *
 *      1. Create a folder for AbstractPrecalculatedModel implementations (for example: "Models/Precalculated")
 *      2. Create a specific-process implementation (for example: EcommerceTotalsLast30DaysPrecalculatedModel.php)
 *          2.1. Implement refresh(), add() and $BASE_KEY
 *      3. Create a kernel background process that executes refresh() method of each implementation
 *      4. Create an async structure (Async Job or Event) that dispatches
 *      5. Add the execution of a Dispatchable Job created in step 4 on Business Logic Layer (such as Repository or Service)
 */
abstract class AbstractPrecalculatedModel
{
    // la template de la key sin parámetros aplicados. Las variables serán envueltas entre doble claudators: {{var}} . Ejemplo: "ecommerce_total_last_30_days_branch_{{branch_id}}"
    protected static string $BASE_KEY_TEMPLATE;

    // This is the key with the parameters replaced
    private string $base_key;

    // We save $params for query purposes
    protected array $params;

    final public function __construct(array $params)
    {
        $this->params = $params;
        $this->base_key = $this->createKey($params);
    }

    /**
     * ABSTRACT METHODS TO IMPLEMENT
     */

    // Refreshes the whole model
    public final function refresh() {
        $data = $this->calc();
        $this->set($data);
    }

    // Adds a new iteration without having to refresh the whole model
    abstract public function add(array $array);

    /**
     * PUBLIC METHODS
     */
    final public function get(): array
    {
        if (! $this->check()) {
            $this->refresh();
        }

        return json_decode(Cache::get($this->getDataKey()), true);
    }

    final public function getWithoutCache(array $params): array {
        return $this->calc($params);
    }

    final public function check(): bool
    {
        return Cache::has($this->getDataKey());
    }

    final public function getUpdatedAt(): Carbon
    {
        return Carbon::createFromTimestamp(Cache::get($this->getUpdatedAtKey()));
    }

    /**
     * PROTECTED METHODS
     */

    /**
     * Calculates and returns the new data
     * @param $params is used for when is called from getWithoutCache() function
     */
    abstract protected function calc(array $params = null): array;

    final protected function set(array $data): void
    {
        Cache::set($this->getDataKey(), json_encode($data));
        Cache::set($this->getUpdatedAtKey(), Carbon::now()->timestamp);
    }

    final protected function generateDaysArray(Carbon $from, Carbon $to)
    {
        $res = [];
        $it = $from->copy();
        while ($it <= $to) {
            $res[] = 0;
            $it->addDay();
        }

        return $res;
    }

    /**
     * PRIVATE METHODS
     */
    private function getDataKey()
    {
        return $this->base_key.'_data';
    }

    private function getUpdatedAtKey()
    {
        return $this->base_key.'_updated_at';
    }

    private static function createKey(array $params)
    {
        $res = static::$BASE_KEY_TEMPLATE;
        foreach ($params as $key => $val) {
            $res = str_replace("{{{$key}}}", $val, $res);
        }

        return $res;
    }
}
