<?php
/** Exercise the real clone controller with in-memory persistence; no app boot. */
namespace App\Http\Controllers { class Controller {} }
namespace Illuminate\Http { class JsonResponse { public function __construct(public array $body, public int $status) {} } }
namespace Illuminate\Support\Facades {
    class DB {
        public static function transaction($callback) {
            $before = unserialize(serialize(\App\Models\ProxyServer::$rows));
            try { return $callback(); } catch (\Throwable $e) { \App\Models\ProxyServer::$rows = $before; throw $e; }
        }
    }
}
namespace App\Models {
    #[\AllowDynamicProperties]
    class ProxyServer {
        public static array $rows = [];
        public static ?int $failOnSave = null;
        public function __construct(array $values) { foreach ($values as $key => $value) $this->$key = $value; }
        public static function orderBy($column) { return (new NodeQuery())->orderBy($column); }
        public function replicate() { $values = get_object_vars($this); unset($values['id']); return new self($values); }
        public function save() {
            if (isset($this->id) && self::$failOnSave === $this->id) throw new \RuntimeException('write failed');
            $this->id ??= max([0, ...array_keys(self::$rows)]) + 1;
            self::$rows[$this->id] = $this;
        }
        public function load(...$relations) { return $this; }
    }
    class NodeQuery {
        private array $columns = [];
        private bool $locked = false;
        public function orderBy($column) { $this->columns[] = $column; return $this; }
        public function lockForUpdate() { $this->locked = true; return $this; }
        public function get() {
            if (!$this->locked) throw new \RuntimeException('clone list must be locked');
            $rows = array_values(ProxyServer::$rows);
            usort($rows, function ($a, $b) { foreach ($this->columns as $column) if ($r = $a->$column <=> $b->$column) return $r; return 0; });
            return new NodeRows($rows);
        }
    }
    class NodeRows implements \IteratorAggregate {
        public function __construct(private array $rows) {}
        public function firstWhere($key, $value) { foreach ($this->rows as $row) if ($row->$key == $value) return $row; return null; }
        public function getIterator(): \Traversable { return new \ArrayIterator($this->rows); }
    }
}
namespace {
    function abort_if($condition, $status, $message) { if ($condition) throw new \RuntimeException($message, $status); }
    function response() { return new class { public function json($data, $status = 200) { return new \Illuminate\Http\JsonResponse($data, $status); } }; }
    require __DIR__.'/../../app/Http/Controllers/Api/Admin/InfrastructureController.php';
    $controller = (new \ReflectionClass(\App\Http\Controllers\Api\Admin\InfrastructureController::class))->newInstanceWithoutConstructor();
    function seed(array $sorts): void {
        \App\Models\ProxyServer::$rows = [];
        \App\Models\ProxyServer::$failOnSave = null;
        foreach ($sorts as $index => $sort) {
            $node = new \App\Models\ProxyServer(['id' => $index + 1, 'sort' => $sort, 'name' => 'Node', 'type' => 'vless', 'parent_id' => null, 'machine_id' => 8, 'kernel' => 'xray', 'rate' => 2, 'warp_enabled' => true, 'enabled' => true, 'show' => true, 'u' => 100, 'd' => 200, 'group_ids' => [3]]);
            $node->save();
        }
    }
    function expectOrder(array $expected): void {
        $rows = iterator_to_array(\App\Models\ProxyServer::orderBy('sort')->orderBy('id')->lockForUpdate()->get());
        if (array_column($rows, 'id') !== $expected) throw new \RuntimeException('Unexpected node order');
        if (array_column($rows, 'sort') !== range(1, count($rows))) throw new \RuntimeException('Order must be unique and contiguous');
    }
    function copyNode(int $id) { global $controller; return $controller->cloneNode(\App\Models\ProxyServer::$rows[$id])->body['data']; }
    seed([1, 2, 3]);
    $copy = copyNode(1);
    expectOrder([1, 4, 2, 3]);
    if ($copy->parent_id !== 1 || $copy->show !== false || $copy->u !== 0 || $copy->d !== 0 || $copy->group_ids !== [3]) throw new \RuntimeException('Clone inheritance changed');
    copyNode(1); expectOrder([1, 5, 4, 2, 3]);
    $childCopy = copyNode(4); expectOrder([1, 5, 4, 6, 2, 3]);
    if ($childCopy->parent_id !== 1) throw new \RuntimeException('Copy of child must retain the root parent');
    copyNode(3); expectOrder([1, 5, 4, 6, 2, 3, 7]);
    seed([null, null, 9]); copyNode(2); expectOrder([1, 2, 4, 3]);
    seed([1, 2, 3]); \App\Models\ProxyServer::$failOnSave = 2;
    try { copyNode(1); throw new \LogicException('Expected write failure'); } catch (\RuntimeException $e) { if ($e->getMessage() !== 'write failed') throw $e; }
    expectOrder([1, 2, 3]);
    echo "Node clone order: passed\n";
}
