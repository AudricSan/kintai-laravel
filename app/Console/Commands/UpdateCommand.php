<?php

namespace App\Console\Commands;

use App\Activity\ActivityLogger;
use App\Settings\AppSettings;
use App\Update\FinishProcess;
use App\Update\UpdateApplier;
use App\Update\UpdateChannel;
use App\Update\UpdateChecker;
use App\Update\UpdateException;
use App\Update\UpdateState;
use Illuminate\Console\Command;

/**
 * Met à jour le Core depuis les releases GitHub, depuis le terminal (aucun mot de passe : l'accès au terminal est un accès au
 * serveur). Le journal d'audit n'a pas d'acteur (système).
 *
 * Sans option : recherche, confirmation, préparation, application puis `--finish` dans un NOUVEAU processus, car seul un
 * processus neuf exécute le code posé par l'application. Après `apply()`, ce processus-ci ne fait donc plus que relayer la
 * sortie : tout ce dont il a besoin est préparé avant.
 */
class UpdateCommand extends Command
{
    protected $signature = 'kintai:update
                            {--check : Show the installed version and the latest available one, without updating}
                            {--channel= : Update channel for this run only (release, beta or alpha)}
                            {--yes : Do not ask for confirmation}
                            {--rollback : Roll back an unfinished update}
                            {--finish : Finish an applied update (migrations); run by the update itself in a new process}';

    protected $description = 'Update Kintai from the GitHub releases';

    public function handle(UpdateChecker $checker, UpdateApplier $applier, AppSettings $settings, ActivityLogger $logger, FinishProcess $process): int
    {
        try {
            if ($this->option('rollback')) {
                return $this->rollback($applier, $logger);
            }

            if ($this->option('finish')) {
                return $this->finish($applier, $logger);
            }

            $channel = $this->channel($settings);

            if ($channel === null) {
                $this->components->error(__('Unknown channel. Use release, beta or alpha.'));

                return self::FAILURE;
            }

            $installed = (string) config('kintai.version');
            $this->line(__('Installed version: :version', ['version' => $installed]));
            $this->line(__('Update channel: :channel', ['channel' => $channel->value]));

            $release = $checker->check($channel);
            $newer = $checker->isNewer($release);

            if ($release !== null) {
                $this->line(__('Latest version: :version', ['version' => (string) $release->version]));
            }

            if (! $newer) {
                $this->components->info(__('Kintai is up to date.'));

                return self::SUCCESS;
            }

            if ($this->option('check')) {
                $this->components->info(__('Version :version is available.', ['version' => (string) $release->version]));

                return self::SUCCESS;
            }

            if (! $this->option('yes') && ! $this->confirm(__('Update Kintai from :from to :to?', ['from' => $installed, 'to' => (string) $release->version]), false)) {
                $this->components->warn(__('Update cancelled.'));

                return self::SUCCESS;
            }

            $staged = $applier->stage($release);
            $this->line(__('Update prepared: :count files.', ['count' => $staged['files']]));

            return $this->apply($applier, $logger, $process, $staged['from'], $staged['to']);
        } catch (UpdateException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function channel(AppSettings $settings): ?UpdateChannel
    {
        $option = $this->option('channel');

        return $option === null || $option === '' ? $settings->updateChannel() : UpdateChannel::tryFrom((string) $option);
    }

    /**
     * @throws UpdateException
     */
    private function apply(UpdateApplier $applier, ActivityLogger $logger, FinishProcess $process, string $from, string $to): int
    {
        // Tout ce qui sert après apply() est préparé ici : ensuite le disque porte le code neuf (voir la note de la classe).
        $process->prepare();
        $manual = __('Finish the update by hand with: :command', ['command' => $process->manualCommand()]);
        $applying = __('Applying the update...');
        $failed = __('The update could not be finished.');
        $done = __('The update was applied.');

        $this->line($applying);
        $logger->record('update.applied', null, null, ['from' => $from, 'to' => $to]);

        try {
            $applier->apply();
        } catch (UpdateException $e) {
            $logger->record('update.rolled_back', null, null, ['from' => $from, 'to' => $to, 'reason' => $e->getMessage()]);

            throw $e;
        }

        $code = $process->run(function (string $buffer): void {
            $this->output->write($buffer);
        });

        if ($code === null) {
            $this->line($manual);

            return self::FAILURE;
        }

        if ($code !== 0) {
            $this->line($failed);

            return self::FAILURE;
        }

        $this->line($done);

        return self::SUCCESS;
    }

    /**
     * @throws UpdateException
     */
    private function finish(UpdateApplier $applier, ActivityLogger $logger): int
    {
        $state = $applier->state();

        try {
            $result = $applier->finish();
        } catch (UpdateException $e) {
            $logger->record('update.rolled_back', null, null, ['from' => $state->from, 'to' => $state->to, 'reason' => $e->getMessage()]);

            throw $e;
        }

        if ($result['migrations_output'] !== '') {
            $this->line($result['migrations_output']);
        }

        $this->components->info(__('Kintai was updated from :from to :to.', ['from' => $result['from'], 'to' => $result['to']]));

        return self::SUCCESS;
    }

    /**
     * @throws UpdateException
     */
    private function rollback(UpdateApplier $applier, ActivityLogger $logger): int
    {
        $state = $applier->state();
        $applier->rollback();
        $after = $applier->state();

        $logger->record('update.rolled_back', null, null, ['from' => $state->from, 'to' => $state->to, 'complete' => $after->phase === UpdateState::ROLLED_BACK]);

        if ($after->phase !== UpdateState::ROLLED_BACK) {
            foreach ($after->error as $message) {
                $this->line($message);
            }

            $this->components->error(__('The rollback is incomplete. See the update status.'));

            return self::FAILURE;
        }

        $this->components->info(__('The update was rolled back.'));

        return self::SUCCESS;
    }
}
