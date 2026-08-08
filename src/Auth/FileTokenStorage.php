<?php namespace StarlineApi\Auth;

use StarlineApi\Exceptions\StarlineException;

/**
 * Файловое хранилище токенов (JSON).
 *
 * @author Alexander Tischenko (http://alex-tisch.ru)
 *
 * Удобно для CLI-скриптов и cron-задач: токены переживают перезапуск
 * процесса, файл создаётся с правами 0600.
 */
final class FileTokenStorage implements TokenStorageInterface
{
    /**
     * @param string $filePath Путь к JSON-файлу.
     */
    public function __construct(private string $filePath)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $key): ?string
    {
        $data = $this->read();

        if (!isset($data[$key]) || !is_array($data[$key]) || !isset($data[$key]['value'])) {
            return null;
        }

        $item = $data[$key];

        if (isset($item['expires_at']) && $item['expires_at'] !== null && (int) $item['expires_at'] < time()) {
            $this->delete($key);

            return null;
        }

        return (string) $item['value'];
    }

    /**
     * {@inheritDoc}
     */
    public function set(string $key, string $value, ?int $ttl = null): void
    {
        $data = $this->read();
        $data[$key] = [
            'value' => $value,
            'expires_at' => $ttl === null ? null : time() + $ttl,
        ];

        $this->write($data);
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $key): void
    {
        $data = $this->read();

        if (array_key_exists($key, $data)) {
            unset($data[$key]);

            $this->write($data);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        if (!is_file($this->filePath)) {
            return [];
        }

        $contents = file_get_contents($this->filePath);

        if ($contents === false || $contents === '') {
            return [];
        }

        $data = json_decode($contents, true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(array $data): void
    {
        $dir = dirname($this->filePath);

        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new StarlineException(sprintf('Не удалось создать каталог хранилища: %s', $dir));
        }

        $written = file_put_contents(
            $this->filePath,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );

        if ($written === false) {
            throw new StarlineException(sprintf('Не удалось записать хранилище токенов: %s', $this->filePath));
        }

        @chmod($this->filePath, 0600);
    }
}