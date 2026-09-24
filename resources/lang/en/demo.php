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

    /*
     | The floating bar. Its sentence is the banner's; these are the labels
     | on the controls only it has.
     */
    'bar' => [
        /*
         | Shorter than the banner's sentence, because the bar is a pill and a
         | pill full of prose is a pill the width of the screen.
         */
        'with_countdown' => 'Resets in :time',
        'without_countdown' => 'Resets periodically',

        'reset' => 'Rebuild the demonstration',
        'confirm' => 'Rebuild the demonstration?',
        'confirm_yes' => 'Rebuild',
        'cancel' => 'Cancel',
        'working' => 'Rebuilding the demonstration…',
        /*
         | What the button says when it clears one visitor's sandbox rather than
         | rebuilding the installation. Two different promises, so two sets of
         | words rather than one hedged between them.
         */
        'reset_sandbox' => 'Clear what you created',
        'confirm_sandbox' => 'Clear everything you created?',
        'confirm_yes_sandbox' => 'Clear',
        'working_sandbox' => 'Clearing…',

        'rebuilding' => 'Rebuilding the demonstration…',
        'failed' => 'That did not work. Try again in a moment.',
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
        'sandbox_cleared' => 'Everything you created has been removed.',
        'done' => 'The demonstration has been rebuilt.',
    ],

    'errors' => [
        'read_only' => 'This demonstration is read-only.',
        'write_prohibited' => 'That cannot be changed in the demonstration.',
        'privileged_account' => 'Signing in as this account is disabled in the demonstration.',
        'cooldown' => 'This demonstration was reset recently. Try again later.',
        'in_progress' => 'This demonstration is already being rebuilt. Give it a moment, then reload.',
        'unavailable' => 'This demonstration cannot be rebuilt right now.',
        'not_found' => 'Not found.',
    ],

];
