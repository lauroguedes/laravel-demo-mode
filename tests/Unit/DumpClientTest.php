<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use LauroGuedes\DemoMode\Support\DumpClient;
use Pdo\Mysql;

/**
 * The whole security surface of shelling out, asserted directly.
 *
 * Worth testing here rather than only through an import that happens to work:
 * PostgreSQL has no server in the default test environment, and "it imported"
 * would not have told us the password stayed out of the argument list anyway.
 */
function clientFor(string $driver, array $config = []): ?DumpClient
{
    Config::set('database.connections.under-test', array_merge(['driver' => $driver], $config));

    return DumpClient::for(DB::connection('under-test'));
}

it('passes a database name a shell would mangle, unmangled', function (): void {
    $client = clientFor('mysql', ['database' => 'demo db; DROP DATABASE prod', 'username' => 'root']);

    expect($client->command)->toContain('demo db; DROP DATABASE prod');
});

/**
 * Arguments are world-readable in `ps` for the life of the process. Both clients
 * read these variables for exactly this reason.
 */
it('keeps the password out of the argument list', function (string $driver, string $variable): void {
    $client = clientFor($driver, ['database' => 'demo', 'username' => 'demo_user', 'password' => 'hunter2']);

    expect($client->environment)->toBe([$variable => 'hunter2'])
        ->and(implode(' ', $client->command))->not->toContain('hunter2');
})->with([
    ['mysql', 'MYSQL_PWD'],
    ['pgsql', 'PGPASSWORD'],
]);

it('sets no password variable when there is no password', function (): void {
    expect(clientFor('mysql', ['database' => 'demo', 'username' => 'root'])->environment)->toBe([]);
});

it('invokes the mysql client with the connection it was given', function (): void {
    $client = clientFor('mysql', ['host' => 'db.internal', 'port' => 3307, 'database' => 'demo', 'username' => 'demo_user']);

    expect($client->command)->toBe([
        'mysql',
        '--host=db.internal',
        '--port=3307',
        '--user=demo_user',
        'demo',
    ]);
});

/**
 * --set=ON_ERROR_STOP=1 is not optional: without it psql reports a failed
 * statement and still exits zero, so a half-loaded dump would look like success.
 */
it('invokes psql so that a failed statement fails the process', function (): void {
    $client = clientFor('pgsql', ['host' => 'db.internal', 'port' => 5433, 'database' => 'demo', 'username' => 'demo_user']);

    expect($client->command)->toBe([
        'psql',
        '--host=db.internal',
        '--port=5433',
        '--username=demo_user',
        '--dbname=demo',
        '--set=ON_ERROR_STOP=1',
        '--quiet',
    ]);
});

it('falls back to the conventional port when none is configured', function (string $driver, string $port): void {
    expect(clientFor($driver, ['database' => 'demo'])->command)->toContain($port);
})->with([
    ['mysql', '--port=3306'],
    ['pgsql', '--port=5432'],
]);

it('lets the configured client binary win', function (): void {
    Config::set('database.connections.under-test', ['driver' => 'mysql', 'database' => 'demo']);

    expect(DumpClient::for(DB::connection('under-test'), '/usr/local/bin/mariadb')->command[0])
        ->toBe('/usr/local/bin/mariadb');
});

it('has no client for a driver that needs none', function (): void {
    expect(clientFor('sqlite', ['database' => ':memory:']))->toBeNull();
});

it('has no client for a driver it does not know', function (): void {
    expect(clientFor('sqlsrv', ['database' => 'demo']))->toBeNull();
});

it('takes the socket route when the connection is configured for one', function (): void {
    $client = clientFor('mysql', ['unix_socket' => '/tmp/mysql.sock', 'database' => 'demo', 'username' => 'root']);

    expect($client->command)->toContain('--socket=/tmp/mysql.sock')
        ->and(implode(' ', $client->command))->not->toContain('--host=');
});

/**
 * A connection the application requires TLS for would otherwise send the
 * password and the whole baseline in the clear.
 */
it('carries the TLS settings the application connects with', function (): void {
    $client = clientFor('mysql', [
        'database' => 'demo',
        'options' => [Mysql::ATTR_SSL_CA => '/etc/ssl/ca.pem'],
    ]);

    expect($client->command)->toContain('--ssl-ca=/etc/ssl/ca.pem');
});

it('carries the PostgreSQL TLS settings through the environment', function (): void {
    $client = clientFor('pgsql', ['database' => 'demo', 'sslmode' => 'require', 'password' => 'hunter2']);

    expect($client->environment)->toBe(['PGPASSWORD' => 'hunter2', 'PGSSLMODE' => 'require']);
});
