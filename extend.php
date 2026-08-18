<?php

use Flarum\Api\Resource\ForumResource;
use Flarum\Discussion\Search\DiscussionSearcher;
use Flarum\Extend;
use Flarum\Post\CommentPost;
use Flarum\Post\Filter\PostSearcher;
use Flarum\Search\Database\DatabaseSearchDriver;
use Flarum\Settings\Event\Deserializing;
use Flarum\Settings\Event\Saved;
use Flarum\Settings\Event\Serializing;
use LinkRobins\Forage\Api\Controller\RelatedController;
use LinkRobins\Forage\Api\Controller\RetryController;
use LinkRobins\Forage\Api\Controller\StatusController;
use LinkRobins\Forage\Api\ForumFields;
use LinkRobins\Forage\Api\RelatedThrottler;
use LinkRobins\Forage\Console\ReindexCommand;
use LinkRobins\Forage\Listener\ExchangeSetupToken;
use LinkRobins\Forage\Listener\HideKeysFromAdmin;
use LinkRobins\Forage\Listener\NormalizeSwitches;
use LinkRobins\Forage\Listener\SyncIndex;
use LinkRobins\Forage\Search\DiscussionFulltextFilter;
use LinkRobins\Forage\Search\PostFulltextFilter;
use LinkRobins\Forage\Search\PostIndexer;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    /*
     * The forum bundle exists for related discussions and nothing else. Search
     * itself needs no frontend at all: it is answered by replacing a filter on
     * the driver, so Flarum's own search box keeps working unchanged.
     */
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less'),

    /*
     * Whether related discussions can be answered at all, so a forum with no
     * key does not ask on every discussion page for a list that cannot exist.
     */
    (new Extend\ApiResource(ForumResource::class))
        ->fields(ForumFields::class),

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

    /*
     * Flarum watches the model and hands changes to the indexer on a queue,
     * which covers written, edited, hidden, restored and approved without a
     * listener of our own.
     *
     * Registered against CommentPost, not Post. Every post row is really one of
     * Post's subclasses, and Eloquent fires model events under the concrete
     * class, so an indexer registered against Post is looked up under
     * CommentPost, found missing, and silently never runs. Registering the
     * parent class costs nothing and does nothing.
     *
     * Comments are also the only posts worth indexing: the rest are event
     * posts, which carry no text anybody searches for.
     */
    (new Extend\SearchIndex())
        ->indexer(CommentPost::class, PostIndexer::class),

    (new Extend\Event())
        ->subscribe(SyncIndex::class)
        ->listen(Saved::class, ExchangeSetupToken::class)
        ->listen(Deserializing::class, HideKeysFromAdmin::class)
        ->listen(Serializing::class, NormalizeSwitches::class),

    (new Extend\Routes('api'))
        ->get('/linkrobins-forage/status', 'linkrobins-forage.status', StatusController::class)
        ->post('/linkrobins-forage/retry', 'linkrobins-forage.retry', RetryController::class)
        ->get('/linkrobins-forage/related', 'linkrobins-forage.related', RelatedController::class),

    /*
     * Related discussions is the one route a member can drive at will, through
     * the composer. Everything else here is admin-only or runs on the queue.
     */
    (new Extend\ThrottleApi())
        ->set('linkrobins-forage.related', RelatedThrottler::class),

    (new Extend\Console())
        ->command(ReindexCommand::class),
];
