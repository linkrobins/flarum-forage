# Link Robins Forage

Hosted search for Flarum. Your posts are indexed on a search server of your own,
and your forum's search box asks that server instead of the database. Searches
come back faster, spelling mistakes still find things, and searching stops
competing with everything else your database is doing.

Flarum 2.x only.

## Setting it up

Install and enable the extension, then go to Admin, Extensions, Link Robins
Forage, paste your setup key and save. That is the whole configuration: the
endpoint, the keys and your plan's limit are fetched with the key, and your
forum starts indexing itself straight away.

A banner at the top of the settings page tells you where you stand:

- **Connected**, with a count of how many posts are indexed
- **Your search server is still being built**, which is normal for a minute or
  two after subscribing. Press *Check again* rather than retyping the key.
- **That key was not recognised**, which means the key is wrong or the
  subscription has lapsed
- **Cannot reach your search server**, which means everything is set up but the
  server is not answering right now

A Forage subscription is what the setup key comes from:
[linkrobins.com/forage](https://linkrobins.com/forage).

## What it does

**Search.** Anything typed into the forum's search box, and any API request with
`filter[q]`, is answered by your search server. Browsing and filtering are not:
Flarum only reaches for a search driver when there is a query, so tag pages,
sorting and everything else still run against your database exactly as before.

**Indexing.** Every post is kept in step automatically: written, edited, hidden,
restored, approved, deleted. Renaming a discussion re-indexes its posts, because
each post is indexed under its discussion's title. Hiding or deleting a
discussion takes its posts out of the index.

**Permissions.** Results are always filtered through Flarum's own visibility
rules before they are shown. The search server has no idea who is asking, so
everything it returns is treated as a suggestion and checked against what the
person searching is allowed to read. A member cannot see a private discussion,
a hidden post, or a post awaiting approval through search, whatever the search
server says. Posts that are not readable are not indexed in the first place.

**If the search server is unavailable**, searching falls back to the search
Flarum ships with. Your search box keeps working; it is simply less good until
the server is back. The same is true before you have entered a key at all.

## What it does NOT do

- It does not search users, tags, or anything but discussions and posts.
- It does not change how results are displayed. Ranking comes from the search
  server; the page is Flarum's.
- It does not send anything to the search server except the post text, its id,
  its discussion's id and title. No usernames, no email addresses, no IPs.
- It does not index posts that members cannot read.

## Rebuilding the index

The index is filled automatically when you first connect, and kept up to date
after that. Rebuild it by hand when you have imported posts straight into the
database, when your queue was not running for a while, or if you just want to be
sure:

```
php flarum forage:reindex
```

Add `--fresh` to empty the index first, which also clears out anything left over
from posts that no longer exist.

## Requirements

- Flarum **2.0** or later
- PHP **8.3+**
- An active [Forage](https://linkrobins.com/forage) subscription
- Outbound HTTPS from your forum's server
- **A queue worker is strongly recommended.** Indexing runs as queued work. With
  Flarum's default `sync` queue, that work happens during the web request that
  triggered it, so posting a reply waits on your search server. With a real
  queue driver it happens in the background, which is what you want.

## Installation

```sh
composer require linkrobins/flarum-forage
php flarum extension:enable linkrobins-forage
```

Installing does not enable an extension. The second line is the on switch;
Extension Manager has a toggle that does the same thing.

## Updating

```sh
composer update linkrobins/flarum-forage
php flarum cache:clear
```

## A note on how this hooks into Flarum

Flarum 2.0 lets an extension register a whole new search driver, and that is the
obvious-looking way to build this. It is not what happens here, on purpose.

Filters, sorts and mutators are registered against a specific searcher class, so
a driver that brings its own searchers silently loses every one of them that
core and other extensions added. On a forum with Tags, `filter[tag]` would stop
applying to searches, and the mutator that keeps restricted tags off the
all-discussions list would stop running. So Forage replaces just the part that
finds matches, and leaves the rest of Flarum's search pipeline in place. Every
filter, sort and mutator any other extension has added keeps working.

Indexing does use Flarum's own indexing system, which is driver-independent by
design.

## Licence

MIT. See [LICENSE](LICENSE).
