import app from 'flarum/forum/app';
import Link from 'flarum/common/components/Link';
import humanTime from 'flarum/common/helpers/humanTime';
import type { RelatedDiscussion } from './loadRelated';
import type Mithril from 'mithril';

/**
 * One discussion, as the footer and the modal both draw it.
 *
 * Shared so the two lists cannot drift: the same title, the same meta, the same
 * decision about when a reply count is worth showing.
 */
export default function relatedRow(discussion: RelatedDiscussion): Mithril.Children {
  return m(
    'li',
    { className: 'ForageRelated-item' },
    m(Link, { className: 'ForageRelated-link', href: app.route('discussion', { id: discussion.id + '-' + discussion.slug }) }, discussion.title),
    m('span', { className: 'ForageRelated-meta' }, meta(discussion))
  );
}

/**
 * A reply count only when there are replies: most threads on a quiet forum have
 * none, and a list recommending them should not open by saying so. When it was
 * last posted in is worth showing either way, so it always is.
 */
function meta(discussion: RelatedDiscussion): Mithril.Children {
  const parts: Mithril.Children[] = [];

  // Core counts the opening post in commentCount and shows one fewer as the
  // reply count; match it rather than invent a number.
  const replies = Math.max(0, discussion.commentCount - 1);

  if (replies > 0) {
    parts.push(app.translator.trans('linkrobins-forage.forum.related_replies', { count: replies }));
  }

  if (discussion.lastPostedAt) {
    parts.push(humanTime(new Date(discussion.lastPostedAt)));
  }

  return parts.flatMap((part, i) => (i === 0 ? [part] : [m('span', { className: 'ForageRelated-dot' }, '·'), part]));
}
