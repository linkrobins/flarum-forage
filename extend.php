<?php

use Flarum\Discussion\Search\DiscussionSearcher;
use Flarum\Extend;
use Flarum\Post\Filter\PostSearcher;
use Flarum\Post\Post;
use Flarum\Search\Database\DatabaseSearchDriver;
use Flarum\Settings\Event\Deserializing;
use Flarum\Settings\Event\Saved;
use LinkRobins\Forage\Api\Controller\RetryController;
use LinkRobins\Forage\Api\Controller\StatusController;
use LinkRobins\Forage\Console\ReindexCommand;
use LinkRobins\Forage\Listener\ExchangeSetupToken;
use LinkRobins\Forage\Listener\HideKeysFromAdmin;
use LinkRobins\Forage\Listener\SyncIndex;
use LinkRobins\Forage\Search\DiscussionFulltextFilter;
use LinkRobins\Forage\Search\PostFulltextFilter;
use LinkRobins\Forage\Search\PostIndexer;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    /*
     * Search is answered by the search server, by replacing the fulltext filter
     * on the driver Flarum already uses.
     *
     * Flarum also supports registering a whole new search driver, which is the
     * obvious-looking route and is the wrong one here. Filters, sorts and
     * mutators are registered against a specific searcher class, so a driver
     * with its own searchers silently loses every one of them that core and
     * other extensions added: on a forum with Tags, `filter[tag]` would stop
     * applying to searches, and the mutator that keeps restricted tags off the
     * all-discussions list would stop running. Replacing just the fulltext
     * filter changes how matches are found and leaves all of that intact.
     *
     * Only searches go out to the search server. Flarum uses the database
     * driver whenever there is no query, so browsing and filtering are
     * untouched.
     */
    (new Extend\SearchDriver(DatabaseSearchDriver::class))
        ->setFulltext(DiscussionSearcher::class, DiscussionFulltextFilter::class)
        ->setFulltext(PostSearcher::class, PostFulltextFilter::class),

    // Flarum watches the Post model and hands changes to the indexer on a
    // queue, which covers written, edited, hidden, restored, deleted and
    // approved without a listener of our own.
    (new Extend\SearchIndex())
        ->indexer(Post::class, PostIndexer::class),

    (new Extend\Event())
        ->subscribe(SyncIndex::class)
        ->listen(Saved::class, ExchangeSetupToken::class)
        ->listen(Deserializing::class, HideKeysFromAdmin::class),

    (new Extend\Routes('api'))
        ->get('/linkrobins-forage/status', 'linkrobins-forage.status', StatusController::class)
        ->post('/linkrobins-forage/retry', 'linkrobins-forage.retry', RetryController::class),

    (new Extend\Console())
        ->command(ReindexCommand::class),
];
