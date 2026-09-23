<?php

declare(strict_types=1);

return [

    'banner' => [
        'with_countdown' => 'This is a demonstration. Everything you change here is deleted in :time.',
        'without_countdown' => 'This is a demonstration. Everything you change here is deleted periodically.',
        'dismiss' => 'Dismiss',

        /*
         | Short units for the ticking countdown, which replaces the sentence's
         | rendered duration a second after the page paints.
         */
        'units' => ['hour' => 'h', 'minute' => 'm', 'second' => 's'],
    ],

    'credentials' => [
        'heading' => 'Sign in with',
        'email' => 'Email',
        'password' => 'Password',
        'copy' => 'Copy',
        'copied' => 'Copied',
    ],

    'reset' => [
        'queued' => 'The demonstration is being rebuilt. Give it a moment, then reload.',
        'done' => 'The demonstration has been rebuilt.',
    ],

    'errors' => [
        'read_only' => 'This demonstration is read-only.',
        'write_prohibited' => 'That cannot be changed in the demonstration.',
        'privileged_account' => 'Signing in as this account is disabled in the demonstration.',
        'cooldown' => 'This demonstration was reset recently. Try again in :time.',
        'in_progress' => 'This demonstration is already being rebuilt. Give it a moment, then reload.',
        'unavailable' => 'This demonstration cannot be rebuilt right now.',
        'not_found' => 'Not found.',
    ],

];
