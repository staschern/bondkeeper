<?php

declare(strict_types=1);

namespace BondKeeper\Payments;

use RuntimeException;

/**
 * Замок от наложения запусков опроса GetNews (11.10.2026).
 *
 * При опросе раз в минуту новый запуск может начаться, пока прежний ещё
 * работает (страница ленты в тысячу сообщений читается десятки секунд).
 * У raw_messages нет уникального ключа по source_ref, поэтому два
 * одновременных прохода могли бы обработать одно сообщение дважды и
 * создать два события. Замок на файле этого не допускает: второй запуск
 * видит, что файл занят, и тихо завершается.
 *
 * Замок снимается сам, когда процесс заканчивается — в том числе если он
 * упал, так что «вечного» замка после сбоя не остаётся.
 */
final class PollLock
{
    /** @param resource $handle */
    private function __construct(private $handle)
    {
    }

    /** Занять замок; null — он уже занят другим запуском. */
    public static function acquire(string $file): ?self
    {
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            throw new RuntimeException("Не удалось открыть файл замка опроса: {$file}");
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }
        // Время начала — чтобы по занятому замку было видно, как давно идёт запуск.
        ftruncate($handle, 0);
        fwrite($handle, (string) time());
        fflush($handle);

        return new self($handle);
    }

    /** Сколько минут назад начался запуск, который держит замок; null — неизвестно. */
    public static function heldMinutes(string $file): ?int
    {
        clearstatcache(true, $file);
        $started = @filemtime($file);

        return $started === false ? null : (int) floor(max(0, time() - $started) / 60);
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
