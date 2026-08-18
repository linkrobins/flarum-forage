import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Link from 'flarum/common/components/Link';
import humanTime from 'flarum/common/helpers/humanTime';
import loadRelated from '../loadRelated';
import type { RelatedDiscussion } from '../loadRelated';
import type Mithril from 'mithril';

export interface RelatedPanelAttrs {
  discussionId: number;
  /** The title being read, which is also what "more like this" searches for. */
  title: string;
}

/**
 * The "related discussions" list under a discussion.
 *
 * Renders nothing at all until it has something to show — no heading, no empty
 * state, no skeleton. A thread with no relatives is the normal case on a small
 * forum, and an empty box under every one of them would read as a fault.
 */
export default class RelatedPanel extends Component<RelatedPanelAttrs> {
  discussions: RelatedDiscussion[] = [];

  /**
   * The discussion the current list belongs to.
   *
   * Flarum reuses one DiscussionPage across navigations, so the component is
   * updated rather than recreated and oninit fires once for many discussions.
   * Without this the panel would keep showing the previous thread's relatives.
   */
  loadedFor: number | null = null;

  oninit(vnode: Mithril.Vnode<RelatedPanelAttrs, this>) {
    super.oninit(vnode);

    this.load(vnode.attrs.discussionId);
  }

  onbeforeupdate(vnode: Mithril.VnodeDOM<RelatedPanelAttrs, this>) {
    super.onbeforeupdate(vnode);

    if (vnode.attrs.discussionId !== this.loadedFor) {
      this.load(vnode.attrs.discussionId);
    }

    return true;
  }

  view() {
    if (!this.discussions.length) {
      return null;
    }

    return m(
      'section',
      { className: 'ForageRelated' },
      m('h3', { className: 'ForageRelated-heading' }, app.translator.trans('linkrobins-forage.forum.related_heading')),
      m(
        'ul',
        { className: 'ForageRelated-list' },
        this.discussions.map((discussion) =>
          m(
            'li',
            { className: 'ForageRelated-item' },
            m(
              Link,
              { className: 'ForageRelated-link', href: app.route('discussion', { id: discussion.id + '-' + discussion.slug }) },
              discussion.title
            ),
            m('span', { className: 'ForageRelated-meta' }, this.meta(discussion))
          )
        )
      ),
      this.more()
    );
  }

  /**
   * What each row says about itself.
   *
   * A reply count only when there are replies: most threads on a quiet forum
   * have none, and a list recommending them should not open by saying so. When
   * it was last posted in is worth showing either way, so it always is.
   */
  meta(discussion: RelatedDiscussion): Mithril.Children {
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

  /** The forum's own search, for the rest of what this panel had to cut. */
  more(): Mithril.Children {
    return m(
      Link,
      { className: 'ForageRelated-more', href: app.route('index', { q: this.attrs.title }) },
      app.translator.trans('linkrobins-forage.forum.related_more')
    );
  }

  load(discussionId: number) {
    // Set before the request, not after: an update mid-flight would otherwise
    // start the same load again.
    this.loadedFor = discussionId;
    this.discussions = [];

    if (!discussionId) {
      return;
    }

    loadRelated({ discussion: discussionId }).then((discussions) => {
      // A member who navigated on while this was in the air gets the answer for
      // the thread they left; drop it rather than render it under the new one.
      if (this.loadedFor !== discussionId) {
        return;
      }

      this.discussions = discussions;
      m.redraw();
    });
  }
}
