<?php

declare(strict_types=1);

use LauroGuedes\DemoMode\Cleaners;
use LauroGuedes\DemoMode\Reset\Strategies;
use LauroGuedes\DemoMode\Restrictions;

return [

    /*
    |--------------------------------------------------------------------------
    | Demo mode
    |--------------------------------------------------------------------------
    |
    | Whether this installation declared itself a public demonstration. With
    | this off the package registers nothing: no middleware, no listener, no
    | route, no schedule, and no destructive command. The cost of having it
    | installed and switched off is one boolean.
    |
    */

    'enabled' => (bool) env('DEMO_MODE', false),

    /*
    |--------------------------------------------------------------------------
    | Where a reset is allowed to happen
    |--------------------------------------------------------------------------
    |
    | A guard independent of the flag above. Even with DEMO_MODE=true the reset
    | refuses to run outside this list, so a demo .env copied onto the wrong
    | server does nothing on its own. Adding 'production' here is possible and
    | is the only way past the production check — which is the point: it has to
    | be typed by a person who meant it.
    |
    */

    'environments' => ['local', 'staging', 'demo'],

    /*
    |--------------------------------------------------------------------------
    | Allowed hosts
    |--------------------------------------------------------------------------
    |
    | An allowlist for the host in APP_URL. Null disables the check. Set it on
    | anything public: it is the guard that survives an .env being copied to a
    | server whose APP_ENV happens to match the list above.
    |
    */

    'allowed_hosts' => null, // ['demo.example.com']

    /*
    |--------------------------------------------------------------------------
    | Reset
    |--------------------------------------------------------------------------
    |
    | The schedule accepts a raw cron expression or one of the shortcuts
    | 'hourly', 'daily', 'weekly' and 'every-N-hours'. Whatever it says is also
    | what Demo::nextResetAt() reads, so the banner's countdown and the
    | scheduler can never disagree.
    |
    */

    'reset' => [

        'strategy' => env('DEMO_RESET_STRATEGY', 'migrate-fresh-seed'),

        'schedule' => env('DEMO_RESET_SCHEDULE', '0 */6 * * *'),

        'maintenance' => (bool) env('DEMO_RESET_MAINTENANCE', true),

        'lock_ttl' => 1800,

        'connection' => null,

        /*
         | Each strategy has its own block, so 'seeder' belongs to
         | migrate-fresh-seed and does not have to be prefixed to stay out of
         | another strategy's way. Switching is then one env var.
         */
        'strategies' => [

            'migrate-fresh-seed' => [
                'driver' => Strategies\MigrateFreshSeed::class,
                'seeder' => 'Database\Seeders\DemoSeeder',
                'drop_views' => true,
            ],

        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cleaners
    |--------------------------------------------------------------------------
    |
    | Resetting the database is not resetting the application. A surviving
    | session leaves a visitor signed in as a user who no longer exists, and a
    | cached settings blob leaves the server wearing whatever the last visitor
    | configured. These run after the strategy, in the order written.
    |
    | FlushCache keeps this package's own keys on purpose: the cache credential
    | store lives behind one of them, and rotating a password into a store that
    | the next step empties would publish a password nobody can use.
    |
    */

    'cleaners' => [

        Cleaners\FlushSessions::class => ['driver' => null],

        Cleaners\FlushCache::class => ['tags' => [], 'except' => ['demo-mode:*']],

        Cleaners\FlushStorage::class => ['disks' => []],

        Cleaners\FlushQueue::class => ['queues' => []],

    ],

    /*
    |--------------------------------------------------------------------------
    | Published credentials
    |--------------------------------------------------------------------------
    |
    | A demo is only a demo if a stranger can get in, so one password is
    | deliberately recoverable and shown on the sign-in page. Two things keep
    | that bounded: nothing is read back unless this installation still says it
    | is a demo, and a rotating password stops being a fact of the internet the
    | next time the scheduler runs.
    |
    | The file store must resolve to a disk that is not web-reachable.
    | 'demo:doctor' fails when it does not.
    |
    */

    'credentials' => [

        'enabled' => true,

        'store' => env('DEMO_CREDENTIALS_STORE', 'file'),

        /*
         | Whether Demo::toArray() carries the password. Leave it on for the
         | usual case of prefilling a login form; turn it off if that payload
         | is serialised into every page of a server-rendered app you would
         | rather not have a crawler read.
         */
        'expose_in_payload' => true,

        'accounts' => [
            [
                'email' => env('DEMO_EMAIL', 'admin@demo.test'),
                'label' => 'Administrator',
                'rotate' => true,
                'primary' => true,
                'password' => null,
            ],
        ],

        'password' => [
            'length' => 16,
            'symbols' => false, // a visitor retyping it may be on another keyboard layout
            'numbers' => true,
        ],

        'stores' => [
            'file' => ['disk' => 'local', 'path' => 'demo-credentials.json'],
            'cache' => ['store' => null, 'key' => 'demo-mode:credentials', 'ttl' => null],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Restrictions
    |--------------------------------------------------------------------------
    |
    | Applied during boot, only while the flag is on. Everything the package
    | ships is listed here so that removing one is a visible edit rather than
    | an absent default.
    |
    */

    'restrictions' => [

        Restrictions\DisableMail::class => ['transport' => 'array'],

        Restrictions\DisableNotifications::class => ['channels' => ['vonage', 'nexmo', 'slack']],

        Restrictions\ForceConfig::class => ['pin' => []],

        Restrictions\BlockPrivilegedAccounts::class => [
            'roles' => [],
            'emails' => [],
            'message' => null,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Write guards
    |--------------------------------------------------------------------------
    |
    | Records a visitor may not change. This is the emptiest default here that
    | you should probably fill in: if a visitor can change the published
    | account's email or password, the next visitor cannot get in, and the demo
    | is closed until the following reset.
    |
    | Resolved as an array of attributes to match, or a Closure(Model): bool.
    |
    */

    'guards' => [

        'protected' => [
            // \App\Models\User::class => ['email' => 'admin@demo.test'],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Banner
    |--------------------------------------------------------------------------
    */

    'banner' => [
        'enabled' => true,
        'variant' => 'warning',
        'dismissible' => true,
        'message' => null, // null uses the translation, with a live countdown
        'position' => 'top',
        'classes' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-visitor isolation
    |--------------------------------------------------------------------------
    |
    | 'shared' is the default and the only one with no cost: everyone sees the
    | same data.
    |
    */

    'sandbox' => [
        'driver' => env('DEMO_SANDBOX', 'shared'),
    ],

];
