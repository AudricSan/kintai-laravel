<?php

namespace App\Actions\Updates;

use App\Activity\ActivityLogger;
use App\Models\User;
use App\Settings\AppSettings;
use App\Update\UpdateChannel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Change le canal de mise à jour suivi (Owner seulement) et le consigne.
 */
class ChangeUpdateChannel
{
    public function __construct(
        private readonly AppSettings $settings,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, UpdateChannel $channel): void
    {
        if (! Gate::forUser($actor)->allows('manage-settings')) {
            throw new AuthorizationException;
        }

        $before = $this->settings->updateChannel();

        if ($before === $channel) {
            return;
        }

        $this->settings->set('update.channel', $channel->value);
        $this->logger->record('update.channel_changed', null, null, ['from' => $before->value, 'to' => $channel->value], $actor);
    }
}
