<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Support;

use Illuminate\Database\Connection;
use Pdo\Mysql;

/**
 * How to invoke a database client, as an argument array and an environment.
 *
 * Separated from the strategy that uses it because these two values are the
 * whole security surface of shelling out, and they are worth asserting directly
 * rather than inferring from whether an import happened to work.
 *
 * The command is a list, never a string. Nothing is interpolated into a shell:
 * not the database name, not the host, not the path. These come from config
 * rather than from a request, so this is mostly defence against the ordinary
 * accident — a database name with a space in it — but a package that drops
 * tables should not be the one assembling shell strings.
 *
 * The password goes in the environment rather than in an argument, because
 * arguments are world-readable in 'ps' for the life of the process. That is the
 * same reason both clients read MYSQL_PWD and PGPASSWORD at all.
 *
 * The framework has its own version of this — Illuminate\Database\Schema\
 * SchemaState, which SqlDump does use for SQLite. It is not used for MySQL or
 * PostgreSQL, and the reason is worth recording so that nobody has to work it
 * out again: SchemaState builds a shell command string with "${:VAR}"
 * placeholders, and Symfony substitutes the real values into that string before
 * handing it to sh -c. The password therefore appears in the command line of the
 * shell process, which is what 'ps' shows. It also invokes psql without
 * ON_ERROR_STOP, so a dump that fails halfway exits zero.
 *
 * What this gives up by not using it: unix sockets, the PDO SSL options, and
 * MySQL-versus-MariaDB client detection. A demo on a socket or a managed
 * database that requires TLS needs one of those, and the honest fix then is to
 * add it here rather than to switch back.
 */
final readonly class DumpClient
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    private function __construct(
        public array $command,
        public array $environment,
    ) {}

    /**
     * Null when the driver has no external client worth depending on, which
     * today means sqlite — small enough that the driver executes the dump itself
     * — and any driver this package has not been taught.
     */
    public static function for(Connection $connection, ?string $binary = null): ?self
    {
        $config = $connection->getConfig();

        return match ($connection->getDriverName()) {
            'mysql', 'mariadb' => new self(self::command([
                $binary ?? 'mysql',
                ...self::mysqlRoute($config),
                ...self::mysqlTls($config),
                self::prefixed('--user=', $config, 'username'),
                self::value($config, 'database', ''),
            ]), self::password($config, 'MYSQL_PWD')),

            'pgsql' => new self(self::command([
                $binary ?? 'psql',
                '--host='.self::value($config, 'host', '127.0.0.1'),
                '--port='.self::value($config, 'port', '5432'),
                self::prefixed('--username=', $config, 'username'),
                '--dbname='.self::value($config, 'database', ''),
                /* Without this psql reports a failed statement and exits zero. */
                '--set=ON_ERROR_STOP=1',
                '--quiet',
            ]), self::password($config, 'PGPASSWORD') + self::postgresTls($config)),

            default => null,
        };
    }

    /**
     * A unix socket is a different server from 127.0.0.1, and an application
     * configured for one that silently got the other is the kind of accident
     * this class exists to avoid — in the worst case it means loading the
     * baseline into something that is not the demo.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private static function mysqlRoute(array $config): array
    {
        $socket = self::value($config, 'unix_socket', '');

        return $socket === ''
            ? ['--host='.self::value($config, 'host', '127.0.0.1'), '--port='.self::value($config, 'port', '3306')]
            : ['--socket='.$socket];
    }

    /**
     * Carried across because a connection the application requires TLS for would
     * otherwise send the password and the whole baseline in the clear.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private static function mysqlTls(array $config): array
    {
        $options = is_array($config['options'] ?? null) ? $config['options'] : [];

        $arguments = [];

        foreach ([Mysql::ATTR_SSL_CA => '--ssl-ca=', Mysql::ATTR_SSL_CERT => '--ssl-cert=', Mysql::ATTR_SSL_KEY => '--ssl-key='] as $attribute => $flag) {
            $value = Options::scalarString($options[$attribute] ?? null, '');

            if ($value !== '') {
                $arguments[] = $flag.$value;
            }
        }

        return $arguments;
    }

    /**
     * psql takes these through the environment, which is also where its password
     * already goes.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    private static function postgresTls(array $config): array
    {
        $environment = [];

        $mode = self::value($config, 'sslmode', '');

        if ($mode !== '') {
            $environment['PGSSLMODE'] = $mode;
        }

        $root = self::value($config, 'sslrootcert', '');

        if ($root !== '') {
            $environment['PGSSLROOTCERT'] = $root;
        }

        return $environment;
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    private static function command(array $arguments): array
    {
        return array_values(array_filter($arguments, static fn (string $argument): bool => $argument !== ''));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function value(array $config, string $key, string $default): string
    {
        return Options::scalarString($config[$key] ?? null, $default);
    }

    /**
     * A flag that disappears entirely when there is nothing to put after it,
     * rather than being passed empty — '--user=' with no name is not the same
     * request as omitting it.
     *
     * @param  array<string, mixed>  $config
     */
    private static function prefixed(string $flag, array $config, string $key): string
    {
        $value = self::value($config, $key, '');

        return $value === '' ? '' : $flag.$value;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    private static function password(array $config, string $variable): array
    {
        $password = $config['password'] ?? null;

        return is_string($password) && $password !== '' ? [$variable => $password] : [];
    }
}
