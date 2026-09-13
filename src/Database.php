<?php

declare(strict_types=1);

namespace BondKeeper;

use BondKeeper\Support\Env;
use PDO;
use PDOException;
use Throwable;

/**
 * Одно на процесс подключение к MySQL (синглтон) — годится как есть для
 * bin/*.php скриптов, запускаемых из cron: короткоживущий процесс,
 * подключение открывается и умирает вместе с ним, протухнуть не успевает.
 *
 * Для bin/daemon_*.php (живут часами/сутками, единственный такой сейчас —
 * daemon_telegram_bot.php) это уже НЕ так: MySQL сама закрывает
 * простаивающее соединение по wait_timeout, а PDO об этом клиенту заранее
 * не сообщает — следующий же запрос падает с "MySQL server has gone away"
 * (SQLSTATE HY000). Найдено вживую (13 сентября 2026): демон-процесс
 * простоял несколько часов, и КАЖДЫЙ последующий проход рассылки падал с
 * этой ошибкой — синглтон держал один и тот же мёртвый PDO-объект вечно,
 * никто не переподключался. isConnectionLost()/reconnect() — для
 * демонов, которые сами ловят исключение в своём цикле и должны решить
 * "это стоит просто пересоздать соединение и продолжить, а не падать
 * насовсем" (см. bin/daemon_telegram_bot.php).
 */
final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection === null) {
            self::$connection = self::createConnection();
        }

        return self::$connection;
    }

    /**
     * Принудительно пересоздаёт соединение — вызывающий код (демон) сам
     * решает, когда это нужно (после isConnectionLost() на пойманном
     * исключении), и сам должен пересоздать объекты, которым старый PDO
     * уже передан конструктором (PDO там — readonly-свойство, само не
     * подменится вслед за синглтоном).
     */
    public static function reconnect(): PDO
    {
        self::$connection = null;

        return self::connection();
    }

    /**
     * true — исключение похоже именно на "соединение с MySQL умерло само"
     * (простой по wait_timeout, перезапуск/сеть на стороне MySQL), а не
     * на содержательную ошибку запроса (синтаксис, нарушение constraint и
     * т.п.) — такие стоит переподключением лечить молча, а не ронять
     * процесс. Оба текста — реальные формулировки libmysqlclient/mysqlnd,
     * подтверждённые в логе на бою.
     */
    public static function isConnectionLost(Throwable $e): bool
    {
        if (!$e instanceof PDOException) {
            return false;
        }

        $message = $e->getMessage();

        return str_contains($message, 'MySQL server has gone away')
            || str_contains($message, 'Lost connection to MySQL server');
    }

    private static function createConnection(): PDO
    {
        $host = Env::get('DB_HOST', '127.0.0.1');
        $port = Env::get('DB_PORT', '3306');
        $name = Env::required('DB_NAME');
        $user = Env::required('DB_USER');
        $pass = Env::get('DB_PASSWORD', '');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
