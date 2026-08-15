<?php

namespace LinkRobins\Forage\Console;

use Flarum\Console\AbstractCommand;
use LinkRobins\Forage\ForageClient;
use LinkRobins\Forage\Indexer;
use LinkRobins\Forage\Settings;
use Symfony\Component\Console\Input\InputOption;

/**
 * php flarum forage:reindex
 *
 * The escape hatch for everything the event listeners cannot see: posts
 * imported straight into the database, a spell where the queue was not running,
 * or an index that simply needs rebuilding.
 */
class ReindexCommand extends AbstractCommand
{
    public function __construct(
        protected Settings $settings,
        protected Indexer $indexer,
        protected ForageClient $client
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('forage:reindex')
            ->setDescription('Rebuild the Forage search index from this forum\'s posts')
            ->addOption('fresh', null, InputOption::VALUE_NONE, 'Empty the index first, dropping anything left over from posts that no longer exist');
    }

    protected function fire(): int
    {
        if (! $this->settings->isConfigured()) {
            $this->error('Forage is not set up yet. Paste your setup key on the extension\'s settings page first.');

            return 1;
        }

        if (! $this->client->isReachable()) {
            $this->error('Cannot reach the search server at '.$this->settings->endpoint().'.');

            return 1;
        }

        $this->info('Indexing posts into '.$this->settings->endpoint().'...');

        $result = $this->indexer->reindex(
            (bool) $this->input->getOption('fresh'),
            function (int $indexed, int $skipped): void {
                $this->output->write("\r  ".$indexed.' indexed, '.$skipped.' skipped');
            }
        );

        $this->output->writeln('');
        $this->info('Done: '.$result['indexed'].' posts indexed, '.$result['skipped'].' skipped.');

        if ($result['capped']) {
            $this->error('Stopped at your plan\'s limit of '.$this->settings->postCap().' posts. The rest of the forum is not searchable through Forage.');
        }

        return 0;
    }
}
