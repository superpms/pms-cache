<?php

namespace pms\cache;

class Driver
{
    protected string $rootPath = '';
    protected string $symbol = '';
    protected string $noSymbol = '';

    public function __construct()
    {
        $this->symbol = DIRECTORY_SEPARATOR;
        if ($this->symbol === '/') {
            $this->noSymbol = "\\";
        } else {
            $this->noSymbol = "/";
        }
    }

    protected function generatePath(string $path): string
    {
        return $this->rootPath . $this->symbol . md5($path);
    }

    /**
     * 设置缓存文件根目录
     * @param string $rootPath 缓存文件根目录
     * @return void
     */
    public function init(string $rootPath): void{
        if (str_contains($rootPath, $this->noSymbol)) {
            $rootPath = str_replace($this->noSymbol, $this->symbol, $rootPath);
        }
        $this->rootPath = rtrim($rootPath, $this->symbol);
        if (!is_dir($this->rootPath)) {
            mkdir($this->rootPath, 0777, true);
        }
    }

    /**
     * 设置缓存
     * @param string $key 缓存键名
     * @param mixed $value 缓存值
     * @param int $expire 过期时间
     * @return bool
     */
    public function set(string $key, mixed $value, int $expire = 0): bool
    {
        $path = $this->generatePath($key);
        $file = fopen($path, 'w');
        if (!$file) {
            return false;
        }
        $status = fwrite($file, serialize([
            $value,
            $expire <= 0 ? 0 : time() + $expire,
        ]));
        fclose($file);
        return !($status === false);
    }

    /**
     * 获取缓存
     * @param string $key 缓存名称
     * @param mixed $default 默认值
     * @return mixed|null
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $path = $this->generatePath($key);
        if (!is_file($path)) {
            return $default;
        }
        $file = fopen($path, 'r');
        if (!$file) {
            return $default;
        }
        try {
            [$data, $expire] = unserialize(fread($file, filesize($path)));
            if ($expire !== 0 && $expire < time()) {
                return $default;
            }
            return $data;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /**
     * 删除缓存 （别名：del）
     * @param string $key 缓存名称
     * @return bool
     */
    public function delete(string $key): bool
    {
        $path = $this->generatePath($key);
        if (!is_file($path)) {
            return true;
        }
        return unlink($path);
    }

    /**
     * 删除缓存（delete 的别名）
     * @param string $key 缓存名称
     * @return bool
     */
    public function del(string $key): bool
    {
        return $this->delete($key);
    }

    /**
     * 判断 key 是否存在
     * @param string $key
     * @param ...$other_keys
     * @return int
     */
    public function exists(string $key, ...$other_keys): int
    {
        $hasKey = [];
        $data = $this->get($key);
        if (!empty($data)) {
            $hasKey[] = $key;
        }
        foreach ($other_keys as $key) {
            $data = $this->get($key);
            if (!empty($data) && !in_array($key, $hasKey)) {
                $hasKey[] = $key;
            }
        }
        return count($hasKey);
    }

    /**
     * 在指定的 key 不存在时,为 key设置指定的值
     * @param string $key
     * @param $value
     * @param int $expire 过期时间
     * @return bool
     */
    public function setnx(string $key, $value, int $expire = 0): bool
    {
        $path = $this->generatePath($key);
        $data = $this->get($key);
        if (empty($data)) {
            $this->delete($key);
        }
        try {
            $file = fopen($path, 'x');
            if (!$file) {
                return false;
            }
            $status = fwrite($file, serialize([
                $value,
                $expire <= 0 ? 0 : time() + $expire,
            ]));
            fclose($file);
            return !($status === false);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 如果缓存存在，读取缓存，如果不存在，File抢占式创建缓存（在并发情况下,只会有一个进程创建缓存,其余进程阻塞等待）
     * @param string $name
     * @param \Closure $callback
     * @param int $expireTime 缓存过期时间
     * @return mixed
     */
    public function setnxDCS(string $name, \Closure $callback, int $expireTime = 0){
        $data = $this->get($name);
        if (empty($data)) {
            $lockName = 'lock:' . $name;
            // 当前进程进行设置缓存
            if ($this->setnx($lockName, 'lock', 5)) {
                $data = $callback(function ($number) use (&$expireTime) {
                    $expireTime = $number;
                });
                if ($data !== false && $data !== null && $data !== "") {
                    $this->set($name, $data, $expireTime);
                    $this->delete($lockName);
                }
            } else {
                // 其他进程等待缓存的加载
                $count = 0; // 等待的次数
                usleep(100000);
                while (empty($this->get($name))) {
                    // 如果循环了5次还没有等到结果（100毫秒 * 10），则判定去取数据的进程死亡（代码报错）
                    if ($count > 10) {
                        throw new \RedisException("请求终止");
                        // 杀死所有进程（避免浪费服务器资源）
                    }
                    usleep(100000);
                    $count++;
                };
                $data = $this->get($name);
            }
        }
        return $data;
    }

    /**
     * 分布式锁 锁定
     * @param string $name 锁名称
     * @param int $occupy 占锁时间（秒）
     * @param int $pause 抢锁间隔时间（毫秒）
     * @return void
     */
    public function lock(string $name, int $occupy = 3, int $pause = 50): void
    {
        $name = 'lock:' . $name;
        $last = $occupy + time();
        $pause *= 1000;
        // 如果抢占失败再挂起 ($pause) 毫秒
        do {
            usleep($pause); //暂停 ($pause) 毫秒
            //防止当持有锁的进程崩溃或删除锁失败时，其他进程将无法获取到锁
            $lock_time = $this->get($name);
            // 锁已过期，重置
            if ($lock_time < time()) {
                $this->delete($name);
            }
        } while (!$this->setnx($name, $last, $occupy));
    }

    /**
     * 分布式锁 解锁
     * @param string $name 锁名称
     * @return void
     */
    public function unlock(string $name): void
    {
        $name = 'lock:'.$name;
        $this->delete($name);
    }

}