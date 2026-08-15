<?php

namespace LinkRobins\Forage\Job;

use Flarum\Queue\AbstractJob;
use LinkRobins\Forage\Indexer;
use Psr\Log\LoggerInterface;

/**
 * Fill the index from scratch.
 *
 * Queued when a forum first connects, so the admin who just pasted their token
 * gets a working search without having to open a terminal.
 */
class ReindexAll extends AbstractJob
{
    public function handle(Indexer $indexer, LoggerInterface $log): void
    {
        $result = $indexer->reindex();

        $log->info('[linkrobins/forage] indexed '.$result['indexed'].' posts'.($result['capped'] ? ' (stopped at the plan limit)' : ''));
    }
}
