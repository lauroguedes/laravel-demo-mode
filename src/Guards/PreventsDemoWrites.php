<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Guards;

use Illuminate\Database\Eloquent\Model;
use LauroGuedes\DemoMode\Configuration;

/**
 * Declare the protection on the model instead of in the config.
 *
 * Same guard, same matcher, same exception — only the place it is written down
 * differs. Some projects would rather a model carry its own rules than have them
 * listed somewhere else.
 *
 * The model still needs an entry in demo.guards.protected to say *which* of its
 * records are protected. Without one, matches() answers false and the trait does
 * nothing, which is the safe way round: a trait that guessed would be a trait
 * that froze somebody's whole users table.
 *
 * The events are registered one by one rather than with observe(), which
 * instantiates the model — and doing that from inside boot() is a recursive
 * bootIfNotBooted() and a LogicException.
 */
trait PreventsDemoWrites
{
    public static function bootPreventsDemoWrites(): void
    {
        if (! app(Configuration::class)->enabled()) {
            return;
        }

        foreach (['saving', 'deleting', 'restoring', 'forceDeleting'] as $event) {
            static::registerModelEvent($event, static function (Model $model) use ($event): void {
                app(ModelGuard::class)->{$event}($model);
            });
        }
    }
}
